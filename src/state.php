<?php

declare(strict_types=1);

namespace GalahadXVI\OctaneGuard;

use JsonException;
use RuntimeException;

final class State
{
    private const LOCK_MARKER = "octane-guard-lock-v1\n";

    /** @var resource|null */
    private $lock = null;

    private string $directory;

    private string $application_path;

    private string $boot_id;

    /**
     * Require private persistent storage outside the deployed application.
     */
    public function __construct(string $directory, string $application_path, string $boot_id)
    {
        $directory = rtrim($directory, '/');
        $application_path = rtrim($application_path, '/');

        if (!$this->isNormalizedAbsolutePath($directory) || !$this->isNormalizedAbsolutePath($application_path))
            throw new RuntimeException('Guard paths must be normalized absolute paths.');

        if (!$this->isBootId($boot_id))
            throw new RuntimeException('Guard boot identity must be a UUID.');

        $directory_stat = @lstat($directory);
        $resolved_application = realpath($application_path);

        if ($directory_stat === false || realpath($directory) !== $directory || !is_dir($directory) || !is_dir($application_path) || $resolved_application === false)
            throw new RuntimeException('Guard storage and application directories must exist.');

        if ($directory_stat['uid'] !== posix_geteuid() || ($directory_stat['mode'] & 0077) !== 0)
            throw new RuntimeException('Guard storage must be private and owned by the current user.');

        if ($directory === $resolved_application || str_starts_with($directory, $resolved_application.'/') || $directory === $application_path || str_starts_with($directory, $application_path.'/'))
            throw new RuntimeException('Guard storage must be outside the deployed application.');

        $this->directory = $directory;
        $this->application_path = $application_path;
        $this->boot_id = $boot_id;
    }

    /**
     * Acquire one stable lock, initializing state only alongside a new lock.
     */
    public function acquire(): void
    {
        if (is_resource($this->lock))
            throw new RuntimeException('Guard storage is already locked by this instance.');

        $lock_path = $this->directory.'/guard.lock';
        $lock = $this->pathExists($lock_path) ? false : @fopen($lock_path, 'x+b');
        $new_lock = is_resource($lock);

        if (!$new_lock) {
            $this->assertPrivatePath($lock_path);
            $lock = @fopen($lock_path, 'r+b');
        }

        if (!is_resource($lock))
            throw new RuntimeException('Guard lock cannot be opened.');

        if ($new_lock && !@chmod($lock_path, 0600)) {
            fclose($lock);
            throw new RuntimeException('Guard lock permissions cannot be set.');
        }

        try {
            $this->assertPrivateFile($lock_path, $lock);

            if (!flock($lock, LOCK_EX | LOCK_NB))
                throw new RuntimeException('Another guard owns this application lock.');

            $this->lock = $lock;
            $state_exists = $this->pathExists($this->directory.'/state.json');

            if ($new_lock === $state_exists)
                throw new RuntimeException('Guard lock and durable state history are inconsistent.');

            if ($new_lock) {
                $this->save(['version' => 1, 'application_path' => $this->application_path, 'boot_id' => $this->boot_id, 'launches' => 0, 'pgid' => null]);

                if (fwrite($lock, self::LOCK_MARKER) !== strlen(self::LOCK_MARKER) || !fflush($lock) || !fsync($lock))
                    throw new RuntimeException('Guard lock history cannot be committed.');
            } else {
                if (stream_get_contents($lock, 128) !== self::LOCK_MARKER)
                    throw new RuntimeException('Guard lock initialization is incomplete.');

                $state = $this->read();

                if ($state['boot_id'] !== $this->boot_id) {
                    $state['boot_id'] = $this->boot_id;
                    $state['pgid'] = null;
                    $this->save($state);
                }
            }
        } catch (\Throwable $exception) {
            fclose($lock);
            $this->lock = null;
            throw $exception;
        }
    }

