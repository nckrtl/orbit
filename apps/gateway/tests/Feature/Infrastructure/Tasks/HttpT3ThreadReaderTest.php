<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Tasks\HttpT3ThreadReader;
use App\Models\Node;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function t3_thread_node(): Node
{
    return Node::query()->create([
        'name' => 't3-thread',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.121',
        'wireguard_ip' => '10.44.0.121',
    ]);
}

it('gets a thread snapshot from the Node T3 server', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.121:3773/api/orchestration/threads/thread-abc' => Http::response([
            'snapshotSequence' => 9,
            'thread' => ['id' => 'thread-abc', 'activities' => [], 'checkpoints' => []],
        ]),
    ]);
    config()->set('orbit.t3.token', 't3-secret');

    $snapshot = app(HttpT3ThreadReader::class)->snapshot(t3_thread_node(), 'thread-abc');

    expect($snapshot)->toBe([
        'snapshotSequence' => 9,
        'thread' => ['id' => 'thread-abc', 'activities' => [], 'checkpoints' => []],
    ]);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'http://10.44.0.121:3773/api/orchestration/threads/thread-abc'
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer t3-secret');
    });
});

it('returns null when T3 refuses the snapshot', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.121:3773/api/orchestration/threads/missing' => Http::response(['reason' => 'thread_not_found'], 404),
    ]);

    expect(app(HttpT3ThreadReader::class)->snapshot(t3_thread_node(), 'missing'))->toBeNull();
});

it('returns null when the Node has no WireGuard address', function (): void {
    Http::preventStrayRequests();
    Http::fake();

    $node = Node::query()->create([
        'name' => 't3-offline',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.122',
        'wireguard_ip' => null,
    ]);

    expect(app(HttpT3ThreadReader::class)->snapshot($node, 'thread-abc'))->toBeNull();
    Http::assertNothingSent();
});
