<?php

declare(strict_types=1);

use App\Actions\Broadcasting\PresenceChannelSigner;
use App\Domain\AgentView\AgentStateView;
use App\Domain\AgentView\AgentViewFreshness;
use App\Domain\Logs\LogStreamBroadcast;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Infrastructure\AgentView\AgentViewPublisher;
use App\Infrastructure\AgentView\AgentViewSubscriber;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AgentView\WebSocketException;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Models\Node;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Psr\Log\NullLogger;

final class FakeAgentViewSocket implements WebSocketClient
{
    /** @var list<WebSocketEndpoint> */
    public array $connects = [];

    /** @var list<array<string, mixed>> */
    public array $sent = [];

    /** @var list<list<array<string, mixed>>> Messages each receive() returns, in order. */
    public array $inbox = [];

    public bool $connected = false;

    public bool $refuse = false;

    public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
    {
        $this->connects[] = $endpoint;

        if ($this->refuse) {
            throw new WebSocketException('refused');
        }

        $this->connected = true;
        $this->inbox = [[[
            'event' => 'pusher:connection_established',
            'data' => json_encode(['socket_id' => '1234.5678', 'activity_timeout' => 30]),
        ]], ...$this->inbox];
    }

    public function send(array $message): void
    {
        if (! $this->connected) {
            throw new WebSocketException('closed');
        }

        $this->sent[] = $message;
    }

    public function receive(float $timeoutSeconds): array
    {
        return $this->connected ? (array_shift($this->inbox) ?? []) : [];
    }

    public function close(): void
    {
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    /** @param array<string, mixed> ...$messages */
    public function push(array ...$messages): void
    {
        $this->inbox[] = array_values($messages);
    }

    /** @return list<string> */
    public function events(): array
    {
        return array_map(static fn (array $message): string => (string) $message['event'], $this->sent);
    }
}

function subscriber_managed_node(string $name, string $address): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.30',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => $address,
        'ssh_host_fingerprint' => 'SHA256:'.$name,
    ]);
}

/**
 * @param  list<FakeAgentViewSocket>  $extra  Receives each socket the subscriber makes for a second link.
 * @return array{AgentViewSubscriber, FakeAgentViewSocket, object{now: float, commit: string}}
 */
function agent_view_subscriber(array &$extra = [], ?AgentViewPublisher $publisher = null): array
{
    $socket = new FakeAgentViewSocket;
    $state = new class
    {
        public float $now = 1_000.0;

        public string $commit = 'aaa';
    };
    Carbon::setTestNow(Carbon::createFromTimestamp($state->now));

    $subscriber = new AgentViewSubscriber(
        socket: $socket,
        credentials: app(WebSocketCredentialManager::class),
        view: app(CacheAgentStateView::class),
        signer: new PresenceChannelSigner,
        log: new NullLogger,
        caPath: '/home/orbit/.orbit/ca/root.pem',
        commit: static fn (): string => $state->commit,
        clock: static fn (): float => $state->now,
        sleep: static function (float $seconds) use ($state): void {
            $state->now += $seconds;
        },
        publisher: $publisher,
        sockets: static function () use (&$extra): FakeAgentViewSocket {
            return $extra[] = new FakeAgentViewSocket;
        },
    );

    return [$subscriber, $socket, $state];
}

final class FakeAgentViewPublisher implements AgentViewPublisher
{
    /** @var list<array{int, list<int>}> */
    public array $workspaces = [];

    /** @var list<int> */
    public array $usage = [];

    public int $polls = 0;

    public bool $stopped = false;

    /** @var list<array{int, string, array<string, mixed>}> */
    public array $logs = [];

    /** @var list<int> */
    public array $agentsLeft = [];

    public int $streamsChanged = 0;

    public function queueWorkspaces(int $nodeId, array $instanceIds): void
    {
        $this->workspaces[] = [$nodeId, $instanceIds];
    }

    public function queueUsage(int $sampledAt): void
    {
        $this->usage[] = $sampledAt;
    }

    public function queueLog(int $nodeId, string $event, array $data): void
    {
        $this->logs[] = [$nodeId, $event, $data];
    }

    public function queueLogAgentLeft(int $nodeId): void
    {
        $this->agentsLeft[] = $nodeId;
    }

    public function logStreamsChanged(): void
    {
        $this->streamsChanged++;
    }

    public function poll(): void
    {
        $this->polls++;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }
}