    /**
     * Read validated state without reconstructing missing or corrupt history.
     *
     * @return array{version: int, application_path: string, boot_id: string, launches: int, pgid: ?int}
     */
    public function read(): array
    {
        $this->assertLocked();
        $state_path = $this->directory.'/state.json';
        $this->assertPrivatePath($state_path);
        $stream = @fopen($state_path, 'rb');

        if (!is_resource($stream))
            throw new RuntimeException('Guard state cannot be read.');

        try {
            $this->assertPrivateFile($state_path, $stream);
            $contents = stream_get_contents($stream, 4097);

            if ($contents === false || strlen($contents) > 4096)
                throw new RuntimeException('Guard state is invalid.');

            try {
                $state = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException('Guard state is invalid.');
            }

            if (!is_array($state))
                throw new RuntimeException('Guard state is invalid.');

            $this->validate($state);

            return $state;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Commit state before allowing a launch or a subsequent automatic restart.
     *
     * @param array{version: int, application_path: string, boot_id: string, launches: int, pgid: ?int} $state
     */
    public function save(array $state): void
    {
        $this->assertLocked();
        $this->validate($state);

        if ($state['boot_id'] !== $this->boot_id)
            throw new RuntimeException('Guard cannot write state for another boot.');

        $temporary_path = $this->directory.'/state-'.bin2hex(random_bytes(16)).'.tmp';
        $stream = @fopen($temporary_path, 'x+b');

        if (!is_resource($stream))
            throw new RuntimeException('Guard state cannot be written.');

        try {
            if (!@chmod($temporary_path, 0600))
                throw new RuntimeException('Guard state permissions cannot be set.');

            $contents = json_encode($state, JSON_THROW_ON_ERROR)."\n";
            $length = strlen($contents);
            $written = 0;

            while ($written < $length) {
                $result = fwrite($stream, substr($contents, $written));

                if ($result === false || $result === 0)
                    throw new RuntimeException('Guard state cannot be written.');

                $written += $result;
            }

            if (!fflush($stream) || !fsync($stream) || !@rename($temporary_path, $this->directory.'/state.json'))
                throw new RuntimeException('Guard state cannot be committed.');

            $directory_stream = @fopen($this->directory, 'r');

            if (!is_resource($directory_stream))
                throw new RuntimeException('Guard state directory cannot be synchronized.');

            try {
                if (!@fsync($directory_stream))
                    throw new RuntimeException('Guard state directory cannot be synchronized.');
            } finally {
                fclose($directory_stream);
            }
        } finally {
            fclose($stream);

            if ($this->pathExists($temporary_path))
                @unlink($temporary_path);
        }
    }

    /**
     * Close an inherited descriptor without unlocking the parent's shared lock.
     */
    public function closeInChild(): void
    {
        if (is_resource($this->lock))
            fclose($this->lock);

        $this->lock = null;
    }

    /**
     * Release the descriptor without removing its permanent lock file.
     */
    public function __destruct()
    {
        $this->closeInChild();
    }

    /**
     * Check both descriptor and pathname identify one private regular file.
     *
     * @param resource $stream
     */
    private function assertPrivateFile(string $path, $stream): void
    {
        $path_stat = $this->assertPrivatePath($path);
        $stream_stat = fstat($stream);

        if ($stream_stat === false || $path_stat['ino'] !== $stream_stat['ino'] || $path_stat['dev'] !== $stream_stat['dev'] || $stream_stat['uid'] !== posix_geteuid() || ($stream_stat['mode'] & 0077) !== 0 || $stream_stat['nlink'] !== 1)
            throw new RuntimeException('Guard storage files must be private regular files owned by the current user.');
    }

    /**
     * Reject links and special files before opening a path that could block.
     *
     * @return array<string|int, int>
     */
    private function assertPrivatePath(string $path): array
    {
        clearstatcache(true, $path);

        if (!$this->pathExists($path))
            throw new RuntimeException('Guard storage file is missing.');

        $path_stat = @lstat($path);

        if ($path_stat === false || ($path_stat['mode'] & 0170000) !== 0100000 || $path_stat['uid'] !== posix_geteuid() || ($path_stat['mode'] & 0077) !== 0 || $path_stat['nlink'] !== 1)
            throw new RuntimeException('Guard storage files must be private regular files owned by the current user.');

        return $path_stat;
    }

    /**
     * Require this instance to hold the lock before using durable state.
     */
    private function assertLocked(): void
    {
        if (!is_resource($this->lock))
            throw new RuntimeException('Guard state access requires the application lock.');
    }

    /**
     * Accept only this application's exact state schema.
     *
     * @param array<mixed> $state
     */
    private function validate(array $state): void
    {
        $keys = array_keys($state);
        sort($keys);

        if ($keys !== ['application_path', 'boot_id', 'launches', 'pgid', 'version'] || $state['version'] !== 1 || $state['application_path'] !== $this->application_path || !is_string($state['boot_id']) || !$this->isBootId($state['boot_id']) || !is_int($state['launches']) || $state['launches'] < 0 || !($state['pgid'] === null || (is_int($state['pgid']) && $state['pgid'] > 1)))
            throw new RuntimeException('Guard state does not match the application schema.');
    }

    /**
     * Detect missing files without treating a broken symlink as absent history.
     */
    private function pathExists(string $path): bool
    {
        clearstatcache(true, $path);

        return file_exists($path) || is_link($path);
    }

    /**
     * Validate the Linux boot identity before trusting a persisted process group.
     */
    private function isBootId(string $boot_id): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $boot_id) === 1;
    }

    /**
     * Keep the stored application identity stable across release symlink changes.
     */
    private function isNormalizedAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') && $path !== '/' && !str_contains($path, "\0") && !str_contains($path, '//') && preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path) === 0;
    }
}
