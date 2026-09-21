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

it('accepts a successful project.create that returns only a sequence', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.120:3773/api/orchestration/dispatch' => Http::response(['sequence' => 2]),
    ]);

    expect(app(HttpT3Dispatcher::class)->dispatch(t3_node(), [
        'type' => 'project.create',
        'commandId' => 'cmd-create',
        'projectId' => '11111111-1111-1111-1111-111111111111',
        'workspaceRoot' => '/srv/orbit/apps/orbit/task-1',
    ]))->toBe([
        'sequence' => 2,
        'thread_id' => '',
    ]);
});

it('parses an existing project id from a workspace-root collision without leaking the bearer', function (array $error): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.120:3773/api/orchestration/dispatch' => Http::response($error, 500),
    ]);
    config()->set('orbit.t3.token', 'bearer-secret');

    expect(fn () => app(HttpT3Dispatcher::class)->dispatch(t3_node(), [
        'type' => 'project.create',
        'projectId' => '11111111-1111-1111-1111-111111111111',
        'workspaceRoot' => '/srv/orbit/apps/orbit/task-1',
    ]))->toThrow(function (T3DispatchException $exception): void {
        expect($exception->existingProjectId)->toBe('550e8400-e29b-41d4-a716-446655440000')
            ->and($exception->getMessage())->toBe('T3 dispatch failed.')
            ->and($exception->getMessage())->not->toContain('bearer-secret');
    });
})->with([
    'documented reason' => [[
        'reason' => 'Active project 550e8400-e29b-41d4-a716-446655440000 already exists for that workspace root',
    ]],
    'live quoted phrase' => [[
        'reason' => "Active project '550e8400-e29b-41d4-a716-446655440000' already exists for workspace root '/srv/orbit/apps/orbit/task-1'.",
    ]],
    'nested effect cause' => [[
        '_tag' => 'EnvironmentInternalError',
        'code' => 'internal_error',
        'reason' => 'orchestration_dispatch_failed',
        'traceId' => 'trace-1',
        'cause' => [
            'message' => "Active project '550e8400-e29b-41d4-a716-446655440000' already exists for workspace root '/srv/orbit/apps/orbit/task-1'.",
        ],
    ]],
]);

it('adopts the existing project from the orchestration snapshot when collide is an opaque 500', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.120:3773/api/orchestration/dispatch' => Http::response([
            '_tag' => 'EnvironmentInternalError',
            'code' => 'internal_error',
            'reason' => 'orchestration_dispatch_failed',
            'traceId' => 'trace-occupied',
        ], 500),
        'http://10.44.0.120:3773/api/orchestration/snapshot' => Http::response([
            'snapshotSequence' => 3,
            'projects' => [
                [
                    'id' => '550e8400-e29b-41d4-a716-446655440000',
                    'workspaceRoot' => '/srv/orbit/apps/orbit/task-1/',
                    'deletedAt' => null,
                ],
            ],
            'threads' => [],
            'updatedAt' => '2026-09-21T00:00:00.000Z',
        ]),
    ]);
    config()->set('orbit.t3.token', 'bearer-secret');

    expect(fn () => app(HttpT3Dispatcher::class)->dispatch(t3_node(), [
        'type' => 'project.create',
        'projectId' => '11111111-1111-1111-1111-111111111111',
        'workspaceRoot' => '/srv/orbit/apps/orbit/task-1',
    ]))->toThrow(function (T3DispatchException $exception): void {
        expect($exception->existingProjectId)->toBe('550e8400-e29b-41d4-a716-446655440000')
            ->and($exception->getMessage())->toBe('T3 dispatch failed.')
            ->and($exception->getMessage())->not->toContain('bearer-secret');
    });

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://10.44.0.120:3773/api/orchestration/snapshot'
        && $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer bearer-secret'));
});

it('does not adopt from an opaque 500 when the snapshot has no active project for the root', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.120:3773/api/orchestration/dispatch' => Http::response('', 500),
        'http://10.44.0.120:3773/api/orchestration/snapshot' => Http::response([
            'projects' => [
                [
                    'id' => '550e8400-e29b-41d4-a716-446655440000',
                    'workspaceRoot' => '/srv/orbit/apps/other/task-1',
                    'deletedAt' => null,
                ],
            ],
        ]),
    ]);
    config()->set('orbit.t3.token', 'bearer-secret');

    expect(fn () => app(HttpT3Dispatcher::class)->dispatch(t3_node(), [
        'type' => 'project.create',
        'workspaceRoot' => '/srv/orbit/apps/orbit/task-1',
    ]))->toThrow(function (T3DispatchException $exception): void {
        expect($exception->existingProjectId)->toBeNull()
            ->and($exception->getMessage())->not->toContain('bearer-secret');
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
            ->and($exception->existingProjectId)->toBeNull()
            ->and($exception->httpStatus)->toBe(400)
            ->and($exception->httpBody)->toBe('{"code":"command_rejected","reason":"bearer-secret must not leak"}')
            ->and($exception->getMessage())->not->toContain('bearer-secret');
    });
});

it('surfaces the HTTP status and body when turn start fails', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.120:3773/api/orchestration/dispatch' => Http::response('turn rejected', 422),
    ]);

    expect(fn () => app(HttpT3Dispatcher::class)->dispatch(t3_node(), [
        'type' => 'thread.turn.start',
        'threadId' => 'thread-1',
    ]))->toThrow(function (T3DispatchException $exception): void {
        expect($exception->httpStatus)->toBe(422)
            ->and($exception->httpBody)->toBe('turn rejected');
    });
});
