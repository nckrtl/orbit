<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Runs a cleanup when Ctrl-C or a termination signal ends the process, because PHP skips shutdown functions then.
 * The previous handler still runs, or the default action ends the process. SIGKILL cannot be handled, so callers
 * also remove leftovers of such runs.
 */
final class InterruptCleanup
{
    public static function register(callable $cleanup): void
    {
        if (! function_exists('pcntl_signal') || ! function_exists('posix_kill')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
            $previous = pcntl_signal_get_handler($signal);

            pcntl_signal($signal, static function (int $signal, mixed $information) use ($cleanup, $previous): void {
                $cleanup();

                if (is_callable($previous)) {
                    $previous($signal, $information);

                    return;
                }

                if ($previous === SIG_IGN) {
                    return;
                }

                pcntl_signal($signal, SIG_DFL);
                posix_kill(getmypid(), $signal);
            });
        }
    }
}
