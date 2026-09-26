<?php

declare(strict_types=1);

use App\Domain\Logs\LogRelayCursor;
use App\Domain\Logs\LogStreamBroadcast;
use App\Domain\Logs\LogStreamStore;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Models\Activity;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

function log_stream_node(string $name, string $address): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => $address,
        'wireguard_ip' => $address,
        'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:'.$name,
    ]);
}

function log_stream_instance(Node $node, string $checkout = '/home/orbit/apps/shop/main'): AppInstance
{
    $app = OrbitApp::query()->firstOrCreate(['slug' => 'shop'], [
        'name' => 'Shop',
        'repository_url' => 'git@example.test:shop.git',
        'default_branch' => 'main',
    ]);

    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'main-'.$node->id.'-'.random_int(1, 1_000_000),
        'checkout_path' => $checkout,
        'status' => 'active',
    ]);
}

function log_stream_process(Node $node, string $runtime, string $name = 'queue'): Process
{
    return Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $node->id,
        'name' => $name,
        'runtime' => $runtime,
        'working_directory' => '/srv',
        'runtime_config' => $runtime === 'docker'
            ? ['image' => 'example/worker:1', 'environment' => ['WORKER_SECRET' => 'orbit-worker-secret-9931']]
            : ['command' => ['/usr/bin/worker']],
        'restart_policy' => 'always',
        'desired_state' => 'running',
        'status' => LifecycleStatus::Active,
    ]);
}

/** Makes the Node's agent fresh in the view, as a member of its log channel unless told otherwise. */
function log_stream_agent(Node $node, bool $logs = true, ?string $version = '0.3.0'): void
{
    $view = app(CacheAgentStateView::class);
    $view->putSubscriber(true, true, 2);
    $view->putNode((int) $node->id, [], 'available', 1, CacheAgentStateView::now(), null, [], $logs, $version);
}

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_000));
    [, $this->credentials] = activate_websocket_role();
    $this->serving = log_stream_node('app-prod', '10.44.0.11');
    $this->viewer = log_stream_node('laptop', '10.44.0.21');
    $this->viewer->accessibleNodes()->attach($this->serving->id);
    $this->instance = log_stream_instance($this->serving);
    log_stream_agent($this->serving);
    Event::fake([LogStreamBroadcast::class]);
    $this->as = fn (Node $node): static => $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $this->open = fn (array $body = ['socket_id' => '123.456'], ?Node $caller = null, ?string $url = null): TestResponse => ($this->as)($caller ?? $this->viewer)
        ->postJson($url ?? "/api/v1/instances/{$this->instance->id}/log-streams", $body);
});

afterEach(fn () => Carbon::setTestNow());

