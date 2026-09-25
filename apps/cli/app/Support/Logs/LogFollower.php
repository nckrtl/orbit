<?php

declare(strict_types=1);

namespace App\Support\Logs;

use App\Support\Console\InterruptIntent;
use App\Support\Realtime\RealtimeState;
use App\Support\Realtime\RealtimeSubscriber;
use Closure;
use Orbit\Sdk\GatewayApiException;

/**
 * Follows one Instance or Process log: live through a log stream when it can, and by polling the
 * one-shot read every five seconds when it cannot. It prints each line once across a reopened
 * stream and the switch to polling, using the lines it printed last as context. When the realtime
 * socket has not connected 15 seconds after the start or after a drop, it polls for the rest of
 * the command.
 *
 * run() returns when the Gateway closes the stream. It throws GatewayApiException for a failure
 * the follow cannot recover from, including `node_access.required` when the stream is revoked,
 * and ConsoleInterrupted on Ctrl-C.
 */
final class LogFollower
{
    public const float POLL_SECONDS = 5.0;

    private const string LIVE_UNAVAILABLE = 'logs.live_unavailable';

    /** How long the realtime socket may stay unconnected, at the start or after a drop, before the follow polls instead. */
    private const float CONNECT_GRACE_SECONDS = 15.0;

    private const float TICK_SECONDS = 0.1;

    /** How long a reopened stream's first lines may take to reach the last printed line. */
    private const float CATCH_UP_SECONDS = 2.0;

    private readonly LogTailOverlap $overlap;

    private bool $polling = false;

    private float $nextPollAt = 0.0;

    private int $stream = 0;

    /** The last `log.lines` sequence printed from the current stream. */
    private int $sequence = 0;

    /** @var list<string>|null Lines of a reopened stream held until they reach the last printed line. */
    private ?array $catchUp = null;

    private int $catchUpDropped = 0;

    private int $catchUpSkipped = 0;

    private float $catchUpStartedAt = 0.0;

    private RealtimeState $lastState;

    /** When the socket last started to connect without being connected since, or null while it is connected. */
    private ?float $unconnectedSince = null;

    /** @param  Closure(): string  $fetch  One-shot read of the log tail; throws GatewayApiException. */
    public function __construct(
        private readonly RealtimeSubscriber $subscriber,
        private readonly LogStreamOpener $opener,
        private readonly Closure $fetch,
        private readonly int $lines,
        private readonly LogFollowClock $clock,
        private readonly LogFollowOutput $output,
    ) {
        $this->overlap = new LogTailOverlap;
        $this->lastState = $subscriber->state();
    }

    public function run(): void
    {
        if ($this->subscriber->state() === RealtimeState::NotConfigured) {
            $this->startPolling(self::LIVE_UNAVAILABLE, 'realtime_not_configured');
        } else {
            $this->unconnectedSince = $this->clock->now();
            $this->subscriber->connect();
        }

        for (; ;) {
            InterruptIntent::throwIfPending();

            if ($this->polling) {
                $this->poll();
            } elseif ($this->followLive()) {
                return;
            }

            InterruptIntent::throwIfPending();
            $this->clock->sleep($this->polling ? max(0.0, $this->nextPollAt - $this->clock->now()) : self::TICK_SECONDS);
        }
    }

    /** @return bool True once the Gateway closed the stream. */
    private function followLive(): bool
    {
        try {
            $events = $this->subscriber->pollDecoded(LogStreamEvent::decode(...));
        } catch (GatewayApiException $exception) {
            $code = $exception->errorCode();

            if ($code !== 'logs.live_unavailable' && $code !== 'logs.stream_limit') {
                throw $exception;
            }

            $this->subscriber->close();
            $this->startPolling($code, $code === 'logs.live_unavailable' ? self::reason($exception->details()['reason'] ?? null) : null);

            return false;
        }

        $this->reportStateChange();

        if ($this->realtimeUnreachable()) {
            $this->opener->close();

            return $this->fallBack('realtime_unreachable');
        }

        foreach ($events as $event) {
            if ($event->streamId !== $this->opener->streamId()) {
                continue;
            }

            if ($event->type === LogStreamEvent::LINES) {
                $this->receive($event);

                continue;
            }

            $this->opener->forget();

            return match ($event->reason) {
                'closed' => true,
                'expired' => $this->reopen(),
                'revoked' => throw new GatewayApiException('Node access is required.', 'node_access.required'),
                default => $this->fallBack($event->reason),
            };
        }

        $this->flushCatchUpWhenDue();

        if ($this->subscriber->state() !== RealtimeState::Connected) {
            return false;
        }

        // A new stream is due at once: this first renewal, right after its subscription
        // succeeded, activates it. Later renewals keep its lease.
        try {
            $this->opener->renewIfDue();
        } catch (GatewayApiException $exception) {
            if ($exception->errorCode() !== 'logs.stream_not_found') {
                throw $exception;
            }

            $this->opener->forget();
            $this->reopen();
        }

        return false;
    }

