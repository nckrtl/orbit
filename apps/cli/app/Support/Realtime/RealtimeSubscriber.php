<?php

declare(strict_types=1);

namespace App\Support\Realtime;

use App\Data\GatewayProfile;
use Closure;
use stdClass;

/**
 * Subscribes to the gateway's private `orbit` realtime channel and hands back decoded events
 * without blocking a caller's render loop.
 *
 * poll() is safe to call every tick: it never performs a blocking wait. The one exception is
 * establishing or re-establishing the socket itself (TCP connect, TLS handshake, HTTP channel
 * authorization), which needs bounded blocking I/O by nature; poll() only does that work when a
 * reconnect is due under the backoff schedule, not on every call, and then only up to a short
 * per-step timeout. Once connected, poll() only drains frames already sitting on the socket.
 */
final class RealtimeSubscriber
{
    private const string CHANNEL = 'private-orbit';

    /** @var list<int> Reconnect backoff in seconds: 1, 2, 4, 8, 16, 30, 30, ... */
    private const array BACKOFF_SECONDS = [1, 2, 4, 8, 16, 30];

    private const float CONNECT_TIMEOUT_SECONDS = 5.0;

    private const float HANDSHAKE_TIMEOUT_SECONDS = 10.0;

    private const string PHASE_IDLE = 'idle';

    private const string PHASE_AWAITING_ESTABLISHED = 'awaiting_established';

    private const string PHASE_AWAITING_SUBSCRIBED = 'awaiting_subscribed';

    private const string PHASE_CONNECTED = 'connected';

    private RealtimeState $state;

    private string $phase = self::PHASE_IDLE;

    private int $backoffIndex = 0;

    private float $nextAttemptAt = 0.0;

    private float $handshakeDeadline = 0.0;

    /** @var Closure(): float */
    private readonly Closure $clock;

