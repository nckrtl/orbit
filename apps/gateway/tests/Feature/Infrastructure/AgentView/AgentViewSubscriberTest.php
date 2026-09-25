<?php

declare(strict_types=1);

use App\Actions\Broadcasting\PresenceChannelSigner;
use App\Domain\AgentView\AgentStateView;
use App\Domain\AgentView\AgentViewFreshness;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Infrastructure\AgentView\AgentViewSubscriber;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AgentView\WebSocketException;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Models\Node;
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
function agent_view_subscriber(array &$extra = []): array
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
        sockets: static function () use (&$extra): FakeAgentViewSocket {
            return $extra[] = new FakeAgentViewSocket;
        },
    );

    return [$subscriber, $socket, $state];
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
