<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentView;

use App\Actions\Broadcasting\PresenceChannelSigner;
use App\Domain\Broadcasting\RealtimeConnectionData;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Domain\WebSocket\WebSocketHostname;
use App\Models\Node;
use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The agent view subscriber: one Pusher-protocol connection to Reverb that joins every managed
 * Node's `presence-node.{id}` channel and keeps the Gateway's view of each agent current.
 *
 * It signs its own `gateway.{socket id}` membership with the Reverb secret the Gateway already
 * holds, so no message costs an HTTP request. It never sends a client event and never acts on
 * what an agent reports: the view is only an input to reads. ADR 0148 records the design.
 */
final class AgentViewSubscriber
{
    public const float ReceiveWaitSeconds = 0.25;

    public const int RefreshSeconds = 30;

    public const int UnconfiguredRetrySeconds = 60;

    public const int HealthSeconds = 5;

    public const int CommitCheckSeconds = 60;

    public const int PingAfterSeconds = 30;

    public const int PongTimeoutSeconds = 30;

    public const int ConnectTimeoutSeconds = 10;

    private const float MaxBackoffSeconds = 30.0;

    private const string CHANNEL = '/\Apresence-node\.([1-9][0-9]*)\z/D';

    /** @var array<int, AgentChannelState> Joined channels, keyed by Node id. */
    private array $channels = [];

    /** @var array<int, true> Nodes whose stored view changed in this pass. */
    private array $dirty = [];

    private ?string $socketId = null;

    private ?string $credentialsFingerprint = null;

    private ?RealtimeConnectionData $connection = null;

    private bool $configured = false;

    private float $backoff = 0.0;

    private float $nextConnectAt = 0.0;

    private float $nextRefreshAt = 0.0;

    private float $nextHealthAt = 0.0;

    private float $nextCommitCheckAt = 0.0;

    private float $lastMessageAt = 0.0;

    private ?float $pingSentAt = null;

    /**
     * @param  Closure(): ?string  $commit  The Gateway checkout's commit.
     * @param  Closure(): float  $clock
     * @param  Closure(float): void  $sleep
     */
    public function __construct(
        private readonly WebSocketClient $socket,
        private readonly WebSocketCredentialManager $credentials,
        private readonly CacheAgentStateView $view,
        private readonly PresenceChannelSigner $signer,
        private readonly LoggerInterface $log,
        private readonly string $caPath,
        private readonly Closure $commit,
        private readonly Closure $clock,
        private readonly Closure $sleep,
        private readonly ManagedNodeEligibility $eligibility = new ManagedNodeEligibility,
    ) {}

    /**
     * Runs until `$stopping` returns true or the checkout's commit changes. Always leaves the view
     * and its own health empty and the socket closed.
     *
     * @param  Closure(): bool  $stopping
     * @return string Why the subscriber stopped: `stopped` or `commit_changed`.
     */
    public function run(Closure $stopping): string
    {
        $startCommit = ($this->commit)();
        $this->nextCommitCheckAt = $this->now() + self::CommitCheckSeconds;

        try {
            while (! $stopping()) {
                if ($this->now() >= $this->nextCommitCheckAt) {
                    $this->nextCommitCheckAt = $this->now() + self::CommitCheckSeconds;
                    $commit = ($this->commit)();

                    if ($startCommit !== null && $commit !== null && $commit !== $startCommit) {
                        $this->log->info('The Gateway checkout changed; the agent view subscriber restarts.');

                        return 'commit_changed';
                    }
                }

                $this->pass();
            }

            return 'stopped';
        } finally {
            $this->disconnect();

            try {
                $this->view->forgetSubscriber();
            } catch (Throwable $exception) {
                $this->log->warning('The agent view subscriber could not clear its health.', ['error' => $exception->getMessage()]);
            }
        }
    }