    public function __construct(
        private readonly WebSocketTransport $transport,
        private readonly ?RealtimeConnectionConfig $config,
        private readonly RealtimeChannelAuthorizer $authorizer,
        ?Closure $clock = null,
    ) {
        $this->state = $this->config === null ? RealtimeState::NotConfigured : RealtimeState::Reconnecting;
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * Build a subscriber for $profile's active gateway. Returns a subscriber in the
     * `not_configured` state when the profile (and no environment override) supplies a realtime
     * URL and key; callers still call poll() safely in that state, which always returns [].
     */
    public static function forProfile(
        ?GatewayProfile $profile,
        string $clientVersion,
        ?WebSocketTransport $transport = null,
    ): self {
        $config = RealtimeConnectionConfig::resolve($profile, $clientVersion);

        return new self(
            transport: $transport ?? new StreamWebSocketTransport,
            config: $config,
            authorizer: new GatewayChannelAuthorizer(
                $config === null ? '' : $config->gatewayUrl,
                $config?->caPath,
            ),
        );
    }

    public function state(): RealtimeState
    {
        return $this->state;
    }

    /** Start connecting when realtime is configured and no attempt is already under way. */
    public function connect(): void
    {
        if ($this->config === null) {
            $this->state = RealtimeState::NotConfigured;

            return;
        }

        if ($this->phase === self::PHASE_IDLE) {
            $this->beginConnection();
        }
    }

    /**
     * Drain every realtime event currently available, in arrival order, without blocking.
     *
     * @return list<RealtimeEvent>
     */
    public function poll(): array
    {
        if ($this->config === null) {
            $this->state = RealtimeState::NotConfigured;

            return [];
        }

        if ($this->phase === self::PHASE_IDLE) {
            if (($this->clock)() < $this->nextAttemptAt) {
                return [];
            }

            $this->beginConnection();
        }

        if ($this->phase !== self::PHASE_CONNECTED) {
            $this->advanceHandshake();

            if ($this->phase !== self::PHASE_CONNECTED) {
                return [];
            }
        }

        return $this->drainEvents();
    }

    public function close(): void
    {
        $this->transport->close();
        $this->phase = self::PHASE_IDLE;
        $this->backoffIndex = 0;
        $this->nextAttemptAt = 0.0;
        $this->state = $this->config === null ? RealtimeState::NotConfigured : RealtimeState::Reconnecting;
    }

    private function beginConnection(): void
    {
        $config = $this->config;

        if ($config === null) {
            return;
        }

        try {
            $this->transport->connect($config->socketUrl, $config->caPath, self::CONNECT_TIMEOUT_SECONDS);
            $this->phase = self::PHASE_AWAITING_ESTABLISHED;
            $this->handshakeDeadline = ($this->clock)() + self::HANDSHAKE_TIMEOUT_SECONDS;
        } catch (RealtimeConnectionException) {
            $this->scheduleReconnect();
        }
    }

    /** Progress the connection-established / channel-subscribe handshake without blocking. */
    private function advanceHandshake(): void
    {
        try {
            while (($message = $this->transport->receive()) !== null) {
                if ($this->phase === self::PHASE_AWAITING_ESTABLISHED) {
                    $this->handleAwaitingEstablished($message);

                    continue;
                }

                if ($this->handleAwaitingSubscribed($message)) {
                    return;
                }
            }

            if (! $this->transport->isConnected() || ($this->clock)() > $this->handshakeDeadline) {
                throw new RealtimeConnectionException('The realtime channel handshake did not complete in time.');
            }
        } catch (RealtimeConnectionException|RealtimeProtocolException) {
            $this->transport->close();
            $this->scheduleReconnect();
        }
    }

    /** @param  array<string, mixed>  $message */
    private function handleAwaitingEstablished(array $message): void
    {
        if (($message['event'] ?? null) !== 'pusher:connection_established') {
            return;
        }

        $socketId = $this->messageData($message)['socket_id'] ?? null;

        if (! is_string($socketId) || $socketId === '') {
            throw new RealtimeProtocolException('The realtime socket omitted its socket_id.');
        }

        $auth = $this->authorizer->authorize($socketId, self::CHANNEL);

        $this->transport->send([
            'event' => 'pusher:subscribe',
            'data' => ['auth' => $auth, 'channel' => self::CHANNEL],
        ]);

        $this->phase = self::PHASE_AWAITING_SUBSCRIBED;
        $this->handshakeDeadline = ($this->clock)() + self::HANDSHAKE_TIMEOUT_SECONDS;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return bool True once the channel subscription is confirmed and $this->phase is connected.
     */
    private function handleAwaitingSubscribed(array $message): bool
    {
        $event = $message['event'] ?? null;

        if ($event === 'pusher:ping') {
            $this->transport->send(['event' => 'pusher:pong', 'data' => new stdClass]);

            return false;
        }

        if ($this->isProtocolFailure($event)) {
            throw new RealtimeProtocolException('The realtime channel subscription failed.');
        }

        if ($event === 'pusher_internal:subscription_succeeded' && ($message['channel'] ?? null) === self::CHANNEL) {
            $this->phase = self::PHASE_CONNECTED;
            $this->state = RealtimeState::Connected;
            $this->backoffIndex = 0;

            return true;
        }

        return false;
    }

    /** @return list<RealtimeEvent> */
    private function drainEvents(): array
    {
        $events = [];

        try {
            while (($message = $this->transport->receive()) !== null) {
                $event = $this->handleConnectedMessage($message);

                if ($event instanceof RealtimeEvent) {
                    $events[] = $event;
                }
            }

            if (! $this->transport->isConnected()) {
                throw new RealtimeConnectionException('The realtime socket disconnected.');
            }
        } catch (RealtimeConnectionException|RealtimeProtocolException) {
            $this->transport->close();
            $this->scheduleReconnect();
        }

        return $events;
    }

    /** @param  array<string, mixed>  $message */
    private function handleConnectedMessage(array $message): ?RealtimeEvent
    {
        $event = $message['event'] ?? null;

        if ($event === 'pusher:ping') {
            $this->transport->send(['event' => 'pusher:pong', 'data' => new stdClass]);

            return null;
        }

        if ($this->isProtocolFailure($event)) {
            throw new RealtimeProtocolException('The realtime channel reported a protocol error.');
        }

        if (
            ! is_string($event)
            || str_starts_with($event, 'pusher:')
            || str_starts_with($event, 'pusher_internal:')
            || ($message['channel'] ?? null) !== self::CHANNEL
        ) {
            return null;
        }

        return RealtimeEvent::fromChannelPayload($event, $message['data'] ?? null);
    }

    private function isProtocolFailure(mixed $event): bool
    {
        return in_array($event, ['pusher:error', 'pusher_internal:subscription_error'], strict: true);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function messageData(array $message): array
    {
        $data = $message['data'] ?? [];

        if (is_string($data)) {
            $data = json_decode($data, associative: true);
        }

        return is_array($data) ? $data : [];
    }

    private function scheduleReconnect(): void
    {
        $this->phase = self::PHASE_IDLE;
        $this->state = RealtimeState::Reconnecting;
        $delay = self::BACKOFF_SECONDS[min($this->backoffIndex, count(self::BACKOFF_SECONDS) - 1)];
        $this->nextAttemptAt = ($this->clock)() + $delay;
        $this->backoffIndex++;
    }
}
