<?php

declare(strict_types=1);

use App\Domain\Logs\LogStream;
use App\Domain\Logs\LogStreamBroadcast;
use App\Domain\Logs\LogStreamBroadcaster;
use App\Domain\Logs\LogStreamRecordType;
use App\Domain\Logs\LogStreamSource;
use App\Domain\Logs\LogStreamStore;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentView\LogRelay;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceEnvironmentValue;
use App\Models\Node;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Testing\Fakes\EventFake;

function relay_node(string $name, string $address): Node
{
    return Node::query()->create([
        'name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.30', 'user' => 'orbit', 'wireguard_ip' => $address,
    ]);
}

/**
 * @param  list<array<string, mixed>>  $items
 * @return array{relay: string, items: list<array<string, mixed>>, sweep: bool, prompt: bool}
 */
function relay_batch(array $items, bool $sweep = false, string $relay = 'relay-1', bool $prompt = false): array
{
    return ['relay' => $relay, 'items' => $items, 'sweep' => $sweep, 'prompt' => $prompt];
}

/**
 * @param  list<string>  $lines
 * @return array<string, mixed>
 */
function relay_lines(int $item, int $node, string $stream, array $lines, int $dropped = 0, int $skipped = 0): array
{
    return ['type' => 'lines', 'item' => $item, 'node' => $node, 'stream' => $stream, 'lines' => $lines, 'dropped' => $dropped, 'skipped' => $skipped];
}

/** @return list<LogStreamBroadcast> */
function relayed(string $name): array
{
    return array_values(array_filter(
        Event::dispatched(LogStreamBroadcast::class)->map(static fn (array $call): LogStreamBroadcast => $call[0])->all(),
        static fn (LogStreamBroadcast $event): bool => $event->name === $name,
    ));
}

/** @return list<string> Every relayed line, in order. */
function relayed_lines(): array
{
    return array_merge(...array_map(static fn (LogStreamBroadcast $event): array => $event->payload['data']['lines'], relayed('log.lines')));
}