    /** One loop pass: connect when needed, handle what arrived, and keep the view current. */
    public function pass(): void
    {
        if (! $this->socket->isConnected()) {
            if ($this->socketId !== null) {
                $this->log->warning('The agent view subscriber lost its Reverb connection.');
                $this->disconnect();
                $this->scheduleReconnect();
                $this->writeHealth(force: true);
            }

            if ($this->now() < $this->nextConnectAt) {
                $this->writeHealth();
                ($this->sleep)(min(self::ReceiveWaitSeconds * 4, max(0.0, $this->nextConnectAt - $this->now())));

                return;
            }

            $this->connect();
            $this->writeHealth();

            return;
        }

        foreach ($this->socket->receive(self::ReceiveWaitSeconds) as $message) {
            $this->handle($message);
        }

        $this->flush();
        $this->keepAlive();

        if ($this->socket->isConnected() && $this->now() >= $this->nextRefreshAt) {
            $this->refresh();
        }

        $this->writeHealth();
    }

    /** @return list<int> Node ids of the joined channels. */
    public function joinedNodes(): array
    {
        return array_keys($this->channels);
    }

    private function connect(): void
    {
        $credentials = $this->currentCredentials();

        if ($credentials === null || $credentials->servingAddress === null || $credentials->servingAddress === '') {
            $this->configured = false;
            $this->nextConnectAt = $this->now() + self::UnconfiguredRetrySeconds;

            return;
        }

        $this->configured = true;
        $connection = $this->connectionData($credentials);

        try {
            $this->socket->connect(new WebSocketEndpoint(
                address: $credentials->servingAddress,
                port: 443,
                serverName: WebSocketHostname::Value,
                path: '/app/'.rawurlencode($credentials->appKey).'?protocol=7&client=orbit-gateway&version=1.0&flash=false',
                caPath: $this->caPath,
            ), self::ConnectTimeoutSeconds);
            $socketId = $this->awaitConnectionEstablished();
        } catch (Throwable $exception) {
            $this->socket->close();
            $this->log->warning('The agent view subscriber could not connect to Reverb.', ['error' => $exception->getMessage()]);
            $this->scheduleReconnect();

            return;
        }

        $this->socketId = $socketId;
        $this->connection = $connection;
        $this->credentialsFingerprint = $this->fingerprint($credentials);
        $this->backoff = 0.0;
        $this->lastMessageAt = $this->now();
        $this->pingSentAt = null;
        $this->log->info('The agent view subscriber connected to Reverb.');
        $this->refresh();
    }

    private function awaitConnectionEstablished(): string
    {
        $deadline = $this->now() + self::ConnectTimeoutSeconds;

        while ($this->socket->isConnected() && $this->now() < $deadline) {
            foreach ($this->socket->receive(self::ReceiveWaitSeconds) as $message) {
                if (($message['event'] ?? null) !== 'pusher:connection_established') {
                    continue;
                }

                $data = $this->data($message);
                $socketId = $data['socket_id'] ?? null;

                if (is_string($socketId) && preg_match('/\A\d+\.\d+\z/D', $socketId) === 1) {
                    return $socketId;
                }
            }
        }

        throw new WebSocketException('Reverb did not establish the connection.');
    }

    /** Joins every managed Node's channel, leaves removed ones, and reconnects after a credential change. */
    private function refresh(): void
    {
        $this->nextRefreshAt = $this->now() + self::RefreshSeconds;
        $credentials = $this->currentCredentials();

        if ($credentials === null || $this->fingerprint($credentials) !== $this->credentialsFingerprint) {
            $this->log->info('The Reverb connection changed; the agent view subscriber reconnects.');
            $this->disconnect();
            $this->configured = $credentials !== null;
            $this->nextConnectAt = $this->now() + ($credentials === null ? self::UnconfiguredRetrySeconds : 0.0);

            return;
        }

        try {
            $nodeIds = Node::query()
                ->where('status', LifecycleStatus::Active)
                ->with('roles')
                ->get()
                ->filter(fn (Node $node): bool => $this->eligibility->allows($node))
                ->map(static fn (Node $node): int => (int) $node->getKey())
                ->values()
                ->all();
        } catch (Throwable $exception) {
            $this->log->warning('The agent view subscriber could not read the Node list.', ['error' => $exception->getMessage()]);

            return;
        }

        foreach (array_diff(array_keys($this->channels), $nodeIds) as $nodeId) {
            $this->send(['event' => 'pusher:unsubscribe', 'data' => ['channel' => "presence-node.{$nodeId}"]]);
            unset($this->channels[$nodeId]);
            $this->forget($nodeId);
        }

        foreach (array_diff($nodeIds, array_keys($this->channels)) as $nodeId) {
            $this->subscribe($nodeId);
        }
    }