describe('opening a live log stream', function (): void {
    it('stores a stream for the Instance log and signs the viewer socket for its private channel only', function (): void {
        $response = ($this->open)(['socket_id' => '123.456', 'lines' => 500])->assertCreated();
        $id = $response->json('data.id');
        $channel = "private-log-stream.{$id}";

        expect($id)->toMatch('/\A[0-9a-f]{32}\z/')
            ->and($response->json('data'))->toBe([
                'id' => $id,
                'channel' => $channel,
                'auth' => $this->credentials->appKey.':'.hash_hmac('sha256', "123.456:{$channel}", $this->credentials->appSecret),
                'lines' => 500,
                'lease_seconds' => 60,
                'renew_seconds' => 20,
            ]);

        $stream = app(LogStreamStore::class)->find($id);

        expect($stream?->nodeId)->toBe($this->serving->id)
            ->and($stream?->viewerNodeId)->toBe($this->viewer->id)
            ->and($stream?->source->toArray())->toBe(['type' => 'laravel', 'path' => '/home/orbit/apps/shop/main']);

        // The agent starts only after the viewer subscribed and renewed once, so no line can arrive early.
        expect($stream?->active)->toBeFalse();
        Event::assertNotDispatched(LogStreamBroadcast::class);
    });

    it('resolves the source of a Process from its record, never from the request', function (): void {
        $systemd = log_stream_process($this->serving, 'systemd');
        $docker = log_stream_process($this->serving, 'docker', 'web');

        $journal = ($this->open)(['socket_id' => '1.2', 'path' => '/etc/shadow', 'unit' => 'ssh.service'], url: "/api/v1/processes/{$systemd->id}/log-streams")->assertCreated();
        $container = ($this->open)(['socket_id' => '1.2', 'container' => 'postgres'], url: "/api/v1/processes/{$docker->id}/log-streams")->assertCreated();

        expect(app(LogStreamStore::class)->find($journal->json('data.id'))?->source->toArray())
            ->toBe(['type' => 'journal', 'unit' => "orbit-process-{$systemd->id}-queue.service"])
            ->and(app(LogStreamStore::class)->find($container->json('data.id'))?->source->toArray())
            ->toBe(['type' => 'docker', 'container' => "orbit-process-{$docker->id}-web", 'process_id' => $docker->id]);
    });

    it('refuses an Instance whose recorded checkout path is not a normalized absolute path', function (string $checkout): void {
        $instance = log_stream_instance($this->serving, $checkout);

        ($this->open)(url: "/api/v1/instances/{$instance->id}/log-streams")
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'instance.checkout_path_invalid');

        expect(app(LogStreamStore::class)->all())->toBe([]);
    })->with(['traversal' => '/home/orbit/apps/../../etc', 'relative' => 'apps/shop', 'dot' => '/home/./orbit', 'control' => "/home/orbit/a\nb"]);

    it('requires an access edge to the Node that serves the record', function (): void {
        $stranger = log_stream_node('stranger', '10.44.0.31');

        ($this->open)(caller: $stranger)->assertForbidden()->assertJsonPath('error.code', 'node_access.required');

        expect(app(LogStreamStore::class)->all())->toBe([]);
        Event::assertNotDispatched(LogStreamBroadcast::class);
    });

    it('validates the socket id and the line count', function (array $body): void {
        ($this->open)($body)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    })->with([
        'missing socket' => [[]],
        'malformed socket' => [['socket_id' => 'private-log-stream.x']],
        'no lines' => [['socket_id' => '1.2', 'lines' => 0]],
        'too many lines' => [['socket_id' => '1.2', 'lines' => 1001]],
    ]);

    it('refuses with the reason when the live path is not available', function (Closure $arrange, string $reason): void {
        $arrange($this);

        ($this->open)()
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'logs.live_unavailable')
            ->assertJsonPath('error.details.reason', $reason);

        expect(app(LogStreamStore::class)->all())->toBe([]);
    })->with([
        'no websocket role' => [fn ($test) => Node::query()->where('name', 'websocket-node')->first()?->roles()->delete(), 'realtime_not_configured'],
        'subscriber down' => [fn ($test) => app(CacheAgentStateView::class)->forgetSubscriber(), 'subscriber_down'],
        'subscriber disconnected' => [fn ($test) => app(CacheAgentStateView::class)->putSubscriber(true, false, 0), 'subscriber_down'],
        'agent stopped' => [fn ($test) => app(CacheAgentStateView::class)->forgetNode($test->serving->id), 'agent_unavailable'],
        'agent stale' => [fn ($test) => Carbon::setTestNow(Carbon::createFromTimestamp(1_016)), 'agent_unavailable'],
        'agent before 0.3.0' => [fn ($test) => log_stream_agent($test->serving, logs: false, version: '0.2.0'), 'agent_outdated'],
        'agent 0.3.0 not joined yet' => [fn ($test) => log_stream_agent($test->serving, logs: false), 'agent_not_joined'],
        'agent of unknown version not joined yet' => [fn ($test) => log_stream_agent($test->serving, logs: false, version: null), 'agent_not_joined'],
    ]);

    it('sends a production Instance log to SSH reads before it checks the live path', function (Closure $arrange): void {
        $arrange($this);
        app(CacheAgentStateView::class)->forgetSubscriber();

        ($this->open)()
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'logs.live_unavailable')
            ->assertJsonPath('error.details.reason', 'ssh_only')
            ->assertJsonPath('error.message', 'A production Instance log is read over SSH only.');

        expect(app(LogStreamStore::class)->all())->toBe([]);
    })->with([
        'production Instance' => [fn ($test) => $test->instance->update(['environment' => 'production', 'production_home' => '/home/shop', 'checkout_path' => '/home/shop/releases/1'])],
        'app-prod Node' => [fn ($test) => $test->serving->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active])],
    ]);

    it('allows sixteen open streams for each serving Node', function (): void {
        foreach (range(1, 16) as $index) {
            ($this->open)(['socket_id' => "{$index}.1"])->assertCreated();
        }

        ($this->open)()->assertStatus(429)->assertJsonPath('error.code', 'logs.stream_limit');

        $other = log_stream_node('app-dev', '10.44.0.12');
        $this->viewer->accessibleNodes()->attach($other->id);
        log_stream_agent($other);

        ($this->open)(url: '/api/v1/instances/'.log_stream_instance($other)->id.'/log-streams')->assertCreated();
    });
});

