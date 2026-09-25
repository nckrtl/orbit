<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentView;

use App\Actions\Broadcasting\PresenceChannelSigner;
use App\Domain\AgentView\AgentStateView;
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
 * The agent view subscriber: a Pusher-protocol connection to Reverb that joins every managed
 * Node's `presence-node.{id}` channel and keeps the Gateway's view of each agent current.
 *
 * It signs its own `gateway.{socket id}` membership with the Reverb secret the Gateway already
 * holds, so no message costs an HTTP request. It never sends a client event and never acts on
 * what an agent reports: the view is only an input to reads. ADR 0148 records the design.
 *
 * During a `websocket` move two Nodes serve `reverb.orbit`: agents stay on the old Reverb until it
 * closes their connections, and reconnect to the new one. The subscriber then keeps one link to each
 * server and merges them per Node, taking the state with the newest agent event. A Node that loses its
 * state on one link while another link is open, or shortly after a link closed, keeps its stored entry,
 * which goes stale on its own, instead of reading as missing while its agent reconnects.
 *
 * Reverb announces a member only when its first connection joins and its last one leaves, and an
 * agent sends a snapshot only when a member joins. So when agent events keep arriving without a
 * complete snapshot, or the agent's sequence goes back without a membership change, the subscriber
 * leaves and joins that channel again: its new membership makes every agent connection send one.
 */
final class AgentViewSubscriber
{
    public const float ReceiveWaitSeconds = 0.25;

    public const int RefreshSeconds = 30;

    public const int LinkCheckSeconds = 5;

    public const int UnconfiguredRetrySeconds = 60;

    public const int HealthSeconds = 5;

    public const int CommitCheckSeconds = 60;

    public const int PingAfterSeconds = 30;

    public const int PongTimeoutSeconds = 30;

    public const int ConnectTimeoutSeconds = 10;

    /** The old server of a move gets a short connect limit, so an unreachable Node never stalls the serving link. */
    public const int OldServerConnectTimeoutSeconds = 2;

    public const int SnapshotRequestSeconds = 5;

    private const float MaxBackoffSeconds = 30.0;

    private const string CHANNEL = '/\Apresence-node\.([1-9][0-9]*)\z/D';

    /** @var array<string, AgentViewLink> Links keyed by Reverb address, the serving address first. */
    private array $links = [];

    /** @var list<WebSocketClient> Sockets of closed links, ready for the next link. */
    private array $idleSockets = [];

    /** @var array<int, true> Nodes whose stored view changed in this pass. */
    private array $dirty = [];

    /** @var array<int, true> Nodes with a stored entry this subscriber wrote. */
    private array $stored = [];

    /** @var list<int> Node ids to join, from the last refresh. */
    private array $nodeIds = [];

    private ?string $credentialsFingerprint = null;

    private bool $configured = false;

    private float $carryUntil = 0.0;

    private float $nextLinkCheckAt = 0.0;

    private float $nextRefreshAt = 0.0;

    private float $nextHealthAt = 0.0;

    private float $nextCommitCheckAt = 0.0;

    /** @var Closure(): WebSocketClient */
    private readonly Closure $sockets;

    /**
     * @param  Closure(): ?string  $commit  The Gateway checkout's commit.
     * @param  Closure(): float  $clock
     * @param  Closure(float): void  $sleep
     * @param  (Closure(): WebSocketClient)|null  $sockets  Makes the socket of a second link.
     */
    public function __construct(
        WebSocketClient $socket,
        private readonly WebSocketCredentialManager $credentials,
        private readonly CacheAgentStateView $view,
        private readonly PresenceChannelSigner $signer,
        private readonly LoggerInterface $log,
        private readonly string $caPath,
        private readonly Closure $commit,
        private readonly Closure $clock,
        private readonly Closure $sleep,
        private readonly ManagedNodeEligibility $eligibility = new ManagedNodeEligibility,
        private readonly int $reverbPort = 443,
        ?Closure $sockets = null,
    ) {
        $this->idleSockets = [$socket];
        $this->sockets = $sockets ?? static fn (): WebSocketClient => new StreamWebSocketClient;
    }