/** @return array{AgentViewSubscriber, FakeAgentViewSocket, object{now: float, commit: string}, FakeAgentViewPublisher} */
function live_agent_view_subscriber(): array
{
    $socket = new FakeAgentViewSocket;
    $publisher = new FakeAgentViewPublisher;
    $state = new class
    {
        public float $now = 1_000.0;

        public string $commit = 'aaa';
    };
    Carbon::setTestNow(Carbon::createFromTimestamp($state->now));

    $subscriber = new AgentViewSubscriber(
        socket: $socket,
        credentials: app(WebSocketCredentialManager::class),
        view: app(CacheAgentStateView::class),
        signer: new PresenceChannelSigner,
        log: new NullLogger,
        caPath: '/home/orbit/.orbit/ca/root.pem',
        commit: static fn (): string => $state->commit,
        clock: static fn (): float => $state->now,
        sleep: static function (float $seconds) use ($state): void {
            $state->now += $seconds;
        },
        publisher: $publisher,
    );

    return [$subscriber, $socket, $state, $publisher];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function agent_workspace(int $instanceId, array $overrides = []): array
{
    return [
        'instance_id' => $instanceId, 'base' => 'main', 'start' => null, 'branch' => 'task-1', 'head' => str_repeat('b', 40),
        'dirty' => false, 'commits' => null, 'diff' => ['files' => 2, 'added' => 30, 'removed' => 4, 'truncated' => false], ...$overrides,
    ];
}

/** @param array<string, mixed> $data */
function agent_event(int $nodeId, string $event, array $data, ?string $sender = null): array
{
    return [
        'event' => $event,
        'channel' => "presence-node.{$nodeId}",
        'data' => json_encode($data),
        'user_id' => $sender ?? "agent.{$nodeId}",
    ];
}

function agent_snapshot(int $nodeId, int $sequence, array $units, ?string $sender = null): array
{
    return agent_event($nodeId, 'client-snapshot', [
        'sequence' => $sequence,
        'at' => '2026-09-25T10:00:00Z',
        'docker' => 'available',
        'part' => 1,
        'parts' => 1,
        'units' => $units,
    ], $sender);
}

describe('the agent view subscriber', function (): void {
    afterEach(fn () => Carbon::setTestNow());

    it('waits without connecting while no websocket role is active', function (): void {
        [$subscriber, $socket] = agent_view_subscriber();

        $subscriber->pass();

        expect($socket->connects)->toBe([])
            ->and(app(AgentStateView::class)->subscriber()?->configured)->toBeFalse();
    });

    it('joins every managed Node over one connection with its own signed gateway membership', function (): void {
        [, $credentials] = activate_websocket_role();
        $managed = subscriber_managed_node('app-dev', '10.44.0.3');
        $laptop = Node::query()->create([
            'name' => 'laptop',
            'status' => LifecycleStatus::Active,
            'platform' => 'darwin',
            'public_ssh_host' => '192.0.2.40',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.40',
        ]);
        [$subscriber, $socket] = agent_view_subscriber();

        $subscriber->pass();

        $endpoint = $socket->connects[0];
        $subscribe = collect($socket->sent)->firstWhere('data.channel', "presence-node.{$managed->id}");
        $channelData = '{"user_id":"gateway.1234.5678","user_info":{"kind":"gateway"}}';

        expect($socket->connects)->toHaveCount(1)
            ->and($endpoint->address)->toBe('10.44.0.90')
            ->and($endpoint->serverName)->toBe('reverb.orbit')
            ->and($endpoint->path)->toStartWith('/app/'.$credentials->appKey.'?protocol=7')
            ->and($subscriber->joinedNodes())->toContain($managed->id)
            ->and($subscriber->joinedNodes())->not->toContain($laptop->id)
            ->and($subscribe['event'])->toBe('pusher:subscribe')
            ->and($subscribe['data']['channel_data'])->toBe($channelData)
            ->and($subscribe['data']['auth'])->toBe(
                $credentials->appKey.':'.hash_hmac('sha256', "1234.5678:presence-node.{$managed->id}:{$channelData}", $credentials->appSecret),
            );
    });

    it('stores a complete snapshot from the Node agent and nothing from other members', function (): void {
        activate_websocket_role();
        $node = subscriber_managed_node('app-dev', '10.44.0.3');
        [$subscriber, $socket] = agent_view_subscriber();
        $subscriber->pass();

        $socket->push(agent_snapshot($node->id, 1, [
            ['name' => 'orbit-process-9-web', 'runtime' => 'systemd', 'runtime_status' => 'failed'],
        ], sender: 'viewer.99.1'));
        $subscriber->pass();

        expect(app(AgentStateView::class)->node($node->id)->freshness)->toBe(AgentViewFreshness::Missing);

        $socket->push(agent_snapshot($node->id, 1, [
            ['name' => 'orbit-process-9-web', 'runtime' => 'systemd', 'runtime_status' => 'active'],
        ]));
        $subscriber->pass();

        $view = app(AgentStateView::class)->node($node->id);
        expect($view->freshness)->toBe(AgentViewFreshness::Fresh)
            ->and($view->status(ProcessRuntime::Systemd, 'orbit-process-9-web'))->toBe('active');

        $socket->push(agent_event($node->id, 'client-process', [
            'sequence' => 2,
            'unit' => ['name' => 'orbit-process-9-web', 'runtime' => 'systemd', 'runtime_status' => 'inactive'],
        ]));
        $subscriber->pass();

        expect(app(AgentStateView::class)->node($node->id)->status(ProcessRuntime::Systemd, 'orbit-process-9-web'))->toBe('inactive');
    });

    it('keeps a Node missing until a complete snapshot arrives, whatever heartbeats come first', function (): void {
        activate_websocket_role();
        $node = subscriber_managed_node('app-dev', '10.44.0.3');
        [$subscriber, $socket] = agent_view_subscriber();
        $subscriber->pass();

        $socket->push(agent_event($node->id, 'client-heartbeat', ['sequence' => 1]));
        $subscriber->pass();

        expect(app(AgentStateView::class)->node($node->id)->freshness)->toBe(AgentViewFreshness::Missing);

        $socket->push(
            agent_event($node->id, 'client-snapshot', ['sequence' => 2, 'docker' => 'available', 'part' => 1, 'parts' => 2, 'units' => []]),
            agent_event($node->id, 'client-heartbeat', ['sequence' => 3]),
        );
        $subscriber->pass();

        expect(app(AgentStateView::class)->node($node->id)->freshness)->toBe(AgentViewFreshness::Missing);
    });

    it('joins the channel again when agent events keep arriving without a complete snapshot', function (): void {
        activate_websocket_role();
        $node = subscriber_managed_node('app-dev', '10.44.0.3');
        [$subscriber, $socket, $state] = agent_view_subscriber();
        $subscriber->pass();
        $sent = count($socket->sent);

        // The agent's snapshot never arrived, for example because it was lost with a dropped connection.
        $socket->push(agent_event($node->id, 'client-heartbeat', ['sequence' => 30]));
        $subscriber->pass();
        $state->now += AgentViewSubscriber::SnapshotRequestSeconds - 1;
        $subscriber->pass();

        expect(array_slice($socket->sent, $sent))->toBe([]);

        $state->now += 1;
        $subscriber->pass();
        $requests = array_slice($socket->sent, $sent);

        expect(array_column($requests, 'event'))->toBe(['pusher:unsubscribe', 'pusher:subscribe'])
            ->and(array_column(array_column($requests, 'data'), 'channel'))->toBe(["presence-node.{$node->id}", "presence-node.{$node->id}"])
            ->and($requests[1]['data']['channel_data'])->toBe('{"user_id":"gateway.1234.5678","user_info":{"kind":"gateway"}}');

        // Still no snapshot: it asks again, but not more than once every few seconds.
        $sent = count($socket->sent);
        $socket->push(agent_event($node->id, 'client-heartbeat', ['sequence' => 31]));
        $subscriber->pass();
        $state->now += AgentViewSubscriber::SnapshotRequestSeconds - 1;
        $subscriber->pass();

        expect(array_slice($socket->sent, $sent))->toBe([]);

        $state->now += 1;
        $subscriber->pass();

        expect(array_column(array_slice($socket->sent, $sent), 'event'))->toBe(['pusher:unsubscribe', 'pusher:subscribe']);

        $socket->push(agent_snapshot($node->id, 32, [
            ['name' => 'orbit-process-9-web', 'runtime' => 'systemd', 'runtime_status' => 'active'],
        ]));
        $subscriber->pass();

        expect(app(AgentStateView::class)->node($node->id)->status(ProcessRuntime::Systemd, 'orbit-process-9-web'))->toBe('active');
    });

    it('recovers a Node after a second connection published as the same agent member', function (): void {
        activate_websocket_role();
        $node = subscriber_managed_node('app-dev', '10.44.0.3');
        [$subscriber, $socket, $state] = agent_view_subscriber();
        $subscriber->pass();
        $running = [['name' => 'orbit-process-9-web', 'runtime' => 'docker', 'runtime_status' => 'running']];
        $socket->push(agent_snapshot($node->id, 40, $running));
        $subscriber->pass();

        // A second agent process joins as `agent.{id}`. Reverb announces no member and relays both streams.
        $socket->push(
            agent_event($node->id, 'client-snapshot', ['sequence' => 1, 'docker' => 'absent', 'part' => 1, 'parts' => 1, 'units' => []]),
            agent_event($node->id, 'client-heartbeat', ['sequence' => 41]),
            agent_event($node->id, 'client-heartbeat', ['sequence' => 2]),
        );
        $subscriber->pass();

        expect(app(AgentStateView::class)->node($node->id)->freshness)->toBe(AgentViewFreshness::Missing);

        // The second process exits. Reverb announces nothing, and the agent sends only heartbeats.
        $sent = count($socket->sent);
        $state->now += AgentViewSubscriber::SnapshotRequestSeconds;
        $socket->push(agent_event($node->id, 'client-heartbeat', ['sequence' => 42]));
        $subscriber->pass();

        expect(array_column(array_slice($socket->sent, $sent), 'event'))->toBe(['pusher:unsubscribe', 'pusher:subscribe']);

        $socket->push(agent_snapshot($node->id, 43, $running));
        $subscriber->pass();

        $view = app(AgentStateView::class)->node($node->id);
        expect($view->freshness)->toBe(AgentViewFreshness::Fresh)
            ->and($view->status(ProcessRuntime::Docker, 'orbit-process-9-web'))->toBe('running');
    });

    it('asks for a confirming snapshot when the last one came from a second connection', function (): void {
        activate_websocket_role();
        $node = subscriber_managed_node('app-dev', '10.44.0.3');
        [$subscriber, $socket, $state] = agent_view_subscriber();
        $subscriber->pass();
        $running = [['name' => 'orbit-process-9-web', 'runtime' => 'docker', 'runtime_status' => 'running']];
        $socket->push(agent_snapshot($node->id, 40, $running));
        $subscriber->pass();

        // The second process's snapshot is complete but wrong: it had not reached Docker yet.
        $socket->push(agent_event($node->id, 'client-snapshot', ['sequence' => 1, 'docker' => 'absent', 'part' => 1, 'parts' => 1, 'units' => []]));
        $subscriber->pass();
        $sent = count($socket->sent);
        $state->now += AgentViewSubscriber::SnapshotRequestSeconds;
        $subscriber->pass();

        expect(array_column(array_slice($socket->sent, $sent), 'event'))->toBe(['pusher:unsubscribe', 'pusher:subscribe']);

        $socket->push(agent_snapshot($node->id, 41, $running));
        $subscriber->pass();

        expect(app(AgentStateView::class)->node($node->id)->status(ProcessRuntime::Docker, 'orbit-process-9-web'))->toBe('running');
    });

    it('does not ask again while every snapshot arrives', function (): void {
        activate_websocket_role();
        $node = subscriber_managed_node('app-dev', '10.44.0.3');
        [$subscriber, $socket, $state] = agent_view_subscriber();
        $subscriber->pass();
        $socket->push(agent_snapshot($node->id, 1, []));
        $subscriber->pass();
        $sent = count($socket->sent);

        foreach (range(2, 6) as $sequence) {
            $state->now += 5;
            $socket->push(agent_event($node->id, 'client-heartbeat', ['sequence' => $sequence]));
            $subscriber->pass();
        }

        expect(array_slice($socket->sent, $sent))->toBe([]);
    });

    it('reports itself connected as soon as it reconnects', function (): void {
        activate_websocket_role();
        subscriber_managed_node('app-dev', '10.44.0.3');
        [$subscriber, $socket, $state] = agent_view_subscriber();
        $subscriber->pass();
        $socket->close();
        $subscriber->pass();

        expect(app(AgentStateView::class)->subscriber()?->connected)->toBeFalse();

        $state->now += 1.5;
        $subscriber->pass();

        expect($socket->isConnected())->toBeTrue()
            ->and(app(AgentStateView::class)->subscriber()?->connected)->toBeTrue();
    });

    it('drops a Node view when its agent leaves the channel', function (): void {
        activate_websocket_role();
        $node = subscriber_managed_node('app-dev', '10.44.0.3');
        [$subscriber, $socket] = agent_view_subscriber();
        $subscriber->pass();
        $socket->push(agent_snapshot($node->id, 1, []));
        $subscriber->pass();

        $socket->push([
            'event' => 'pusher_internal:member_removed',
            'channel' => "presence-node.{$node->id}",
            'data' => json_encode(['user_id' => "agent.{$node->id}"]),
        ]);
        $subscriber->pass();

        expect(app(AgentStateView::class)->node($node->id)->freshness)->toBe(AgentViewFreshness::Missing);
    });

    it('answers Reverb pings and pings Reverb after thirty quiet seconds', function (): void {
        activate_websocket_role();
        subscriber_managed_node('app-dev', '10.44.0.3');
        [$subscriber, $socket, $state] = agent_view_subscriber();
        $subscriber->pass();

        $socket->push(['event' => 'pusher:ping', 'data' => []]);
        $subscriber->pass();
        $state->now += AgentViewSubscriber::PingAfterSeconds;
        $subscriber->pass();

        expect(array_slice($socket->events(), -2))->toBe(['pusher:pong', 'pusher:ping']);

        $state->now += AgentViewSubscriber::PongTimeoutSeconds;
        $subscriber->pass();

        expect($socket->isConnected())->toBeFalse();
    });

    it('clears the whole view when the connection drops and reconnects with backoff', function (): void {
        activate_websocket_role();
        $node = subscriber_managed_node('app-dev', '10.44.0.3');
        [$subscriber, $socket, $state] = agent_view_subscriber();
        $subscriber->pass();
        $socket->push(agent_snapshot($node->id, 1, []));
        $subscriber->pass();

        $socket->close();
        $socket->refuse = true;
        $subscriber->pass();

        expect(app(AgentStateView::class)->node($node->id)->freshness)->toBe(AgentViewFreshness::Missing)
            ->and($socket->connects)->toHaveCount(1);

        $state->now += 2;
        $subscriber->pass();
        $socket->refuse = false;
        $state->now += 3;
        $subscriber->pass();

        expect($socket->connects)->toHaveCount(3)
            ->and($socket->isConnected())->toBeTrue()
            ->and($subscriber->joinedNodes())->toContain($node->id);
    });

    it('joins a new Node and leaves a removed one on the next refresh', function (): void {
        activate_websocket_role();
        $first = subscriber_managed_node('app-dev', '10.44.0.3');
        [$subscriber, $socket, $state] = agent_view_subscriber();
        $subscriber->pass();
        $second = subscriber_managed_node('app-prod', '10.44.0.4');
        $first->update(['status' => LifecycleStatus::Removing]);

        $state->now += AgentViewSubscriber::RefreshSeconds;
        $subscriber->pass();

        expect($subscriber->joinedNodes())->toContain($second->id)
            ->and($subscriber->joinedNodes())->not->toContain($first->id)
            ->and(collect($socket->sent)->contains(
                static fn (array $message): bool => $message['event'] === 'pusher:unsubscribe'
                    && $message['data']['channel'] === "presence-node.{$first->id}",
            ))->toBeTrue();
    });

    it('stops when the Gateway checkout changes and leaves the view empty', function (): void {
        activate_websocket_role();
        $node = subscriber_managed_node('app-dev', '10.44.0.3');
        [$subscriber, $socket, $state] = agent_view_subscriber();
        $passes = 0;

        $reason = $subscriber->run(static function () use (&$passes, $socket, $state, $node): bool {
            $passes++;

            if ($passes === 3) {
                $socket->push(agent_snapshot($node->id, 1, []));
            }

            if ($passes === 5) {
                $state->commit = 'bbb';
                $state->now += AgentViewSubscriber::CommitCheckSeconds;
            }

            return $passes > 20;
        });

        expect($reason)->toBe('commit_changed')
            ->and($socket->isConnected())->toBeFalse()
            ->and(app(AgentStateView::class)->node($node->id)->freshness)->toBe(AgentViewFreshness::Missing)
            ->and(app(AgentStateView::class)->subscriber())->toBeNull();
    });

    it('keeps the task workspaces an agent reports and drops malformed ones', function (): void {
        activate_websocket_role();
        $node = subscriber_managed_node('app-dev', '10.44.0.3');
        [$subscriber, $socket] = live_agent_view_subscriber();
        $subscriber->pass();

        $socket->push(
            agent_snapshot($node->id, 1, []),
            agent_event($node->id, 'client-workspaces', ['sequence' => 2, 'part' => 1, 'parts' => 1, 'workspaces' => [
                agent_workspace(31), agent_workspace(32, ['head' => 'not-a-commit']), agent_workspace(33, ['diff' => ['files' => -1, 'added' => 0, 'removed' => 0]]),
            ]]),
        );
        $subscriber->pass();

        $view = app(AgentStateView::class)->node($node->id);
        expect($view->workspace(31))->toBe(agent_workspace(31))
            ->and($view->workspace(32))->toBeNull()
            ->and($view->workspace(33))->toBeNull();

        $socket->push(agent_event($node->id, 'client-workspace', ['sequence' => 3, 'workspace' => agent_workspace(31, ['dirty' => true])]));
        $socket->push(agent_event($node->id, 'client-workspaces', ['sequence' => 4, 'part' => 1, 'parts' => 1, 'workspaces' => []], sender: 'viewer.1.2'));
        $subscriber->pass();
        $subscriber->pass();

        expect(app(AgentStateView::class)->node($node->id)->workspace(31)['dirty'] ?? null)->toBeTrue();
    });

    it('hands a new head or new counts to the publisher and nothing else', function (): void {
        activate_websocket_role();
        $node = subscriber_managed_node('app-dev', '10.44.0.3');
        [$subscriber, $socket, , $publisher] = live_agent_view_subscriber();
        $subscriber->pass();

        $socket->push(
            agent_snapshot($node->id, 1, []),
            agent_event($node->id, 'client-workspaces', ['sequence' => 2, 'part' => 1, 'parts' => 1, 'workspaces' => [agent_workspace(31)]]),
        );
        $subscriber->pass();
        $socket->push(agent_event($node->id, 'client-workspace', ['sequence' => 3, 'workspace' => agent_workspace(31, ['dirty' => true])]));
        $subscriber->pass();
        $socket->push(agent_event($node->id, 'client-workspace', ['sequence' => 4, 'workspace' => agent_workspace(31, ['head' => str_repeat('c', 40), 'diff' => null])]));
        $subscriber->pass();

        expect($publisher->workspaces)->toBe([[$node->id, [31]], [$node->id, [31]]])
            ->and($publisher->polls)->toBeGreaterThan(0);
    });

    it('writes the view again after a failed write and still hands its workspace changes to the publisher', function (): void {
        $cache = new class(new ArrayStore) extends CacheRepository
        {
            public int $failures = 1;

            public function put($key, $value, $ttl = null): bool
            {
                if (str_starts_with((string) $key, 'agent-view.node.') && $this->failures-- > 0) {
                    throw new RuntimeException('No space left on device.');
                }

                return parent::put($key, $value, $ttl);
            }
        };
        app()->instance(CacheAgentStateView::class, new CacheAgentStateView($cache));
        activate_websocket_role();
        $node = subscriber_managed_node('app-dev', '10.44.0.3');
        [$subscriber, $socket, , $publisher] = live_agent_view_subscriber();
        $subscriber->pass();

        $socket->push(
            agent_snapshot($node->id, 1, []),
            agent_event($node->id, 'client-workspaces', ['sequence' => 2, 'part' => 1, 'parts' => 1, 'workspaces' => [agent_workspace(31)]]),
        );
        $subscriber->pass();

        expect($cache->failures)->toBe(0)
            ->and($publisher->workspaces)->toBe([]);

        $subscriber->pass();

        expect($publisher->workspaces)->toBe([[$node->id, [31]]])
            ->and(app(CacheAgentStateView::class)->node($node->id)->workspace(31)['head'] ?? null)->toBe(str_repeat('b', 40));
    });

    it('queues Process usage every fifteen seconds only while a browser watches', function (): void {
        activate_websocket_role();
        $node = subscriber_managed_node('app-dev', '10.44.0.3');
        [$subscriber, $socket, $state, $publisher] = live_agent_view_subscriber();
        $subscriber->pass();
        $subscriber->pass();

        expect($publisher->usage)->toBe([]);

        $socket->push([
            'event' => 'pusher_internal:subscription_succeeded',
            'channel' => "presence-node.{$node->id}",
            'data' => json_encode(['presence' => ['ids' => ["agent.{$node->id}", 'viewer.77.1'], 'hash' => [], 'count' => 2]]),
        ]);
        $state->now += 15;
        $subscriber->pass();
        $state->now += 5;
        $subscriber->pass();

        expect($publisher->usage)->toBe([(int) $state->now - 5]);

        $socket->push(['event' => 'pusher_internal:member_removed', 'channel' => "presence-node.{$node->id}", 'data' => json_encode(['user_id' => 'viewer.77.1'])]);
        $state->now += 15;
        $subscriber->pass();

        expect($publisher->usage)->toHaveCount(1)
            ->and($subscriber->hasViewers())->toBeFalse();
    });

    it('stops a running publish when it stops', function (): void {
        [$subscriber, , , $publisher] = live_agent_view_subscriber();

        $subscriber->run(static fn (): bool => true);

        expect($publisher->stopped)->toBeTrue();
    });

    it('keeps polling the publisher while it has no Reverb connection', function (): void {
        [$subscriber, $socket, , $publisher] = live_agent_view_subscriber();
        $socket->refuse = true;

        $subscriber->pass();
        $subscriber->pass();

        expect($socket->isConnected())->toBeFalse()
            ->and($publisher->polls)->toBe(2);
    });
});

describe('the agent view subscriber during a websocket move', function (): void {
    afterEach(fn () => Carbon::setTestNow());

    beforeEach(function (): void {
        $this->source = subscriber_managed_node('websocket-source', '10.44.0.89');
        new CaddySiteCertificates()->record($this->source->id, CaddySiteCertificates::Websocket);
        [$this->target] = activate_websocket_role();
        new CaddySiteCertificates()->record($this->target->id, CaddySiteCertificates::Websocket);
        new WebSocketDnsTarget()->markServing($this->target->id);
        $this->node = subscriber_managed_node('app-dev', '10.44.0.3');
    });

    it('joins the log channel on both servers and ends log streams only when the agent left both', function (): void {
        $extra = [];
        $publisher = new FakeAgentViewPublisher;
        [$subscriber, $new] = agent_view_subscriber($extra, $publisher);
        $subscriber->pass();
        $old = $extra[0];
        $logChannel = "presence-node-logs.{$this->node->id}";
        $member = fn (string $event): array => ['event' => $event, 'channel' => $logChannel, 'data' => json_encode(['user_id' => "agent.{$this->node->id}"])];

        expect(collect($new->sent)->pluck('data.channel')->all())->toContain($logChannel)
            ->and(collect($old->sent)->pluck('data.channel')->all())->toContain($logChannel);

        $old->push(['event' => 'pusher_internal:subscription_succeeded', 'channel' => $logChannel, 'data' => json_encode(['presence' => ['ids' => ["agent.{$this->node->id}"]]])]);
        $old->push(agent_snapshot($this->node->id, 3, []));
        $subscriber->pass();
        $subscriber->pass();
        expect(app(AgentStateView::class)->node($this->node->id)->logs)->toBeTrue();

        // The agent moves: it joins the new server before the old one reports it gone.
        $new->push($member('pusher_internal:member_added'));
        $subscriber->pass();
        $old->push($member('pusher_internal:member_removed'));
        $subscriber->pass();

        expect($publisher->agentsLeft)->toBe([]);

        $new->push($member('pusher_internal:member_removed'));
        $subscriber->pass();

        expect($publisher->agentsLeft)->toBe([$this->node->id]);
    });

    it('listens on both Reverb servers and keeps a Node fresh while its agent moves between them', function (): void {
        $extra = [];
        [$subscriber, $new] = agent_view_subscriber($extra);
        $subscriber->pass();
        $old = $extra[0];

        expect($subscriber->linkedAddresses())->toBe(['10.44.0.90', '10.44.0.89'])
            ->and($new->connects[0]->address)->toBe('10.44.0.90')
            ->and($old->connects[0]->address)->toBe('10.44.0.89');

        $old->push(agent_snapshot($this->node->id, 7, [
            ['name' => 'orbit-process-9-web', 'runtime' => 'systemd', 'runtime_status' => 'active'],
        ]));
        $subscriber->pass();

        expect(app(AgentStateView::class)->node($this->node->id)->freshness)->toBe(AgentViewFreshness::Fresh);

        // The old server closes the agent's connection; the agent has not reached the new one yet.
        $old->push([
            'event' => 'pusher_internal:member_removed',
            'channel' => "presence-node.{$this->node->id}",
            'data' => json_encode(['user_id' => "agent.{$this->node->id}"]),
        ]);
        $subscriber->pass();

        expect(app(AgentStateView::class)->node($this->node->id)->freshness)->toBe(AgentViewFreshness::Fresh);

        $new->push(agent_snapshot($this->node->id, 1, [
            ['name' => 'orbit-process-9-web', 'runtime' => 'systemd', 'runtime_status' => 'inactive'],
        ]));
        $subscriber->pass();

        $view = app(AgentStateView::class)->node($this->node->id);
        expect($view->freshness)->toBe(AgentViewFreshness::Fresh)
            ->and($view->status(ProcessRuntime::Systemd, 'orbit-process-9-web'))->toBe('inactive');
    });

    it('counts a browser watching on either server as a viewer', function (): void {
        $extra = [];
        [$subscriber] = agent_view_subscriber($extra);
        $subscriber->pass();
        $old = $extra[0];

        expect($subscriber->hasViewers())->toBeFalse();

        $old->push([
            'event' => 'pusher_internal:member_added',
            'channel' => "presence-node.{$this->node->id}",
            'data' => json_encode(['user_id' => 'viewer.7.1']),
        ]);
        $subscriber->pass();

        expect($subscriber->hasViewers())->toBeTrue();
    });

    it('takes the state with the newest agent event when both servers hold one', function (): void {
        $extra = [];
        [$subscriber, $new, $state] = agent_view_subscriber($extra);
        $subscriber->pass();
        $old = $extra[0];

        $new->push(agent_snapshot($this->node->id, 3, [
            ['name' => 'orbit-process-9-web', 'runtime' => 'systemd', 'runtime_status' => 'active'],
        ]));
        $subscriber->pass();
        $state->now += 2;
        $old->push(agent_snapshot($this->node->id, 9, [
            ['name' => 'orbit-process-9-web', 'runtime' => 'systemd', 'runtime_status' => 'failed'],
        ]));
        $subscriber->pass();

        expect(app(AgentStateView::class)->node($this->node->id)->status(ProcessRuntime::Systemd, 'orbit-process-9-web'))->toBe('failed');
    });

    it('closes the old link once the old Node withdraws, and keeps the stored view while the agent reconnects', function (): void {
        $extra = [];
        [$subscriber, $new, $state] = agent_view_subscriber($extra);
        $subscriber->pass();
        $old = $extra[0];
        $old->push(agent_snapshot($this->node->id, 4, []));
        $subscriber->pass();

        new CaddySiteCertificates()->forget($this->source->id, CaddySiteCertificates::Websocket);
        new WebSocketDnsTarget()->forget($this->source->id);
        $state->now += AgentViewSubscriber::LinkCheckSeconds;
        $subscriber->pass();

        expect($subscriber->linkedAddresses())->toBe(['10.44.0.90'])
            ->and($old->isConnected())->toBeFalse()
            ->and(app(AgentStateView::class)->node($this->node->id)->freshness)->toBe(AgentViewFreshness::Fresh);

        $new->push(agent_snapshot($this->node->id, 1, []));
        $subscriber->pass();

        expect(app(AgentStateView::class)->node($this->node->id)->freshness)->toBe(AgentViewFreshness::Fresh);
    });
});

/** @param array<string, mixed> $data */
function agent_log_event(int $nodeId, string $event, array $data, ?string $sender = null): array
{
    return ['event' => $event, 'channel' => "presence-node-logs.{$nodeId}", 'data' => json_encode($data), 'user_id' => $sender ?? "agent.{$nodeId}"];
}

describe('the live log channels', function (): void {
    beforeEach(function (): void {
        activate_websocket_role();
        $this->node = subscriber_managed_node('app-dev', '10.44.0.3');
        [$this->subscriber, $this->socket, $this->clock, $this->publisher] = live_agent_view_subscriber();
        $this->subscriber->pass();
        $this->socket->push(agent_log_event($this->node->id, 'pusher_internal:subscription_succeeded', ['presence' => ['ids' => ["agent.{$this->node->id}", 'gateway.1234.5678']]]));
        $this->socket->push(agent_snapshot($this->node->id, 1, []));
        $this->subscriber->pass();
        $this->subscriber->pass();
    });

    afterEach(fn () => Carbon::setTestNow());

    it('joins every Node log channel with its own gateway membership and records the agent as a member', function (): void {
        $subscribe = collect($this->socket->sent)->firstWhere('data.channel', "presence-node-logs.{$this->node->id}");

        expect($subscribe['event'])->toBe('pusher:subscribe')
            ->and(json_decode($subscribe['data']['channel_data'], true))->toBe(['user_id' => 'gateway.1234.5678', 'user_info' => ['kind' => 'gateway']])
            ->and(app(AgentStateView::class)->node($this->node->id)->logs)->toBeTrue();
    });

    it('queues the agent log events in order and never relays them inside the socket loop', function (): void {
        Event::fake([LogStreamBroadcast::class]);
        $stream = str_repeat('ab', 16);
        $this->socket->push(
            agent_log_event($this->node->id, 'client-log', ['stream' => $stream, 'sequence' => 1, 'dropped' => 0, 'skipped' => 0, 'lines' => ['one']]),
            agent_log_event($this->node->id, 'client-log', ['stream' => $stream, 'sequence' => 2, 'dropped' => 0, 'skipped' => 0, 'lines' => ['two']]),
            agent_log_event($this->node->id, 'client-log-end', ['stream' => $stream, 'reason' => 'source_unavailable']),
        );
        $this->subscriber->pass();

        expect(array_map(static fn (array $log): array => [$log[0], $log[1], $log[2]['lines'] ?? null], $this->publisher->logs))->toBe([
            [$this->node->id, 'client-log', ['one']],
            [$this->node->id, 'client-log', ['two']],
            [$this->node->id, 'client-log-end', null],
        ]);
        Event::assertNotDispatched(LogStreamBroadcast::class);
    });

    it('ignores log events from another member, for another Node, or on the view channel', function (): void {
        $other = subscriber_managed_node('app-dev-2', '10.44.0.4');
        $this->subscriber->pass();
        $line = ['stream' => str_repeat('ab', 16), 'sequence' => 1, 'dropped' => 0, 'skipped' => 0, 'lines' => ['forged']];

        $this->socket->push(
            agent_log_event($this->node->id, 'client-log', $line, sender: 'viewer.9.9'),
            agent_log_event($this->node->id, 'client-log', $line, sender: "agent.{$other->id}"),
            agent_event($this->node->id, 'client-log', $line),
            agent_log_event($this->node->id, 'client-heartbeat', $line),
        );
        $this->subscriber->pass();

        expect($this->publisher->logs)->toBe([]);
    });

    it('queues the end of the Node streams when its agent leaves the log channel', function (): void {
        $this->socket->push(agent_log_event($this->node->id, 'pusher_internal:member_removed', ['user_id' => "agent.{$this->node->id}"]));
        $this->subscriber->pass();

        expect($this->publisher->agentsLeft)->toBe([$this->node->id])
            ->and(app(AgentStateView::class)->node($this->node->id)->logs)->toBeFalse();
    });

    it('records the agent version from its Node channel membership', function (): void {
        $agent = "agent.{$this->node->id}";
        $this->socket->push(agent_event($this->node->id, 'pusher_internal:subscription_succeeded', ['presence' => [
            'ids' => [$agent],
            'hash' => [$agent => ['kind' => 'agent', 'node_id' => $this->node->id, 'version' => '0.2.0']],
        ]], sender: ''));
        $this->socket->push(agent_snapshot($this->node->id, 2, []));
        $this->subscriber->pass();
        $this->subscriber->pass();

        expect(app(AgentStateView::class)->node($this->node->id)->agentVersion)->toBe('0.2.0');

        $this->socket->push(
            agent_event($this->node->id, 'pusher_internal:member_added', ['user_id' => $agent, 'user_info' => ['kind' => 'agent', 'version' => '0.3.0']], sender: ''),
            agent_snapshot($this->node->id, 1, []),
        );
        $this->subscriber->pass();
        $this->subscriber->pass();

        expect(app(AgentStateView::class)->node($this->node->id)->agentVersion)->toBe('0.3.0');

        $this->socket->push(
            agent_event($this->node->id, 'pusher_internal:member_added', ['user_id' => $agent, 'user_info' => ['version' => 'not a version!']], sender: ''),
            agent_snapshot($this->node->id, 1, []),
        );
        $this->subscriber->pass();
        $this->subscriber->pass();

        expect(app(AgentStateView::class)->node($this->node->id)->agentVersion)->toBeNull();
    });

    it('tells the publisher on connect and when the Gateway prompts an agent about its streams', function (): void {
        // Streams may have opened while no subscriber watched.
        expect($this->publisher->streamsChanged)->toBe(1);

        $this->socket->push(['event' => 'log-streams.changed', 'channel' => "presence-node-logs.{$this->node->id}", 'data' => '{}']);
        $this->subscriber->pass();

        expect($this->publisher->streamsChanged)->toBe(2);
    });
});
