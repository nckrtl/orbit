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
 *
 * ADR 0151 adds three duties. It keeps each agent's task workspaces in the view, has a task group's
 * line counts stored when its workspace reports new ones, and, while a browser (`viewer.*` member) is
 * on any channel, has every Process's CPU and memory broadcast every `UsageSeconds`. The last two run
 * in a child process through `AgentViewPublisher`, so the socket loop never waits for them.
 *
 * ADR 0153 adds the log channel `presence-node-logs.{id}` of every Node. The subscriber records
 * whether the agent joined it on any server and queues the agent's log events with the publisher,
 * whose log relay runs redact and publish them for the viewers of live log streams. It never sends a
 * client event there either.
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

    public const int UsageSeconds = 15;

    private const float MaxBackoffSeconds = 30.0;

    private const string CHANNEL = '/\Apresence-node\.([1-9][0-9]*)\z/D';

    private const string LOG_CHANNEL = '/\Apresence-node-logs\.([1-9][0-9]*)\z/D';

    private const string VERSION = '/\A[0-9A-Za-z.+-]{1,32}\z/D';

    /** @var array<string, AgentViewLink> Links keyed by Reverb address, the serving address first. */
    private array $links = [];

    /** @var list<WebSocketClient> Sockets of closed links, ready for the next link. */
    private array $idleSockets = [];

    /** @var array<int, true> Nodes whose stored view changed in this pass. */
    private array $dirty = [];

    /** @var array<int, list<int>> Changed workspaces of each Node whose view write failed, for the next write. */
    private array $unwrittenWorkspaces = [];

    /** @var array<int, true> Nodes with a stored entry this subscriber wrote. */
    private array $stored = [];

    /** @var list<int> Node ids to join, from the last refresh. */
    private array $nodeIds = [];

    private ?string $credentialsFingerprint = null;

    private bool $configured = false;

    private float $carryUntil = 0.0;

    private float $nextUsageAt = 0.0;

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
        private readonly ?AgentViewPublisher $publisher = null,
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
            $this->publisher?->stop();
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
        // Runs started before a disconnect still meet their deadline, and queued work keeps retrying.
        $this->publisher?->poll();

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
        $this->queueUsage();
        $this->publisher?->poll();

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

    /** Whether any browser is subscribed to a joined channel on any server. */
    public function hasViewers(): bool
    {
        return array_any($this->links, fn ($link) => array_any($link->viewers, static fn (array $members): bool => $members !== []));
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
        // Streams may have opened, or lost their viewer's access, while no subscriber watched.
        $this->publisher?->logStreamsChanged();
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
                $this->send($link, ['event' => 'pusher:unsubscribe', 'data' => ['channel' => "presence-node-logs.{$nodeId}"]]);
                unset($link->channels[$nodeId], $link->snapshotRequestedAt[$nodeId], $link->viewers[$nodeId], $link->logMembers[$nodeId], $link->agentVersions[$nodeId]);
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

        if (! $this->send($link, $this->subscription($nodeId, $link->socketId, $link->connection))) {
            return;
        }

        $link->channels[$nodeId] = new AgentChannelState;
        $logChannel = "presence-node-logs.{$nodeId}";
        $signature = $this->signer->sign($link->socketId, $logChannel, $link->connection, "gateway.{$link->socketId}", ['kind' => 'gateway']);
        $this->send($link, ['event' => 'pusher:subscribe', 'data' => ['channel' => $logChannel] + $signature]);
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

        if (is_string($event) && is_string($channel) && preg_match(self::LOG_CHANNEL, $channel, $matches) === 1) {
            $this->handleLogChannel($link, (int) $matches[1], $event, $message);

            return;
        }

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
            $link->viewers[$nodeId] = [];

            foreach ($ids as $id) {
                if (is_string($id) && str_starts_with($id, 'viewer.')) {
                    $link->viewers[$nodeId][$id] = true;
                }
            }

            if (! in_array($agent, $ids, strict: true)) {
                $state->reset();
                $this->dirty[$nodeId] = true;
            }

            $hash = is_array($presence['hash'] ?? null) ? $presence['hash'] : [];
            $this->setAgentVersion($link, $nodeId, is_array($hash[$agent] ?? null) ? $hash[$agent] : null);

            return;
        }

        if (in_array($event, ['pusher_internal:member_added', 'pusher_internal:member_removed'], strict: true)) {
            $member = $data['user_id'] ?? null;

            // The agent joined again or left: its earlier state on this server no longer holds.
            if ($member === $agent) {
                $state->reset();
                $this->dirty[$nodeId] = true;
                $info = $data['user_info'] ?? null;
                $this->setAgentVersion($link, $nodeId, $event === 'pusher_internal:member_added' && is_array($info) ? $info : null);
            } elseif (is_string($member) && str_starts_with($member, 'viewer.')) {
                if ($event === 'pusher_internal:member_added') {
                    $link->viewers[$nodeId][$member] = true;
                } else {
                    unset($link->viewers[$nodeId][$member]);
                }
            }

            return;
        }

        // Reverb stamps every client event on a presence channel with the sender's signed member ID.
        if (
            in_array($event, ['client-heartbeat', 'client-snapshot', 'client-process', 'client-workspaces', 'client-workspace'], strict: true)
            && ($message['user_id'] ?? null) === $agent
            && $state->apply($event, $data, $this->now())
        ) {
            $this->dirty[$nodeId] = true;
        }
    }

    /**
     * Keeps the agent's log channel membership and queues its log events for the relay (ADR 0153).
     * Reverb stamps a client event with the sender's member ID, so only `agent.{id}` can send lines
     * for Node `{id}`. Nothing here waits: the relay runs outside the socket loop.
     *
     * @param  array<string, mixed>  $message
     */
    private function handleLogChannel(AgentViewLink $link, int $nodeId, string $event, array $message): void
    {
        if (! isset($link->channels[$nodeId])) {
            return;
        }

        $agent = "agent.{$nodeId}";
        $data = $this->data($message);

        if ($event === 'pusher_internal:subscription_succeeded') {
            $presence = is_array($data['presence'] ?? null) ? $data['presence'] : [];
            $this->setLogMember($link, $nodeId, in_array($agent, is_array($presence['ids'] ?? null) ? $presence['ids'] : [], strict: true));

            return;
        }

        if (in_array($event, ['pusher_internal:member_added', 'pusher_internal:member_removed'], strict: true)) {
            if (($data['user_id'] ?? null) === $agent) {
                $this->setLogMember($link, $nodeId, $event === 'pusher_internal:member_added');
            }

            return;
        }

        // The Gateway's own prompt to the agent: a stream opened, was renewed for the first time, or closed.
        if ($event === 'log-streams.changed') {
            $this->publisher?->logStreamsChanged();

            return;
        }

        if (($message['user_id'] ?? null) === $agent && in_array($event, ['client-log', 'client-log-end'], strict: true)) {
            $this->publisher?->queueLog($nodeId, $event, $data);
        }
    }

    /**
     * Records the version the agent signed into its membership of `presence-node.{id}` on one server, so
     * the Gateway can tell an agent before 0.3.0 from one that has not joined its log channel yet.
     *
     * @param  array<mixed>|null  $info  The member's `user_info`, or null when the agent is not a member.
     */
    private function setAgentVersion(AgentViewLink $link, int $nodeId, ?array $info): void
    {
        $version = $info['version'] ?? null;

        if (is_string($version) && preg_match(self::VERSION, $version) === 1) {
            $link->agentVersions[$nodeId] = $version;
        } else {
            unset($link->agentVersions[$nodeId]);
        }
    }

    /** The newest agent version any live server reports for the Node, or null when none does. */
    private function agentVersion(int $nodeId): ?string
    {
        $newest = null;

        foreach ($this->links as $link) {
            $version = $link->agentVersions[$nodeId] ?? null;

            if ($version !== null && ($newest === null || version_compare($version, $newest, '>'))) {
                $newest = $version;
            }
        }

        return $newest;
    }

    /** Records the agent's log channel membership on one server. The Node streams while any server has it. */
    private function setLogMember(AgentViewLink $link, int $nodeId, bool $member): void
    {
        $was = $this->logMember($nodeId);
        $link->logMembers[$nodeId] = $member;
        $now = $this->logMember($nodeId);

        if ($was !== $now) {
            $this->dirty[$nodeId] = true;
        }

        if ($was && ! $now) {
            $this->publisher?->queueLogAgentLeft($nodeId);
        }
    }

    /** Whether the Node's agent is a member of its log channel on any live server. */
    private function logMember(int $nodeId): bool
    {
        return array_any($this->links, static fn (AgentViewLink $link): bool => $link->logMembers[$nodeId] ?? false);
    }

    /**
     * Writes every changed Node once, from the link whose agent state has the newest event. A Node
     * without a complete snapshot on any link has no entry, unless a move carries its stored one.
     */
    private function flush(): void
    {
        $retry = [];

        foreach (array_keys($this->dirty) as $nodeId) {
            $newest = null;
            $states = [];

            foreach ($this->links as $link) {
                $state = $link->channels[$nodeId] ?? null;

                if ($state === null) {
                    continue;
                }

                $states[] = $state;

                if ($state->hasSnapshot && $state->lastEventAt !== null
                    && ($newest === null || $state->lastEventAt > $newest->lastEventAt)) {
                    $newest = $state;
                }
            }

            // Only the newest server's workspace changes count; the other server's are older or the same.
            $changed = [];

            foreach ($states as $state) {
                $taken = $state->takeChangedWorkspaces();

                if ($state === $newest) {
                    $changed = $taken;
                }
            }

            try {
                if ($newest === null) {
                    if ($this->now() >= $this->carryUntil) {
                        $this->forget($nodeId);
                    }

                    continue;
                }

                $changed = array_values(array_unique([...($this->unwrittenWorkspaces[$nodeId] ?? []), ...$changed]));
                $this->view->putNode($nodeId, $newest->units, $newest->docker, $newest->sequence, (float) $newest->lastEventAt, $newest->agentAt, $newest->workspaces, $this->logMember($nodeId), $this->agentVersion($nodeId));
                $this->stored[$nodeId] = true;
                unset($this->unwrittenWorkspaces[$nodeId]);

                // The publisher reads the stored workspaces and stores the counts of the groups whose `head`
                // or diff changed.
                if ($changed !== []) {
                    $this->publisher?->queueWorkspaces($nodeId, $changed);
                }
            } catch (Throwable $exception) {
                $this->log->warning('The agent view subscriber could not write the view.', ['node_id' => $nodeId, 'error' => $exception->getMessage()]);

                // Keep the changes and write the Node again on the next pass, so a failed write loses no update.
                if ($newest !== null) {
                    $this->unwrittenWorkspaces[$nodeId] = $changed;
                    $retry[$nodeId] = true;
                }
            }
        }

        $this->dirty = $retry;
    }

    /** Queues a Process usage sample every `UsageSeconds` while a browser watches. It never waits for it. */
    private function queueUsage(): void
    {
        $live = array_filter($this->links, static fn (AgentViewLink $link): bool => $link->isLive());

        if ($this->publisher === null || $live === [] || $this->now() < $this->nextUsageAt) {
            return;
        }

        $this->nextUsageAt = $this->now() + self::UsageSeconds;

        if ($this->hasViewers()) {
            $this->publisher->queueUsage((int) $this->now());
        }
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
        $this->unwrittenWorkspaces = [];
        $this->carryUntil = 0.0;

        foreach (array_keys($this->stored) as $nodeId) {
            $this->forget($nodeId);
        }
    }

    private function forget(int $nodeId): void
    {
        unset($this->dirty[$nodeId], $this->stored[$nodeId], $this->unwrittenWorkspaces[$nodeId]);

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
