<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Commands\GatewayCommand;
use App\Data\GatewayProfile;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\InterruptIntent;
use App\Support\GatewayFailureRenderer;
use App\Support\Logs\ConsoleLogFollowOutput;
use App\Support\Logs\LogFollowClock;
use App\Support\Logs\LogFollower;
use App\Support\Logs\LogStreamOpener;
use App\Support\Realtime\RealtimeState;
use App\Support\Realtime\RealtimeSubscriber;
use App\Support\Realtime\WebSocketTransport;
use Closure;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Logs\InstanceLogStreamTarget;
use Orbit\Sdk\Requests\Logs\ProcessLogStreamTarget;
use Throwable;

/**
 * `--follow` for `instance:logs` and `process:logs`: a live log stream with a polling fallback,
 * rendered as log lines or as one JSON object per line. Ctrl-C closes the stream and exits 0.
 *
 * @phpstan-require-extends GatewayCommand
 */
trait FollowsLogs
{
    /** @param  Closure(int): string  $fetch  One-shot read of the last lines of the log, for the polling fallback. */
    protected function followLogs(
        GatewayConnector $connector,
        GatewayProfile $profile,
        InstanceLogStreamTarget|ProcessLogStreamTarget $target,
        int $lines,
        Closure $fetch,
    ): int {
        $clock = app(LogFollowClock::class);
        $transport = app(WebSocketTransport::class);
        $opener = new LogStreamOpener($connector, $target, $lines, $clock);
        $subscriber = RealtimeSubscriber::forChannel($profile, app()->version(), $opener, $transport, $clock->now(...));

        if ($subscriber->state() === RealtimeState::NotConfigured) {
            $subscriber = RealtimeSubscriber::forChannel(
                $this->discoveredRealtimeProfile($connector, $profile),
                app()->version(),
                $opener,
                $transport,
                $clock->now(...),
            );
        }

        $follower = new LogFollower($subscriber, $opener, $fetch, $lines, $clock, new ConsoleLogFollowOutput($this->output, $this->option('json') === true));
        $handlers = [];
        $async = null;

        try {
            if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal_get_handler')) {
                $async = pcntl_async_signals();

                foreach ([SIGINT, SIGTERM] as $signal) {
                    $handlers[$signal] = pcntl_signal_get_handler($signal);
                    pcntl_signal($signal, static function (int $received): never {
                        throw new ConsoleInterrupted($received);
                    }, restart_syscalls: false);
                }

                pcntl_async_signals(true);
            }

            $follower->run();

            return self::SUCCESS;
        } catch (GatewayApiException $exception) {
            if (InterruptIntent::pending() === null) {
                $opener->close();
                $code = $exception->errorCode() ?? 'gateway.request_failed';

                return $this->renderGatewayFailure(
                    $code,
                    $exception->getMessage(),
                    $exception->requestId(),
                    details: GatewayFailureRenderer::safeDetails($code, $exception->details()),
                );
            }
        } catch (Throwable $exception) {
            if (! $exception instanceof ConsoleInterrupted && InterruptIntent::pending() === null) {
                $opener->close();

                throw $exception;
            }
        } finally {
            foreach ($handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }

            if ($async !== null) {
                pcntl_async_signals($async);
            }

            $subscriber->close();
        }

        // Ctrl-C ends a follow successfully once its stream is closed.
        InterruptIntent::consume();
        $opener->close();

        return self::SUCCESS;
    }
}