    /**
     * Runs until `$stopping` returns true or the checkout's commit changes. Always leaves the view
     * and its own health empty and every socket closed.
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
            $this->closeAll();

            try {
                $this->view->forgetSubscriber();
            } catch (Throwable $exception) {
                $this->log->warning('The agent view subscriber could not clear its health.', ['error' => $exception->getMessage()]);
            }
        }
    }

    /** One loop pass: keep a link to every serving Reverb, handle what arrived, and keep the view current. */
    public function pass(): void
    {
        if ($this->links === [] || $this->now() >= $this->nextLinkCheckAt) {
            $this->syncLinks();
        }

        foreach ($this->links as $link) {
            if ($link->socket->isConnected()) {
                continue;
            }

            if ($link->socketId !== null) {
                $this->log->warning('The agent view subscriber lost its Reverb connection.', ['address' => $link->address]);
                $this->lose($link);
                $this->scheduleReconnect($link);
                $this->writeHealth(force: true);
            }

            if ($this->now() >= $link->nextConnectAt) {
                $this->connect($link);
                // Write at once, so the health never reports the old connection state beside fresh Nodes.
                $this->writeHealth(force: true);
            }
        }

        $live = array_filter($this->links, static fn (AgentViewLink $link): bool => $link->isLive());

        if ($live === []) {
            $this->flush();
            $this->writeHealth();
            $waits = array_map(fn (AgentViewLink $link): float => $link->nextConnectAt - $this->now(), $this->links);
            $wait = $waits === [] ? $this->nextLinkCheckAt - $this->now() : min($waits);
            ($this->sleep)(min(self::ReceiveWaitSeconds * 4, max(0.0, $wait)));

            return;
        }

        foreach ($live as $link) {
            foreach ($link->socket->receive(self::ReceiveWaitSeconds / count($live)) as $message) {
                $this->handle($link, $message);
            }
        }

        $this->flush();

        foreach ($live as $link) {
            $this->requestSnapshots($link);
            $this->keepAlive($link);
        }

        if ($this->now() >= $this->nextRefreshAt) {
            $this->refresh();
        }

        $this->writeHealth();
    }

    /** @return list<int> Node ids of the joined channels on any link. */
    public function joinedNodes(): array
    {
        $joined = [];

        foreach ($this->links as $link) {
            $joined += $link->channels;
        }

        return array_keys($joined);
    }

    /** @return list<string> The Reverb addresses the subscriber keeps a link to, the serving address first. */
    public function linkedAddresses(): array
    {
        return array_keys($this->links);
    }

    /** Opens a link to every address that serves Reverb and closes the others, after a credential change too. */
    private function syncLinks(): void
    {
        $this->nextLinkCheckAt = $this->now() + self::LinkCheckSeconds;
        $credentials = $this->currentCredentials();
        $addresses = $credentials?->addresses() ?? [];

        if ($credentials === null || $addresses === []) {
            $this->configured = false;
            $this->nextLinkCheckAt = $this->now() + self::UnconfiguredRetrySeconds;
            $this->removeLinks(array_keys($this->links), carry: false);

            return;
        }

        $this->configured = true;
        $fingerprint = hash('sha256', implode("\n", [$credentials->appId, $credentials->appKey, $credentials->appSecret]));

        if ($this->credentialsFingerprint !== null && $fingerprint !== $this->credentialsFingerprint) {
            $this->log->info('The Reverb credentials changed; the agent view subscriber reconnects.');
            $this->removeLinks(array_keys($this->links), carry: false);
        }

        $this->credentialsFingerprint = $fingerprint;
        $gone = array_values(array_diff(array_keys($this->links), $addresses));

        if ($gone !== []) {
            $this->log->info('A Reverb server stopped serving; the agent view subscriber closes its link.', ['addresses' => $gone]);
            $this->removeLinks($gone, carry: true);
        }

        $links = [];

        foreach ($addresses as $address) {
            $links[$address] = $this->links[$address] ?? new AgentViewLink($address, array_pop($this->idleSockets) ?? ($this->sockets)());
        }

        $this->links = $links;

        if (count($this->links) > 1) {
            $this->carry();
        }
    }

