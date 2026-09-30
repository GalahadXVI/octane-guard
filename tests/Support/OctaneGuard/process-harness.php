<?php

declare(strict_types=1);

namespace Tests\Support\OctaneGuard;

use RuntimeException;

/**
 * Runs disposable test processes in a new session without booting Laravel.
 */
final class ProcessHarness
{
    private mixed $process = null;

    private int $process_id = 0;

    private ?int $exit_code = null;

    private bool $group_verified = false;

    /**
     * @param list<string> $command PHP script path followed by its arguments.
     */
    public function __construct(private array $command, private string $directory) {}

    /**
     * Start a PHP script as leader of its own process group and session.
     */
    public function start(): self
    {
        if ($this->process !== null)
            throw new RuntimeException('The test process has already started.');

        $launcher = 'if (posix_setsid() < 0) { exit(120); } '
            .'file_put_contents($argv[1], (string) getmypid()); '
            .'pcntl_exec(PHP_BINARY, array_slice($argv, 2)); exit(121);';

        $this->process = proc_open(
            [PHP_BINARY, '-r', $launcher, $this->directory.'/session.pid', ...$this->command],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->directory.'/stdout.log', 'a'], 2 => ['file', $this->directory.'/stderr.log', 'a']],
            $pipes,
        );

        if (! is_resource($this->process))
            throw new RuntimeException('Could not launch the isolated test process.');

        $this->process_id = proc_get_status($this->process)['pid'];
        $this->waitFor(fn (): bool => is_file($this->directory.'/session.pid'));
        $this->group_verified = (int) file_get_contents($this->directory.'/session.pid') === $this->process_id;

        if (! $this->group_verified)
            throw new RuntimeException('The isolated test process has an unexpected session leader.');

        return $this;
    }

    /**
     * Return the process group leader's PID.
     */
    public function pid(): int
    {
        return $this->process_id;
    }

    /**
     * Read the real child status and retain its exit status after termination.
     */
    public function isRunning(): bool
    {
        if (! is_resource($this->process))
            return false;

        $status = proc_get_status($this->process);

        if (! $status['running'] && $this->exit_code === null)
            $this->exit_code = $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];

        return $status['running'];
    }

    /**
     * Wait for a test condition with a bounded monotonic deadline.
     *
     * @param callable(): bool $condition
     */
    public function waitFor(callable $condition, float $timeout = 5.0): void
    {
        $deadline = hrtime(true) + (int) ($timeout * 1_000_000_000);

        do {
            clearstatcache();

            if ($condition())
                return;

            usleep(20_000);
        } while (hrtime(true) < $deadline);

        throw new RuntimeException('Timed out waiting for the isolated test process. '.$this->output());
    }

    /**
     * Wait for the supervised script to exit and return its exit status.
     */
    public function waitForExit(float $timeout = 5.0): int
    {
        $this->waitFor(fn (): bool => ! $this->isRunning(), $timeout);

        return $this->exit_code;
    }

    /**
     * Return captured stdout and stderr for failed assertions.
     */
    public function output(): string
    {
        $output = '';

        foreach (['stdout.log', 'stderr.log'] as $filename)
            if (is_file($this->directory.'/'.$filename))
                $output .= file_get_contents($this->directory.'/'.$filename);

        return $output;
    }

    /**
     * Send a signal to the managed script, as a process manager would.
     */
    public function signal(int $signal): void
    {
        if ($this->isRunning() && ! posix_kill($this->process_id, $signal))
            throw new RuntimeException('Could not signal the isolated test process.');
    }

    /**
     * Stop only this harness's isolated process group and reap the direct child.
     */
    public function shutdown(): void
    {
        if (! is_resource($this->process))
            return;

        $target = $this->group_verified ? -$this->process_id : $this->process_id;
        posix_kill($target, SIGTERM);
        $deadline = hrtime(true) + 500_000_000;

        while (posix_kill($target, 0) && hrtime(true) < $deadline)
            usleep(20_000);

        if (posix_kill($target, 0))
            posix_kill($target, SIGKILL);

        proc_close($this->process);
        $this->process = null;
    }
}
