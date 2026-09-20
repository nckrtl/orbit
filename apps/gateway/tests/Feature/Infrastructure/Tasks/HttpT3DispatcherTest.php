<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\T3DispatchException;
use App\Infrastructure\Tasks\HttpT3Dispatcher;
use App\Models\Node;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function t3_node(): Node
{
    return Node::query()->create([
        'name' => 't3-http',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.120',
        'wireguard_ip' => '10.44.0.120',
    ]);
}

it('posts a flat dispatch body with an empty headers array', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.120:3773/api/orchestration/dispatch' => Http::response([
            'sequence' => 4,
            'threadId' => 'thread-from-t3',
        ]),
    ]);
    config()->set('orbit.t3.token', 't3-secret');

    $result = app(HttpT3Dispatcher::class)->dispatch(t3_node(), [
        'type' => 'thread.create',
        'commandId' => 'cmd-1',
        'threadId' => 'thread-local',
        'title' => 'Reviewer',
    ]);

    expect($result)->toBe([
        'sequence' => 4,
        'thread_id' => 'thread-from-t3',
    ]);

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        return $request->url() === 'http://10.44.0.120:3773/api/orchestration/dispatch'
            && $request->hasHeader('Authorization', 'Bearer t3-secret')
            && $payload === [
                'type' => 'thread.create',
                'commandId' => 'cmd-1',
                'threadId' => 'thread-local',
                'title' => 'Reviewer',
                'headers' => [],
            ]
            && ! array_key_exists('command', $payload);
    });
});

it('keeps the caller thread id when T3 only returns a sequence', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.120:3773/api/orchestration/dispatch' => Http::response(['sequence' => 1]),
    ]);

    expect(app(HttpT3Dispatcher::class)->dispatch(t3_node(), [
        'type' => 'thread.turn.start',
        'threadId' => 'reviewer-thread',
        'message' => 'please review',
    ]))->toBe([
        'sequence' => 1,
        'thread_id' => 'reviewer-thread',
    ]);
});

it('refuses a nested or unsuccessful T3 response without leaking the bearer', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.120:3773/api/orchestration/dispatch' => Http::response([
            'code' => 'command_rejected',
            'reason' => 'bearer-secret must not leak',
        ], 400),
    ]);
    config()->set('orbit.t3.token', 'bearer-secret');

    expect(fn () => app(HttpT3Dispatcher::class)->dispatch(t3_node(), [
        'type' => 'thread.create',
        'threadId' => 'thread-1',
    ]))->toThrow(function (T3DispatchException $exception): void {
        expect($exception->getMessage())->toBe('T3 dispatch failed.')
            ->and($exception->getMessage())->not->toContain('bearer-secret');
    });
});