    /** @param list<string> $addresses */
    private function removeLinks(array $addresses, bool $carry): void
    {
        foreach ($addresses as $address) {
            $link = $this->links[$address] ?? null;

            if ($link === null) {
                continue;
            }

            if ($carry) {
                $this->carry();
            }

            $this->lose($link);
            $this->idleSockets[] = $link->socket;
            unset($this->links[$address]);
        }

        $this->flush();
    }

    /** Keeps stored entries that no link holds for the freshness window, while an agent moves between servers. */
    private function carry(): void
    {
        $this->carryUntil = max($this->carryUntil, $this->now() + AgentStateView::FreshSeconds);
    }

    /** Closes a link and marks its Nodes for a new merge. Another open link carries them. */
    private function lose(AgentViewLink $link): void
    {
        if (count($this->links) > 1) {
            $this->carry();
        }

        foreach (array_keys($link->channels) as $nodeId) {
            $this->dirty[$nodeId] = true;
        }

        $link->reset();
    }

    private function connect(AgentViewLink $link): void
    {
        $credentials = $this->currentCredentials();

        if ($credentials === null || ! in_array($link->address, $credentials->addresses(), strict: true)) {
            $this->nextLinkCheckAt = $this->now();
            $link->nextConnectAt = $this->now() + self::LinkCheckSeconds;

            return;
        }

        $connection = $this->connectionData($credentials, $link->address);

        try {
            $link->socket->connect(new WebSocketEndpoint(
                address: $link->address,
                port: $this->reverbPort,
                serverName: WebSocketHostname::Value,
                path: '/app/'.rawurlencode($credentials->appKey).'?protocol=7&client=orbit-gateway&version=1.0&flash=false',
                caPath: $this->caPath,
            ), $this->isServingLink($link) ? self::ConnectTimeoutSeconds : self::OldServerConnectTimeoutSeconds);
            $socketId = $this->awaitConnectionEstablished($link);
        } catch (Throwable $exception) {
            $link->socket->close();
            $this->log->warning('The agent view subscriber could not connect to Reverb.', ['address' => $link->address, 'error' => $exception->getMessage()]);
            $this->scheduleReconnect($link);

            return;
        }

        $link->socketId = $socketId;
        $link->connection = $connection;
        $link->backoff = 0.0;
        $link->lastMessageAt = $this->now();
        $link->pingSentAt = null;
        $this->log->info('The agent view subscriber connected to Reverb.', ['address' => $link->address]);
        $this->refresh();
    }

    private function isServingLink(AgentViewLink $link): bool
    {
        return array_key_first($this->links) === $link->address;
    }

