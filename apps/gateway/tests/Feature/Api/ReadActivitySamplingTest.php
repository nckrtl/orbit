<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Http\Middleware\RecordCommandActivity;
use App\Models\Activity;
use App\Models\Node;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]));
    $this->operator = Node::query()->create([
        'name' => 'operator',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $this->operator->accessibleNodes()->attach($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
});

describe('read activity sampling', function (): void {
    it('keeps one successful read per command and caller in each window', function (): void {
        Carbon::setTestNow('2026-09-25 10:00:00');
        $first = (string) Str::uuid();

        $this->withHeader('X-Orbit-Request-Id', $first)->getJson('/api/v1/nodes')->assertOk();
        Carbon::setTestNow('2026-09-25 10:00:30');
        $this->getJson('/api/v1/nodes')->assertOk();
        $this->getJson('/api/v1/nodes')->assertOk();

        $rows = Activity::query()->where('command', 'node:list')->get();

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->request_id)->toBe($first)
            ->and($rows[0]->status)->toBe('succeeded')
            ->and($rows[0]->caller_node_id)->toBe($this->gateway->id)
            ->and($rows[0]->duration_ms)->not->toBeNull()
            ->and($rows[0]->created_at?->toDateTimeString())->toBe('2026-09-25 10:00:00');

        Carbon::setTestNow(Carbon::parse('2026-09-25 10:00:00')->addSeconds(RecordCommandActivity::READ_SAMPLE_SECONDS + 1));
        $this->getJson('/api/v1/nodes')->assertOk();

        expect(Activity::query()->where('command', 'node:list')->count())->toBe(2);
    });

    it('samples each command and each caller on its own', function (): void {
        $this->getJson('/api/v1/nodes')->assertOk();
        $this->getJson('/api/v1/clusters')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2'])->getJson('/api/v1/nodes')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2'])->getJson('/api/v1/nodes')->assertOk();

        expect(Activity::query()->where('command', 'node:list')->pluck('caller_ip')->all())
            ->toBe(['10.44.0.1', '10.44.0.2'])
            ->and(Activity::query()->where('command', 'cluster:list')->count())->toBe(1);
    });

    it('keeps one successful read per target of the same command', function (): void {
        $this->operator->accessibleNodes()->attach($this->operator);

        foreach ([1, 2] as $round) {
            $this->getJson('/api/v1/nodes/'.$this->gateway->id)->assertOk();
            $this->getJson('/api/v1/nodes/'.$this->operator->id)->assertOk();
        }

        expect(Activity::query()->where('command', 'node:show')->pluck('properties')->map(
            static fn ($properties): mixed => $properties?->get('path'),
        )->all())->toBe([
            'api/v1/nodes/'.$this->gateway->id,
            'api/v1/nodes/'.$this->operator->id,
        ]);
    });

    it('always records Schedule operations, as ADR 0013 requires', function (): void {
        $this->getJson('/api/v1/schedules')->assertOk();
        $this->getJson('/api/v1/schedules')->assertOk();
        $this->getJson('/api/v1/schedules')->assertOk();

        expect(Activity::query()->where('command', 'schedule:list')->pluck('status')->all())
            ->toBe(['succeeded', 'succeeded', 'succeeded']);
    });

    it('records the read and logs a warning when the sampling cache fails', function (): void {
        Cache::partialMock()->shouldReceive('add')->andThrow(new RuntimeException('database is locked'));
        Log::spy();

        $this->getJson('/api/v1/nodes')->assertOk();

        expect(Activity::query()->where('command', 'node:list')->pluck('status')->all())->toBe(['succeeded']);
        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message, array $context): bool => str_contains($message, 'sampling failed')
                && $context['command'] === 'node:list')
            ->once();
    });

    it('keeps every failed read', function (): void {
        $this->getJson('/api/v1/nodes/999999')->assertNotFound();
        $this->getJson('/api/v1/nodes/999999')->assertNotFound();

        expect(Activity::query()->where('command', 'node:show')->pluck('status')->all())
            ->toBe(['failed', 'failed'])
            ->and(Activity::query()->where('command', 'node:show')->pluck('error_code')->unique()->all())
            ->toBe(['http.404']);
    });

    it('keeps a failed read even inside the window of a successful one', function (): void {
        $this->getJson('/api/v1/nodes/'.$this->gateway->id)->assertOk();
        $this->getJson('/api/v1/nodes/'.$this->gateway->id)->assertOk();
        $this->getJson('/api/v1/nodes/999999')->assertNotFound();

        expect(Activity::query()->where('command', 'node:show')->pluck('status')->all())
            ->toBe(['succeeded', 'failed']);
    });

    it('keeps every credential read', function (): void {
        $this->getJson('/api/v1/analytics/credentials')->assertOk();
        $this->getJson('/api/v1/analytics/credentials')->assertOk();

        expect(Activity::query()->where('command', 'analytics:credentials')->count())->toBe(2);
    });

    it('keeps every mutating command', function (): void {
        $this->postJson('/api/v1/clusters', ['name' => 'one'])->assertCreated();
        $this->postJson('/api/v1/clusters', ['name' => 'two'])->assertCreated();

        expect(Activity::query()->where('command', 'cluster:create')->pluck('status')->all())
            ->toBe(['succeeded', 'succeeded']);
    });
});