    private function subscribe(int $nodeId): void
    {
        if ($this->socketId === null || $this->connection === null) {
            return;
        }

        $channel = "presence-node.{$nodeId}";
        $signature = $this->signer->sign($this->socketId, $channel, $this->connection, "gateway.{$this->socketId}", ['kind' => 'gateway']);

        if ($this->send(['event' => 'pusher:subscribe', 'data' => ['channel' => $channel] + $signature])) {
            $this->channels[$nodeId] = new AgentChannelState;
        }
    }

    /** @param array<string, mixed> $message */
    private function handle(array $message): void
    {
        $this->lastMessageAt = $this->now();
        $this->pingSentAt = null;
        $event = $message['event'] ?? null;

        if ($event === 'pusher:ping') {
            $this->send(['event' => 'pusher:pong', 'data' => []]);

            return;
        }

        if ($event === 'pusher:error' || $event === 'pusher:subscription_error') {
            $this->log->warning('Reverb returned an error to the agent view subscriber.', ['event' => $event, 'channel' => $message['channel'] ?? null]);

            return;
        }

        $channel = $message['channel'] ?? null;

        if (! is_string($event) || ! is_string($channel) || preg_match(self::CHANNEL, $channel, $matches) !== 1) {
            return;
        }

        $nodeId = (int) $matches[1];
        $state = $this->channels[$nodeId] ?? null;

        if ($state === null) {
            return;
        }

        $agent = "agent.{$nodeId}";
        $data = $this->data($message);

        if ($event === 'pusher_internal:subscription_succeeded') {
            $this->joined($nodeId, $state, $data, $agent);

            return;
        }

        if (in_array($event, ['pusher_internal:member_added', 'pusher_internal:member_removed'], strict: true)) {
            if (($data['user_id'] ?? null) === $agent) {
                $this->memberChanged($nodeId, $state);
            }

            return;
        }

        // Reverb stamps every client event on a presence channel with the sender's signed member ID.
        if (
            in_array($event, ['client-heartbeat', 'client-snapshot', 'client-process'], strict: true)
            && ($message['user_id'] ?? null) === $agent
            && $state->apply($event, $data, $this->now())
        ) {
            $this->dirty[$nodeId] = true;
        }
    }

    /** @param array<string, mixed> $data */
    private function joined(int $nodeId, AgentChannelState $state, array $data, string $agent): void
    {
        $presence = is_array($data['presence'] ?? null) ? $data['presence'] : [];
        $ids = is_array($presence['ids'] ?? null) ? $presence['ids'] : [];

        if (! in_array($agent, $ids, strict: true)) {
            $state->reset();
            $this->forget($nodeId);
        }
    }

    /** The agent joined again or left: its earlier state no longer holds. */
    private function memberChanged(int $nodeId, AgentChannelState $state): void
    {
        $state->reset();
        $this->forget($nodeId);
    }

    /** Writes every changed Node once. A Node without a complete snapshot has no entry. */
    private function flush(): void
    {
        foreach (array_keys($this->dirty) as $nodeId) {
            $state = $this->channels[$nodeId] ?? null;

            try {
                if ($state === null || ! $state->hasSnapshot || $state->lastEventAt === null) {
                    $this->view->forgetNode($nodeId);

                    continue;
                }

                $this->view->putNode($nodeId, $state->units, $state->docker, $state->sequence, $state->lastEventAt, $state->agentAt);
            } catch (Throwable $exception) {
                $this->log->warning('The agent view subscriber could not write the view.', ['node_id' => $nodeId, 'error' => $exception->getMessage()]);
            }
        }

        $this->dirty = [];
    }