    private function awaitConnectionEstablished(AgentViewLink $link): string
    {
        $deadline = $this->now() + ($this->isServingLink($link) ? self::ConnectTimeoutSeconds : self::OldServerConnectTimeoutSeconds);

        while ($link->socket->isConnected() && $this->now() < $deadline) {
            foreach ($link->socket->receive(self::ReceiveWaitSeconds) as $message) {
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

    /** Joins every managed Node's channel on every live link, and leaves removed ones. */
    private function refresh(): void
    {
        $this->nextRefreshAt = $this->now() + self::RefreshSeconds;

        try {
            $this->nodeIds = Node::query()
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

        foreach ($this->links as $link) {
            if (! $link->isLive()) {
                continue;
            }

            foreach (array_diff(array_keys($link->channels), $this->nodeIds) as $nodeId) {
                $this->send($link, ['event' => 'pusher:unsubscribe', 'data' => ['channel' => "presence-node.{$nodeId}"]]);
                unset($link->channels[$nodeId], $link->snapshotRequestedAt[$nodeId]);
                $this->dirty[$nodeId] = true;
            }

            foreach (array_diff($this->nodeIds, array_keys($link->channels)) as $nodeId) {
                $this->subscribe($link, $nodeId);
            }
        }

        foreach (array_keys($this->stored) as $nodeId) {
            if (! in_array($nodeId, $this->nodeIds, strict: true)) {
                $this->forget($nodeId);
            }
        }
    }

    private function subscribe(AgentViewLink $link, int $nodeId): void
    {
        if ($link->socketId === null || $link->connection === null) {
            return;
        }

        if ($this->send($link, $this->subscription($nodeId, $link->socketId, $link->connection))) {
            $link->channels[$nodeId] = new AgentChannelState;
        }
    }

    /** @return array<string, mixed> */
    private function subscription(int $nodeId, string $socketId, RealtimeConnectionData $connection): array
    {
        $channel = "presence-node.{$nodeId}";
        $signature = $this->signer->sign($socketId, $channel, $connection, "gateway.{$socketId}", ['kind' => 'gateway']);

        return ['event' => 'pusher:subscribe', 'data' => ['channel' => $channel] + $signature];
    }

    /**
     * Leaves and joins again each channel on this link whose agent owes a complete snapshot. Reverb then
     * announces the subscriber as a new member, and every agent connection answers with a snapshot. The
     * state stays in place, so the Node turns fresh as soon as that snapshot is complete.
     */
    private function requestSnapshots(AgentViewLink $link): void
    {
        if ($link->socketId === null || $link->connection === null) {
            return;
        }

        foreach ($link->channels as $nodeId => $state) {
            $wantedSince = $state->snapshotWantedSince;

            if (
                $wantedSince === null
                || $this->now() - $wantedSince < self::SnapshotRequestSeconds
                || $this->now() - ($link->snapshotRequestedAt[$nodeId] ?? -INF) < self::SnapshotRequestSeconds
            ) {
                continue;
            }

            $link->snapshotRequestedAt[$nodeId] = $this->now();
            $state->snapshotRequested();
            $this->log->info('The agent view subscriber asks a Node agent for a complete snapshot.', ['node_id' => $nodeId, 'address' => $link->address]);

            if (
                ! $this->send($link, ['event' => 'pusher:unsubscribe', 'data' => ['channel' => "presence-node.{$nodeId}"]])
                || ! $this->send($link, $this->subscription($nodeId, $link->socketId, $link->connection))
            ) {
                return;
            }
        }
    }

    /** @param array<string, mixed> $message */
    private function handle(AgentViewLink $link, array $message): void
    {
        $link->lastMessageAt = $this->now();
        $link->pingSentAt = null;
        $event = $message['event'] ?? null;

        if ($event === 'pusher:ping') {
            $this->send($link, ['event' => 'pusher:pong', 'data' => []]);

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
        $state = $link->channels[$nodeId] ?? null;

        if ($state === null) {
            return;
        }

        $agent = "agent.{$nodeId}";
        $data = $this->data($message);

        if ($event === 'pusher_internal:subscription_succeeded') {
            $presence = is_array($data['presence'] ?? null) ? $data['presence'] : [];
            $ids = is_array($presence['ids'] ?? null) ? $presence['ids'] : [];

            if (! in_array($agent, $ids, strict: true)) {
                $state->reset();
                $this->dirty[$nodeId] = true;
            }

            return;
        }

        if (in_array($event, ['pusher_internal:member_added', 'pusher_internal:member_removed'], strict: true)) {
            // The agent joined again or left: its earlier state on this server no longer holds.
            if (($data['user_id'] ?? null) === $agent) {
                $state->reset();
                $this->dirty[$nodeId] = true;
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

    /**
     * Writes every changed Node once, from the link whose agent state has the newest event. A Node
     * without a complete snapshot on any link has no entry, unless a move carries its stored one.
     */
    private function flush(): void
    {
        foreach (array_keys($this->dirty) as $nodeId) {
            $newest = null;

            foreach ($this->links as $link) {
                $state = $link->channels[$nodeId] ?? null;

                if ($state !== null && $state->hasSnapshot && $state->lastEventAt !== null
                    && ($newest === null || $state->lastEventAt > $newest->lastEventAt)) {
                    $newest = $state;
                }
            }

            try {
                if ($newest === null) {
                    if ($this->now() >= $this->carryUntil) {
                        $this->forget($nodeId);
                    }

                    continue;
                }

                $this->view->putNode($nodeId, $newest->units, $newest->docker, $newest->sequence, (float) $newest->lastEventAt, $newest->agentAt);
                $this->stored[$nodeId] = true;
            } catch (Throwable $exception) {
                $this->log->warning('The agent view subscriber could not write the view.', ['node_id' => $nodeId, 'error' => $exception->getMessage()]);
            }
        }

        $this->dirty = [];
    }

    private function keepAlive(AgentViewLink $link): void
    {
        if (! $link->socket->isConnected()) {
            return;
        }

        if ($link->pingSentAt !== null) {
            if ($this->now() - $link->pingSentAt >= self::PongTimeoutSeconds) {
                $this->log->warning('Reverb did not answer the agent view subscriber\'s ping.', ['address' => $link->address]);
                $link->socket->close();
            }

            return;
        }

        if ($this->now() - $link->lastMessageAt >= self::PingAfterSeconds && $this->send($link, ['event' => 'pusher:ping', 'data' => []])) {
            $link->pingSentAt = $this->now();
        }
    }

    /** @param array<string, mixed> $message */
    private function send(AgentViewLink $link, array $message): bool
    {
        try {
            $link->socket->send($message);

            return true;
        } catch (Throwable $exception) {
            $this->log->warning('The agent view subscriber could not write to Reverb.', ['address' => $link->address, 'error' => $exception->getMessage()]);
            $link->socket->close();

            return false;
        }
    }

    /** Closes every link and clears the whole view, because nothing keeps it current anymore. */
    private function closeAll(): void
    {
        foreach ($this->links as $link) {
            $link->reset();
            $this->idleSockets[] = $link->socket;
        }

        $this->links = [];
        $this->dirty = [];
        $this->carryUntil = 0.0;

        foreach (array_keys($this->stored) as $nodeId) {
            $this->forget($nodeId);
        }
    }

    private function forget(int $nodeId): void
    {
        unset($this->dirty[$nodeId], $this->stored[$nodeId]);

        try {
            $this->view->forgetNode($nodeId);
        } catch (Throwable $exception) {
            $this->log->warning('The agent view subscriber could not clear the view.', ['node_id' => $nodeId, 'error' => $exception->getMessage()]);
        }
    }

    private function scheduleReconnect(AgentViewLink $link): void
    {
        $link->backoff = $link->backoff === 0.0 ? 1.0 : min(self::MaxBackoffSeconds, $link->backoff * 2);
        $jitter = $link->backoff * (mt_rand(0, 250) / 1000);
        $link->nextConnectAt = $this->now() + $link->backoff + $jitter;
    }

    private function writeHealth(bool $force = false): void
    {
        if (! $force && $this->now() < $this->nextHealthAt) {
            return;
        }

        $this->nextHealthAt = $this->now() + self::HealthSeconds;
        $connected = array_filter($this->links, static fn (AgentViewLink $link): bool => $link->isLive()) !== [];

        try {
            $this->view->putSubscriber($this->configured, $connected, count($this->joinedNodes()));
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

    private function connectionData(WebSocketCredentials $credentials, string $address): RealtimeConnectionData
    {
        return new RealtimeConnectionData(
            host: WebSocketHostname::Value,
            port: 443,
            scheme: 'https',
            appId: $credentials->appId,
            key: $credentials->appKey,
            secret: $credentials->appSecret,
            caCertificatePath: $this->caPath,
            resolveAddress: $address,
        );
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