describe('renewing and closing a live log stream', function (): void {
    beforeEach(function (): void {
        $this->stream = ($this->open)()->assertCreated()->json('data.id');
        $this->url = "/api/v1/instances/{$this->instance->id}/log-streams/{$this->stream}";
    });

    it('activates the stream and prompts the agent on the first renewal', function (): void {
        expect(app(LogStreamStore::class)->forNode($this->serving->id))->toBe([]);

        ($this->as)($this->viewer)->putJson($this->url)->assertOk();

        expect(array_map(static fn ($stream): string => $stream->id, app(LogStreamStore::class)->forNode($this->serving->id)))->toBe([$this->stream]);
        Event::assertDispatchedTimes(LogStreamBroadcast::class, 1);
        Event::assertDispatched(LogStreamBroadcast::class, fn (LogStreamBroadcast $event): bool => $event->channel === "presence-node-logs.{$this->serving->id}"
            && $event->name === 'log-streams.changed' && $event->payload === []);
    });

    it('prompts the agent again on each renewal until the stream relays its first line', function (): void {
        ($this->as)($this->viewer)->putJson($this->url)->assertOk();
        ($this->as)($this->viewer)->putJson($this->url)->assertOk();

        Event::assertDispatchedTimes(LogStreamBroadcast::class, 2);

        app(LogStreamStore::class)->saveCursor($this->stream, new LogRelayCursor('relay', 1, 0, 1));
        ($this->as)($this->viewer)->putJson($this->url)->assertOk();

        Event::assertDispatchedTimes(LogStreamBroadcast::class, 2);
    });

    it('extends the lease without recording Activity', function (): void {
        $activities = Activity::query()->count();
        Carbon::setTestNow(Carbon::createFromTimestamp(1_050));

        ($this->as)($this->viewer)->putJson($this->url)->assertOk()->assertJsonPath('data', ['id' => $this->stream, 'lease_seconds' => 60]);
        Carbon::setTestNow(Carbon::createFromTimestamp(1_100));

        expect(app(LogStreamStore::class)->find($this->stream))->not->toBeNull()
            ->and(Activity::query()->count())->toBe($activities);
    });

    it('ends a stream whose lease was not renewed', function (): void {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_060));

        expect(app(LogStreamStore::class)->find($this->stream))->toBeNull();
        ($this->as)($this->viewer)->putJson($this->url)->assertNotFound()->assertJsonPath('error.code', 'logs.stream_not_found');
    });

    it('closes the stream, tells the viewer, and prompts the agent', function (): void {
        ($this->as)($this->viewer)->deleteJson($this->url)->assertOk()->assertJsonPath('data', ['id' => $this->stream, 'closed' => true]);

        expect(app(LogStreamStore::class)->find($this->stream))->toBeNull();
        Event::assertDispatched(LogStreamBroadcast::class, fn (LogStreamBroadcast $event): bool => $event->channel === "private-log-stream.{$this->stream}"
            && $event->name === 'log.ended' && $event->payload['data'] === ['reason' => 'closed'] && $event->payload['id'] === $this->stream);
        ($this->as)($this->viewer)->deleteJson($this->url)->assertNotFound();
    });

    it('answers not found for another Node, another record, or an unknown stream', function (): void {
        $colleague = log_stream_node('colleague', '10.44.0.22');
        $colleague->accessibleNodes()->attach($this->serving->id);
        $otherInstance = log_stream_instance($this->serving, '/home/orbit/apps/shop/other');

        ($this->as)($colleague)->putJson($this->url)->assertNotFound()->assertJsonPath('error.code', 'logs.stream_not_found');
        ($this->as)($colleague)->deleteJson($this->url)->assertNotFound();
        ($this->as)($this->viewer)->putJson("/api/v1/instances/{$otherInstance->id}/log-streams/{$this->stream}")->assertNotFound();
        ($this->as)($this->viewer)->putJson("/api/v1/instances/{$this->instance->id}/log-streams/".str_repeat('0', 32))->assertNotFound();

        expect(app(LogStreamStore::class)->find($this->stream))->not->toBeNull();
    });

    it('refuses a renewal from a viewer whose access edge was removed', function (): void {
        $this->viewer->accessibleNodes()->detach();

        ($this->as)($this->viewer)->putJson($this->url)->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    });
});

describe('browser channel authorization', function (): void {
    it('never signs a live log stream channel or a Node log channel', function (string $channel): void {
        $gateway = log_stream_node('gateway', '10.44.0.1');
        $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
        $stream = ($this->open)()->assertCreated()->json('data.id');

        ($this->as)($gateway)
            ->postJson('/api/v1/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => str_replace('{stream}', $stream, $channel)])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'broadcast.channel_forbidden');
    })->with(['stream channel' => 'private-log-stream.{stream}', 'log channel' => 'presence-node-logs.2']);
});