    private function receive(LogStreamEvent $event): void
    {
        if ($this->opener->openedCount() === $this->stream && $event->sequence <= $this->sequence) {
            // The Gateway repeats a part after a failed relay run; its sequence shows it was printed.
            return;
        }

        $this->sequence = $event->sequence;
        $lines = array_map(LogRedaction::redact(...), $event->lines);

        if ($this->opener->openedCount() !== $this->stream) {
            $this->stream = $this->opener->openedCount();

            if ($this->overlap->hasContext()) {
                $this->catchUp = [];
                $this->catchUpDropped = 0;
                $this->catchUpSkipped = 0;
                $this->catchUpStartedAt = $this->clock->now();
            }
        }

        if ($this->catchUp === null) {
            $this->emit($lines, $event->dropped, $event->skipped);

            return;
        }

        $this->catchUp = [...$this->catchUp, ...$lines];
        $this->catchUpDropped += $event->dropped;
        $this->catchUpSkipped += $event->skipped;
        $after = $this->overlap->find($this->catchUp);

        if ($after !== null) {
            $this->catchUp = null;
            $this->emit($after, $this->catchUpDropped, $this->catchUpSkipped);

            return;
        }

        if (count($this->catchUp) >= $this->lines) {
            $this->flushCatchUp();
        }
    }

    private function flushCatchUpWhenDue(): void
    {
        if ($this->catchUp !== null && $this->clock->now() - $this->catchUpStartedAt >= self::CATCH_UP_SECONDS) {
            $this->flushCatchUp();
        }
    }

    /** Print a reopened stream's first lines in full when they never reached the last printed line. */
    private function flushCatchUp(): void
    {
        $lines = $this->catchUp ?? [];
        $this->catchUp = null;
        $this->emit($lines, $this->catchUpDropped, $this->catchUpSkipped);
    }

    /** A new socket gets a new socket ID, and with it a new stream with the same line count. */
    private function reopen(): bool
    {
        $this->catchUp = null;
        $this->subscriber->close();
        $this->lastState = $this->subscriber->state();
        $this->unconnectedSince = $this->clock->now();
        $this->subscriber->connect();

        return false;
    }

    private function fallBack(string $reason): bool
    {
        $this->subscriber->close();
        $this->startPolling(self::LIVE_UNAVAILABLE, $reason);

        return false;
    }

    private function startPolling(string $code, ?string $reason): void
    {
        $this->catchUp = null;
        $this->polling = true;
        $this->nextPollAt = $this->clock->now();
        $this->output->polling($code, $reason);
    }

    private function poll(): void
    {
        if ($this->clock->now() < $this->nextPollAt) {
            return;
        }

        $this->nextPollAt = $this->clock->now() + self::POLL_SECONDS;
        $logs = rtrim(LogRedaction::redact(($this->fetch)()), "\r\n");
        $lines = $logs === '' ? [] : (preg_split('/\r?\n/', $logs) ?: []);

        $this->emit($this->overlap->after($lines), 0, 0);
    }

    /** @param  list<string>  $lines */
    private function emit(array $lines, int $dropped, int $skipped): void
    {
        if ($lines === [] && $dropped === 0 && $skipped === 0) {
            return;
        }

        $this->overlap->remember($lines);
        $this->output->lines($lines, $dropped, $skipped);
    }

    private function reportStateChange(): void
    {
        $state = $this->subscriber->state();

        if ($this->lastState === RealtimeState::Connected && $state === RealtimeState::Reconnecting) {
            // The stream belongs to the dropped socket; the next connection opens its own.
            $this->opener->close();
            $this->output->reconnecting();
            $this->unconnectedSince = $this->clock->now();
        }

        if ($state === RealtimeState::Connected) {
            $this->unconnectedSince = null;
        }

        $this->lastState = $state;
    }

    /** The socket has not connected within the grace period, for example because the host cannot reach Reverb. */
    private function realtimeUnreachable(): bool
    {
        return $this->unconnectedSince !== null
            && $this->clock->now() - $this->unconnectedSince >= self::CONNECT_GRACE_SECONDS;
    }

    private static function reason(mixed $reason): ?string
    {
        return is_string($reason) && preg_match('/\A[a-z][a-z_]{0,63}\z/D', $reason) === 1 ? $reason : null;
    }
}
