<?php

declare(strict_types=1);

namespace GalahadXVI\OctaneGuard;

use Closure;

/**
 * Keep a refused guard idle without triggering Supervisor's automatic restart.
 */
final class Blocked
{
    private const REMINDER_SECONDS = 300;

    /**
     * Wait for an operator to stop the process; never retry or launch anything.
     *
     * @param ?Closure(): bool $stop_requested Preserve a stop received before parking.
     */
    public static function wait(string $reason, ?Closure $stop_requested = null): int
    {
        $stopping = false;

        if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
            $handler = static function (int $signal) use (&$stopping): void {
                $stopping = true;
            };

            foreach ([SIGTERM, SIGINT, SIGHUP] as $signal)
                pcntl_signal($signal, $handler);

            pcntl_async_signals(true);

            # A pre-fork failure may leave termination signals blocked.
            pcntl_sigprocmask(SIG_UNBLOCK, [SIGTERM, SIGINT, SIGHUP]);
            pcntl_signal_dispatch();
        }

        $next_reminder = 0;

        while (! $stopping && ! ($stop_requested !== null && $stop_requested())) {
            $now = hrtime(true);

            if ($now >= $next_reminder) {
                fwrite(STDERR, '[octane-guard] BLOCKED: '.$reason.PHP_EOL);
                fwrite(STDERR, "[octane-guard] No further launches will be attempted. Stop this Forge process, fix the cause, reset the failure count if exhausted, then start it again.\n");
                $next_reminder = $now + self::REMINDER_SECONDS * 1_000_000_000;
            }

            sleep(1);
        }

        fwrite(STDERR, "[octane-guard] Stop requested; leaving blocked state.\n");

        return 0;
    }
}