    private function keepAlive(): void
    {
        if (! $this->socket->isConnected()) {
            return;
        }

        if ($this->pingSentAt !== null) {
            if ($this->now() - $this->pingSentAt >= self::PongTimeoutSeconds) {
                $this->log->warning('Reverb did not answer the agent view subscriber\'s ping.');
                $this->socket->close();
            }

            return;
        }

        if ($this->now() - $this->lastMessageAt >= self::PingAfterSeconds && $this->send(['event' => 'pusher:ping', 'data' => []])) {
            $this->pingSentAt = $this->now();
        }
    }

    /** @param array<string, mixed> $message */
    private function send(array $message): bool
    {
        try {
            $this->socket->send($message);

            return true;
        } catch (Throwable $exception) {
            $this->log->warning('The agent view subscriber could not write to Reverb.', ['error' => $exception->getMessage()]);
            $this->socket->close();

            return false;
        }
    }

    /** Closes the socket and clears the whole view, because nothing keeps it current anymore. */
    private function disconnect(): void
    {
        $this->socket->close();

        foreach (array_keys($this->channels) as $nodeId) {
            $this->forget($nodeId);
        }

        $this->channels = [];
        $this->dirty = [];
        $this->socketId = null;
        $this->connection = null;
        $this->credentialsFingerprint = null;
        $this->pingSentAt = null;
    }

    private function forget(int $nodeId): void
    {
        unset($this->dirty[$nodeId]);

        try {
            $this->view->forgetNode($nodeId);
        } catch (Throwable $exception) {
            $this->log->warning('The agent view subscriber could not clear the view.', ['node_id' => $nodeId, 'error' => $exception->getMessage()]);
        }
    }

    private function scheduleReconnect(): void
    {
        $this->backoff = $this->backoff === 0.0 ? 1.0 : min(self::MaxBackoffSeconds, $this->backoff * 2);
        $jitter = $this->backoff * (mt_rand(0, 250) / 1000);
        $this->nextConnectAt = $this->now() + $this->backoff + $jitter;
    }

    private function writeHealth(bool $force = false): void
    {
        if (! $force && $this->now() < $this->nextHealthAt) {
            return;
        }

        $this->nextHealthAt = $this->now() + self::HealthSeconds;

        try {
            $this->view->putSubscriber($this->configured, $this->socket->isConnected() && $this->socketId !== null, count($this->channels));
        } catch (Throwable $exception) {
            $this->log->warning('The agent view subscriber could not write its health.', ['error' => $exception->getMessage()]);
        }
    }

    private function currentCredentials(): ?WebSocketCredentials
    {
        try {
            return $this->credentials->current();
        } catch (Throwable $exception) {
            $this->log->warning('The agent view subscriber could not read the Reverb connection.', ['error' => $exception->getMessage()]);

            return null;
        }
    }

    private function connectionData(WebSocketCredentials $credentials): RealtimeConnectionData
    {
        return new RealtimeConnectionData(
            host: WebSocketHostname::Value,
            port: 443,
            scheme: 'https',
            appId: $credentials->appId,
            key: $credentials->appKey,
            secret: $credentials->appSecret,
            caCertificatePath: $this->caPath,
            resolveAddress: $credentials->servingAddress,
        );
    }

    private function fingerprint(WebSocketCredentials $credentials): string
    {
        return hash('sha256', implode("\n", [$credentials->appId, $credentials->appKey, $credentials->appSecret, (string) $credentials->servingAddress]));
    }

    /**
     * A Pusher message's `data`, which Reverb sends either as an object or as a JSON string.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function data(array $message): array
    {
        $data = $message['data'] ?? null;

        if (is_string($data)) {
            $data = json_decode($data, associative: true);
        }

        if (! is_array($data) || array_is_list($data)) {
            return [];
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function now(): float
    {
        return ($this->clock)();
    }
}
