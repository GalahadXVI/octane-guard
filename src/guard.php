<?php

declare(strict_types=1);

namespace GalahadXVI\OctaneGuard;

use Throwable;

require_once __DIR__.'/guard-exception.php';

/**
 * Own one foreground Octane generation in Supervisor's process group.
 *
 * This is process management infrastructure; it must not boot Laravel.
 */
final class Guard
{
    public const MAX_LAUNCHES = 3;
    public const REFUSED = 78;

    private bool $stop_requested = false;
    private bool $launched = false;
    private int $owner_pid;

    /**
     * @param list<string> $command An executable and arguments, without a shell.
     */
    public function __construct(
        private State $state,
        private array $command,
        private string $application_path,
        private float $grace_seconds = 10.0,
        private float $previous_group_wait_seconds = 10.0,
    ) {
        $this->owner_pid = getmypid();
    }

    /**
     * Launch once. Supervisor alone decides whether another guard is started.
     */
    public function run(): int
    {
        if ($this->owner_pid !== posix_getpgrp()) {
            $this->log('REFUSED: guard must be the process-group leader, started directly by Supervisor.');

            return self::REFUSED;
        }

        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT, SIGHUP] as $signal)
            pcntl_signal($signal, $this->requestStop(...));

        register_shutdown_function($this->emergencyCleanup(...));

        try {
            $this->state->acquire();
            $state = $this->state->read();

            if (! $this->waitForPreviousGroup($state['pgid'])) {
                $this->log('REFUSED: previous process group still exists or cannot be inspected; no processes were killed.');

                return self::REFUSED;
            }

            if ($this->stop_requested)
                return 0;

            if ($state['launches'] >= self::MAX_LAUNCHES) {
                $this->log('CIRCUIT OPEN: three unsuccessful lifecycles; fix the cause and explicitly reset while stopped.');

                return self::REFUSED;
            }

            if (! chdir($this->application_path))
                throw new GuardException('Cannot enter application directory.');

            # A stop cannot land between reserving ownership and recording the child.
            if (! pcntl_sigprocmask(SIG_BLOCK, [SIGTERM, SIGINT, SIGHUP]))
                throw new GuardException('Cannot block shutdown signals.');

            if ($this->stop_requested) {
                pcntl_sigprocmask(SIG_UNBLOCK, [SIGTERM, SIGINT, SIGHUP]);

                return 0;
            }

            $state['launches']++;
            $state['pgid'] = $this->owner_pid;
            $this->state->save($state);
            $child_pid = pcntl_fork();

            if ($child_pid === -1)
                throw new GuardException('Cannot fork Octane.');

            if ($child_pid === 0)
                $this->executeChild();

            $this->launched = true;
            pcntl_sigprocmask(SIG_UNBLOCK, [SIGTERM, SIGINT, SIGHUP]);
            $this->log('Started Octane child '.$child_pid.' in group '.$this->owner_pid.'; reservation '.$state['launches'].'/'.self::MAX_LAUNCHES.'.');

            while (! $this->stop_requested) {
                $result = pcntl_waitpid($child_pid, $status, WNOHANG);

                if ($result === $child_pid)
                    $this->shutdownGeneration($state, false);

                if ($result === -1 && pcntl_get_last_error() !== PCNTL_EINTR)
                    throw new GuardException('Cannot observe Octane child.');

                usleep(100_000);
            }

            $this->shutdownGeneration($state, true);
        } catch (GuardException $exception) {
            $this->log('REFUSED: '.$exception->getMessage());
            $this->emergencyCleanup();

            return self::REFUSED;
        } catch (Throwable) {
            $this->log('REFUSED: unexpected guard failure. Check the daemon log before restarting.');
            $this->emergencyCleanup();

            return self::REFUSED;
        }
    }

    /**
     * Record a stop without performing filesystem operations in a signal handler.
     */
    private function requestStop(int $signal): void
    {
        $this->stop_requested = true;
    }

    /**
     * Replace the forked child with PHP, preserving the owned process group.
     */
    private function executeChild(): never
    {
        $this->state->closeInChild();

        foreach ([SIGTERM, SIGINT, SIGHUP] as $signal)
            pcntl_signal($signal, SIG_DFL);

        pcntl_sigprocmask(SIG_UNBLOCK, [SIGTERM, SIGINT, SIGHUP]);
        pcntl_exec($this->command[0], array_slice($this->command, 1));
        fwrite(STDERR, "[octane-guard] Child executable could not start.\n");
        exit(1);
    }

    /**
     * Check an earlier generation without ever signalling its stored numeric ID.
     */
    private function waitForPreviousGroup(?int $pgid): bool
    {
        if ($pgid === null)
            return true;

        $deadline = hrtime(true) + (int) ($this->previous_group_wait_seconds * 1_000_000_000);

        do {
            if (self::groupIsAbsent($pgid))
                return true;

            if ($this->stop_requested)
                return false;

            usleep(100_000);
        } while (hrtime(true) < $deadline);

        return false;
    }

    /**
     * Only ESRCH proves absence; permission errors must never count as absence.
     */
    public static function groupIsAbsent(int $pgid): bool
    {
        if ($pgid <= 1)
            return false;

        return ! posix_kill(-$pgid, 0) && posix_get_last_error() === 3;
    }

    /**
     * TERM followed by group KILL closes the generation, including this guard.
     *
     * @param array{version: int, application_path: string, boot_id: string, launches: int, pgid: ?int} $state
     */
    private function shutdownGeneration(array $state, bool $requested): never
    {
        $this->log($requested ? 'Stop requested; closing this Octane generation.' : 'Octane exited unexpectedly; closing this generation before any retry.');

        # Ignore our own group TERM. The shutdown reason is now immutable.
        foreach ([SIGTERM, SIGINT, SIGHUP] as $signal)
            pcntl_signal($signal, SIG_IGN);

        $this->signalOwnedGroup(SIGTERM);
        $deadline = hrtime(true) + (int) ($this->grace_seconds * 1_000_000_000);

        do {
            while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {}

            usleep(50_000);
        } while (hrtime(true) < $deadline);

        if ($requested) {
            $state['launches']--;
            $this->state->save($state);
        }

        # Keep the PGID recorded. The next guard must prove this group is gone.
        $this->signalOwnedGroup(SIGKILL);
        exit(self::REFUSED);
    }

    /**
     * Last-resort cleanup on exceptions or fatal PHP errors after launching.
     */
    public function emergencyCleanup(): void
    {
        if ($this->launched && getmypid() === $this->owner_pid)
            $this->signalOwnedGroup(SIGKILL);
    }

    /**
     * The live group-leader PID is the ownership anchor, never a stored old PID.
     */
    private function signalOwnedGroup(int $signal): void
    {
        if (getmypid() !== $this->owner_pid || posix_getpgrp() !== $this->owner_pid)
            throw new GuardException('Process-group ownership changed.');

        if (! posix_kill(-$this->owner_pid, $signal))
            throw new GuardException('Cannot signal owned process group.');
    }

    /**
     * Write fixed operational messages into the existing Forge daemon log.
     */
    private function log(string $message): void
    {
        fwrite(STDERR, '[octane-guard] '.$message.PHP_EOL);
    }
}
