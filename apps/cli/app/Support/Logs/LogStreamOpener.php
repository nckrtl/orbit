<?php

declare(strict_types=1);

namespace App\Support\Logs;

use App\Support\Realtime\RealtimeChannelGrant;
use App\Support\Realtime\RealtimeChannelOpener;
use App\Support\Realtime\RealtimeConnectionException;
use InvalidArgumentException;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Logs\CreateLogStreamRequest;
use Orbit\Sdk\Requests\Logs\DestroyLogStreamRequest;
use Orbit\Sdk\Requests\Logs\InstanceLogStreamTarget;
use Orbit\Sdk\Requests\Logs\ProcessLogStreamTarget;
use Orbit\Sdk\Requests\Logs\RenewLogStreamRequest;
use Orbit\Sdk\Responses\Logs\LogStreamRenewalResponse;
use Orbit\Sdk\Responses\Logs\LogStreamResponse;
use Saloon\Exceptions\Request\FatalRequestException;
use Throwable;

/**
 * Opens one live log stream for each realtime connection and keeps its lease. A new connection
 * has a new socket ID, so the subscriber calls open() again after a reconnect; the previous
 * stream is then closed on a best-effort basis.
 *
 * A stream starts inactive: the Node agent reads it only after the first renewal. So a new
 * stream is due for renewal at once, and the caller renews it as soon as its channel
 * subscription succeeds, then every `renew_seconds`.
 *
 * open() lets a Gateway refusal such as `logs.live_unavailable` propagate as GatewayApiException.
 * It turns an unreachable Gateway into RealtimeConnectionException, so the subscriber retries
 * with its reconnect backoff.
 */
final class LogStreamOpener implements RealtimeChannelOpener
{
    private const int CLOSE_TIMEOUT_SECONDS = 5;

    private const int RENEW_TIMEOUT_SECONDS = 10;

    /** How soon an activation that could not reach the Gateway is tried again. */
    private const int ACTIVATION_RETRY_SECONDS = 1;

    /** The most earlier lines a stream may ask for. */
    private const int MAX_LINES = 1000;

    /** Refusals that end the renewals: every other failure, such as a 502 while the Gateway restarts, is tried again. */
    private const array FINAL_RENEWAL_ERRORS = ['logs.stream_not_found', 'node_access.required', 'peer.identity_unknown'];

    private ?LogStreamResponse $stream = null;

    private float $renewAt = 0.0;

    private bool $active = false;

    private int $opened = 0;

    /** @param  int  $history  How many earlier lines the next stream asks for: `--lines` at first. */
    public function __construct(
        private readonly GatewayConnector $connector,
        private readonly InstanceLogStreamTarget|ProcessLogStreamTarget $target,
        private int $history,
        private readonly LogFollowClock $clock,
    ) {}

    /**
     * Ask for more earlier lines in the streams opened from now on, so a reopened stream reaches back
     * to the lines printed last. It never asks for fewer.
     */
    public function requestHistory(int $lines): void
    {
        $this->history = max($this->history, min($lines, self::MAX_LINES));
    }

    #[\Override]
    public function open(string $socketId): RealtimeChannelGrant
    {
        $this->close();

        try {
            $stream = $this->send(new CreateLogStreamRequest($this->target, $socketId, $this->history), LogStreamResponse::class);
        } catch (FatalRequestException $exception) {
            throw new RealtimeConnectionException('Could not reach the gateway to open the log stream.', previous: $exception);
        }

        $this->stream = $stream;
        $this->active = false;
        $this->renewAt = $this->clock->now();
        $this->opened++;

        return new RealtimeChannelGrant($stream->channel, $stream->auth);
    }

    /** The ID of the open stream, or null when none is open. */
    public function streamId(): ?string
    {
        return $this->stream?->id;
    }

    /** How many streams this opener has opened, so a caller can tell a reopened stream apart. */
    public function openedCount(): int
    {
        return $this->opened;
    }

    /**
     * Renew the open stream when it is due: at once for a new stream, which activates it, and
     * then every renewal interval. A failed renewal, such as an unreachable Gateway or a 502, is
     * retried after a second while the stream is not yet active, and at the next interval after
     * that, while the lease still runs.
     *
     * @throws GatewayApiException when the Gateway refuses the renewal for good: the stream is gone
     *                             or the caller lost access.
     */
    public function renewIfDue(): void
    {
        $stream = $this->stream;

        if ($stream === null || $this->clock->now() < $this->renewAt) {
            return;
        }

        $this->renewAt = $this->clock->now() + $stream->renewSeconds;
        $request = new RenewLogStreamRequest($this->target, $stream->id);
        $request->config()->merge(['timeout' => self::RENEW_TIMEOUT_SECONDS]);

        try {
            $this->send($request, LogStreamRenewalResponse::class);
            $this->active = true;
        } catch (FatalRequestException|GatewayApiException $exception) {
            if ($exception instanceof GatewayApiException && in_array($exception->errorCode(), self::FINAL_RENEWAL_ERRORS, strict: true)) {
                throw $exception;
            }

            if (! $this->active) {
                $this->renewAt = $this->clock->now() + self::ACTIVATION_RETRY_SECONDS;
            }
        }
    }

    /** Drop the stream without a request, because the Gateway already ended it. */
    public function forget(): void
    {
        $this->stream = null;
    }

    /** Close the open stream on a best-effort basis. Its lease ends it anyway. */
    public function close(): void
    {
        $stream = $this->stream;
        $this->stream = null;

        if ($stream === null) {
            return;
        }

        $request = new DestroyLogStreamRequest($this->target, $stream->id);
        $request->config()->merge(['timeout' => self::CLOSE_TIMEOUT_SECONDS, 'connect_timeout' => self::CLOSE_TIMEOUT_SECONDS]);

        try {
            $this->connector->send($request);
        } catch (Throwable) {
            // Best effort: a stream that is not closed expires with its lease.
        }
    }

    /**
     * @template TResponse of object
     *
     * @param  class-string<TResponse>  $responseClass
     * @return TResponse
     *
     * @throws GatewayApiException
     * @throws FatalRequestException
     */
    private function send(GatewayRequest $request, string $responseClass): object
    {
        try {
            $dto = $this->connector->send($request)->dto();
        } catch (InvalidArgumentException $exception) {
            throw new GatewayApiException('Gateway response is invalid.', 'gateway.invalid_response', previous: $exception);
        }

        if (! $dto instanceof $responseClass) {
            throw new GatewayApiException('Gateway response is invalid.', 'gateway.invalid_response');
        }

        return $dto;
    }
}