describe('a live log relay run', function (): void {
    beforeEach(function (): void {
        activate_websocket_role();
        Carbon::setTestNow(Carbon::createFromTimestamp(1_000));
        Event::fake([LogStreamBroadcast::class]);
        $this->node = relay_node('app-dev', '10.44.0.3');
        $this->viewer = relay_node('laptop', '10.44.0.21');
        $this->viewer->accessibleNodes()->attach($this->node->id);
        $app = OrbitApp::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'git@example.test:shop.git', 'default_branch' => 'main']);
        $this->instance = AppInstance::query()->create(['app_id' => $app->id, 'node_id' => $this->node->id, 'name' => 'main', 'checkout_path' => '/home/orbit/apps/shop/main', 'status' => 'active']);
        AppInstanceEnvironmentValue::query()->create(['app_instance_id' => $this->instance->id, 'env_key' => 'STRIPE_SECRET', 'env_value' => 'sk-live-orbit-4821-secret']);
        $this->stream = new LogStream(str_repeat('ab', 16), LogStreamRecordType::Instance, (int) $this->instance->id, (int) $this->node->id, (int) $this->viewer->id, LogStreamSource::laravel(StoragePath::parse('/home/orbit/apps/shop/main')), 100, 1_060.0);
        app(LogStreamStore::class)->open($this->stream);
        $this->relay = app(LogRelay::class);
    });

    afterEach(fn () => Carbon::setTestNow());

    it('relays redacted lines from the Node agent to the viewer channel', function (): void {
        $open = $this->relay->relay(relay_batch([relay_lines(1, $this->node->id, $this->stream->id, [
            '[2026-09-25 10:00:00] production.ERROR: charge failed with sk-live-orbit-4821-secret', 'Authorization: Bearer abcdefgh12345678', 'DB_PASSWORD=hunter2hunter2',
        ], dropped: 2)]));

        $lines = relayed('log.lines');

        expect($open)->toBe(1)
            ->and($lines)->toHaveCount(1)
            ->and($lines[0]->channel)->toBe("private-log-stream.{$this->stream->id}")
            ->and($lines[0]->payload['type'])->toBe('log.lines')
            ->and($lines[0]->payload['id'])->toBe($this->stream->id)
            ->and($lines[0]->payload['data'])->toBe([
                'sequence' => 1,
                'lines' => ['[2026-09-25 10:00:00] production.ERROR: charge failed with [REDACTED]', 'Authorization: [REDACTED]', 'DB_PASSWORD=[REDACTED]'],
                'dropped' => 2,
                'skipped' => 0,
            ]);
    });

    it('ignores lines for a stream of another Node or for an unknown stream', function (): void {
        $other = relay_node('app-dev-2', '10.44.0.4');
        $foreign = new LogStream(str_repeat('cd', 16), LogStreamRecordType::Instance, (int) $this->instance->id, (int) $other->id, (int) $this->viewer->id, LogStreamSource::laravel(StoragePath::parse('/srv/app')), 100, 1_060.0);
        app(LogStreamStore::class)->open($foreign);

        $this->relay->relay(relay_batch([
            relay_lines(1, $this->node->id, $foreign->id, ['forged']),
            relay_lines(2, $this->node->id, str_repeat('ef', 16), ['forged']),
        ]));

        expect(relayed('log.lines'))->toBe([]);
    });

    it('splits parts under the message limit and numbers them in order', function (): void {
        $this->relay->relay(relay_batch([relay_lines(1, $this->node->id, $this->stream->id, array_fill(0, 32, str_repeat('x', 8_000)), dropped: 8)]));

        $events = relayed('log.lines');

        expect(count(relayed_lines()))->toBe(32)
            ->and($events[0]->payload['data']['dropped'])->toBe(8)
            ->and($events[1]->payload['data']['dropped'])->toBe(0)
            ->and(array_map(static fn (LogStreamBroadcast $event): int => $event->payload['data']['sequence'], $events))->toBe(range(1, count($events)))
            ->and(array_all($events, static fn (LogStreamBroadcast $event): bool => strlen((string) json_encode($event->payload)) <= LogStreamBroadcaster::PayloadLimit))->toBeTrue();
    });

    it('keeps every Reverb request under 10,000 bytes when lines hold quotes', function (): void {
        $quoted = array_map(static fn (int $i): string => sprintf('{"n":%d,"msg":"%s"}', $i, str_repeat('\\"', 20)), range(1, 400));
        $this->relay->relay(relay_batch([relay_lines(1, $this->node->id, $this->stream->id, [...$quoted, str_repeat('"', 8_000)])]));

        $events = relayed('log.lines');
        $lines = relayed_lines();
        // The Reverb HTTP API body: the event JSON as an escaped string, with the event name and channel.
        $bodies = array_map(static fn (LogStreamBroadcast $event): int => strlen((string) json_encode([
            'name' => $event->name, 'data' => (string) json_encode($event->payload), 'channels' => [$event->channel],
        ])), $events);

        expect(count($lines))->toBe(401)
            ->and(array_slice($lines, 0, 400))->toBe($quoted)
            ->and($lines[400])->toEndWith('" [truncated]')
            ->and(max($bodies))->toBeLessThanOrEqual(8_700);
    });

    it('keeps the order of items and continues the sequence across runs', function (): void {
        $this->relay->relay(relay_batch([relay_lines(1, $this->node->id, $this->stream->id, ['one']), relay_lines(2, $this->node->id, $this->stream->id, ['two'])]));
        $this->relay->relay(relay_batch([relay_lines(3, $this->node->id, $this->stream->id, ['three'])]));

        expect(relayed_lines())->toBe(['one', 'two', 'three'])
            ->and(array_map(static fn (LogStreamBroadcast $event): int => $event->payload['data']['sequence'], relayed('log.lines')))->toBe([1, 2, 3]);
    });

    it('fails on a refused publish and, run again, publishes each line once in order', function (): void {
        $refusing = new class(app('events'), [LogStreamBroadcast::class]) extends EventFake
        {
            public int $refusals = 1;

            public function dispatch($event, $payload = [], $halt = false)
            {
                if ($event instanceof LogStreamBroadcast && $event->name === 'log.lines' && $event->payload['data']['lines'] === ['two'] && $this->refusals-- > 0) {
                    throw new BroadcastException('Reverb refused the event.');
                }

                return parent::dispatch($event, $payload, $halt);
            }
        };
        Event::swap($refusing);
        $batch = relay_batch([relay_lines(1, $this->node->id, $this->stream->id, ['one']), relay_lines(2, $this->node->id, $this->stream->id, ['two']), relay_lines(3, $this->node->id, $this->stream->id, ['three'])]);

        expect(fn () => $this->relay->relay($batch))->toThrow(BroadcastException::class)
            ->and(relayed_lines())->toBe(['one']);

        $this->relay->relay($batch);

        expect(array_map(static fn (LogStreamBroadcast $event): array => [$event->payload['data']['sequence'], $event->payload['data']['lines']], relayed('log.lines')))
            ->toBe([[1, ['one']], [2, ['two']], [3, ['three']]]);
    });

    it('starts counting items again for a new subscriber without reusing a sequence', function (): void {
        $this->relay->relay(relay_batch([relay_lines(7, $this->node->id, $this->stream->id, ['before'])], relay: 'relay-1'));
        $this->relay->relay(relay_batch([relay_lines(1, $this->node->id, $this->stream->id, ['after'])], relay: 'relay-2'));

        expect(array_map(static fn (LogStreamBroadcast $event): array => [$event->payload['data']['sequence'], $event->payload['data']['lines']], relayed('log.lines')))
            ->toBe([[1, ['before']], [2, ['after']]]);
    });

    it('ends the stream with the reason the queue gave, closes it, and prompts the agent', function (string $reason): void {
        $this->relay->relay(relay_batch([['type' => 'end', 'item' => 1, 'node' => $this->node->id, 'stream' => $this->stream->id, 'reason' => $reason]]));

        expect(relayed('log.ended')[0]->payload['data'])->toBe(['reason' => $reason])
            ->and(app(LogStreamStore::class)->find($this->stream->id))->toBeNull()
            ->and(collect(relayed('log-streams.changed'))->pluck('channel')->all())->toBe(["presence-node-logs.{$this->node->id}"]);
    })->with(['source_unavailable', 'relay_behind']);

    it('delivers the lines queued before an agent left, then ends every stream of that Node', function (): void {
        $this->relay->relay(relay_batch([
            relay_lines(1, $this->node->id, $this->stream->id, ['last words']),
            ['type' => 'agent_left', 'item' => 2, 'node' => $this->node->id],
        ]));

        expect(relayed_lines())->toBe(['last words'])
            ->and(relayed('log.ended')[0]->payload['data'])->toBe(['reason' => 'agent_left'])
            ->and(app(LogStreamStore::class)->find($this->stream->id))->toBeNull();
    });

    it('ends a stream whose record is gone with source_unavailable', function (): void {
        $this->instance->delete();

        $this->relay->relay(relay_batch([relay_lines(1, $this->node->id, $this->stream->id, ['orphan'])]));

        expect(relayed('log.lines'))->toBe([])
            ->and(relayed('log.ended')[0]->payload['data'])->toBe(['reason' => 'source_unavailable']);
    });

    it('ends a stream when its lease runs out or its viewer loses access, when asked to sweep', function (): void {
        $revoked = new LogStream(str_repeat('cd', 16), LogStreamRecordType::Instance, (int) $this->instance->id, (int) $this->node->id, (int) $this->viewer->id, LogStreamSource::laravel(StoragePath::parse('/home/orbit/apps/shop/main')), 100, 1_200.0);
        app(LogStreamStore::class)->open($revoked);
        Carbon::setTestNow(Carbon::createFromTimestamp(1_061));
        $this->viewer->accessibleNodes()->detach();

        expect($this->relay->relay(relay_batch([])))->toBe(1)
            ->and(relayed('log.ended'))->toBe([]);

        $open = $this->relay->relay(relay_batch([], sweep: true));
        $ended = collect(relayed('log.ended'))->mapWithKeys(static fn (LogStreamBroadcast $event): array => [$event->payload['id'] => $event->payload['data']['reason']])->all();

        expect($open)->toBe(0)
            ->and($ended)->toBe([$this->stream->id => 'expired', $revoked->id => 'revoked'])
            ->and(app(LogStreamStore::class)->all())->toBe([])
            ->and(collect(relayed('log-streams.changed'))->pluck('channel')->all())->toContain("presence-node-logs.{$this->node->id}");
    });

    it('prompts a Node again when asked, while an active stream there has relayed no line yet', function (): void {
        $other = relay_node('app-dev-2', '10.44.0.4');
        $this->viewer->accessibleNodes()->attach($other->id);
        $quiet = new LogStream(str_repeat('cd', 16), LogStreamRecordType::Instance, (int) $this->instance->id, (int) $other->id, (int) $this->viewer->id, LogStreamSource::laravel(StoragePath::parse('/home/orbit/apps/shop/main')), 100, 1_060.0);
        app(LogStreamStore::class)->open($quiet);
        app(LogStreamStore::class)->renew($quiet->id, 1_060.0);
        app(LogStreamStore::class)->renew($this->stream->id, 1_060.0);
        $this->relay->relay(relay_batch([relay_lines(1, $this->node->id, $this->stream->id, ['started'])]));

        $this->relay->relay(relay_batch([], sweep: true));
        expect(relayed('log-streams.changed'))->toBe([]);

        $this->relay->relay(relay_batch([], sweep: true, prompt: true));
        expect(collect(relayed('log-streams.changed'))->pluck('channel')->all())->toBe(["presence-node-logs.{$other->id}"]);
    });

    it('does not prompt for a stream that is not active yet', function (): void {
        $this->relay->relay(relay_batch([], sweep: true, prompt: true));

        expect(relayed('log-streams.changed'))->toBe([]);
    });

    it('fails when no websocket role is active instead of dropping the lines', function (): void {
        Node::query()->where('name', 'websocket-node')->sole()->roles()->delete();
        app()->forgetScopedInstances();

        expect(fn () => app(LogRelay::class)->relay(relay_batch([relay_lines(1, $this->node->id, $this->stream->id, ['kept'])])))
            ->toThrow(RuntimeException::class, 'No websocket role is active.');
    });
});
