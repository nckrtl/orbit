<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Mcp\OrbitServer;
use App\Models\Node;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Contracts\Transport;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

it('uses one generated request id for a headerless MCP handler log and response', function (): void {
    $gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'request-log-mcp-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.90',
        'wireguard_ip' => '10.44.0.90',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);

    $handler = new TestHandler;
    $logger = Log::channel('stack')->getLogger();
    expect($logger)->toBeInstanceOf(Logger::class);
    $logger->pushHandler($handler);

    app()->bind(OrbitServer::class, static function (Container $app, array $parameters): OrbitServer {
        $transport = $parameters['transport'];
        assert($transport instanceof Transport);

        return new class($transport) extends OrbitServer
        {
            protected function boot(): void
            {
                Log::info('MCP handler request context sentinel', [
                    'request_attribute_id' => request()->attributes->getString('orbit.request_id'),
                ]);
                parent::boot();
            }
        };
    });

    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'request-log-test', 'version' => '1.0.0'],
        ],
    ]);

    $response->assertOk();
    $responseRequestId = $response->headers->get('X-Orbit-Request-Id');
    $record = collect($handler->getRecords())->first(
        fn ($record): bool => $record->message === 'MCP handler request context sentinel',
    );

    expect($responseRequestId)->toBeString()->not->toBe('')
        ->and($record?->context['request_id'] ?? null)->toBe($responseRequestId)
        ->and($record?->context['request_attribute_id'] ?? null)->toBe($responseRequestId);
});

it('includes the request id in the log for successful and server-error requests and does not log refusals as errors', function (): void {
    $handler = new TestHandler;
    $logger = Log::channel('stack')->getLogger();
    expect($logger)->toBeInstanceOf(Logger::class);
    $logger->pushHandler($handler);

    Route::middleware('api')->get('/api/test/log-context', function () {
        Log::info('request log context sentinel');

        return response()->json(['ok' => true]);
    });
    Route::middleware('api')->get('/api/test/log-context/server-error', function () {
        throw new RuntimeException('request log context server error');
    });
    Route::middleware('api')->get('/api/test/log-context/http-refusal', function () {
        abort(403, 'The HTTP operation was refused.');
    });
    Route::middleware('api')->get('/api/test/log-context/authorization-refusal', function () {
        throw new AuthorizationException('The authorization was refused.');
    });
    Route::middleware('api')->get('/api/test/log-context/refusal', function () {
        throw new ResourceOperationException('test.refused', 'The operation was refused.', 409);
    });
    Route::middleware('api')->get('/api/test/log-context/role-refusal', function () {
        throw new RoleAssignmentException('The role assignment was refused.');
    });
    Route::middleware('api')->get('/api/test/log-context/not-found', function () {
        throw new NotFoundHttpException('Not found.');
    });
    Route::middleware('api')->get('/api/test/log-context/record-not-found', function () {
        throw new RecordNotFoundException('Record not found.');
    });
    Route::middleware('api')->get('/api/test/log-context/records-not-found', function () {
        throw new RecordsNotFoundException('Records not found.');
    });
    Route::middleware('api')->get('/api/test/log-context/server-refusal', function () {
        throw new ResourceOperationException('test.server_refusal', 'The operation failed.', 503);
    });

    $requestIds = [
        'success' => 'a327e527-4de7-4e1f-86ba-babb15921704',
        'server_error' => 'b327e527-4de7-4e1f-86ba-babb15921704',
        'refusal' => 'c327e527-4de7-4e1f-86ba-babb15921704',
        'role_refusal' => 'd327e527-4de7-4e1f-86ba-babb15921704',
        'not_found' => 'e327e527-4de7-4e1f-86ba-babb15921704',
        'server_refusal' => 'f327e527-4de7-4e1f-86ba-babb15921704',
        'http_refusal' => '037e527a-4de7-4e1f-86ba-babb15921704',
        'authorization_refusal' => '137e527a-4de7-4e1f-86ba-babb15921704',
        'record_not_found' => '237e527b-4de7-4e1f-86ba-babb15921704',
        'records_not_found' => '337e527b-4de7-4e1f-86ba-babb15921704',
    ];

    $this->withHeader('X-Orbit-Request-Id', $requestIds['success'])->getJson('/api/test/log-context')->assertOk();
    $this->withHeader('X-Orbit-Request-Id', $requestIds['server_error'])->getJson('/api/test/log-context/server-error')->assertInternalServerError();
    $this->withHeader('X-Orbit-Request-Id', $requestIds['refusal'])->getJson('/api/test/log-context/refusal')->assertStatus(409);
    $this->withHeader('X-Orbit-Request-Id', $requestIds['role_refusal'])->getJson('/api/test/log-context/role-refusal')->assertStatus(422);
    $this->withHeader('X-Orbit-Request-Id', $requestIds['not_found'])->getJson('/api/test/log-context/not-found')->assertNotFound();
    $this->withHeader('X-Orbit-Request-Id', $requestIds['server_refusal'])->getJson('/api/test/log-context/server-refusal')->assertStatus(503);
    $this->withHeader('X-Orbit-Request-Id', $requestIds['http_refusal'])->getJson('/api/test/log-context/http-refusal')->assertInternalServerError();
    $this->withHeader('X-Orbit-Request-Id', $requestIds['authorization_refusal'])->getJson('/api/test/log-context/authorization-refusal')->assertInternalServerError();
    $this->withHeader('X-Orbit-Request-Id', $requestIds['record_not_found'])->getJson('/api/test/log-context/record-not-found')->assertNotFound();
    $this->withHeader('X-Orbit-Request-Id', $requestIds['records_not_found'])->getJson('/api/test/log-context/records-not-found')->assertNotFound();

    $records = $handler->getRecords();

    expect(collect($records)->first(fn ($record): bool => $record->message === 'request log context sentinel')?->context['request_id'] ?? null)
        ->toBe($requestIds['success']);
    expect(collect($records)->first(fn ($record): bool => $record->level === Level::Error && str_contains($record->message, 'request log context server error'))?->context['request_id'] ?? null)
        ->toBe($requestIds['server_error']);
    expect(collect($records)->first(fn ($record): bool => $record->level === Level::Info && ($record->context['exception'] ?? null) instanceof ResourceOperationException && $record->context['exception']->errorCode === 'test.refused')?->context['request_id'] ?? null)
        ->toBe($requestIds['refusal']);
    expect(collect($records)->first(fn ($record): bool => $record->level === Level::Info && ($record->context['exception'] ?? null) instanceof RoleAssignmentException)?->context['request_id'] ?? null)
        ->toBe($requestIds['role_refusal']);
    expect(collect($records)->first(fn ($record): bool => $record->level === Level::Info && ($record->context['exception'] ?? null) instanceof NotFoundHttpException)?->context['request_id'] ?? null)
        ->toBe($requestIds['not_found']);
    expect(collect($records)->contains(fn ($record): bool => $record->level->value >= Level::Error->value && ($record->context['exception'] ?? null) instanceof ResourceOperationException && $record->context['exception']->errorCode === 'test.refused'))
        ->toBeFalse();
    expect(collect($records)->first(fn ($record): bool => $record->level === Level::Error && ($record->context['exception'] ?? null) instanceof ResourceOperationException && $record->context['exception']->errorCode === 'test.server_refusal')?->context['request_id'] ?? null)
        ->toBe($requestIds['server_refusal']);
    expect(collect($records)->first(fn ($record): bool => $record->level === Level::Error && ($record->context['exception'] ?? null) instanceof HttpException)?->context['request_id'] ?? null)
        ->toBe($requestIds['http_refusal']);
    expect(collect($records)->first(fn ($record): bool => $record->level === Level::Error && ($record->context['exception'] ?? null) instanceof AuthorizationException)?->context['request_id'] ?? null)
        ->toBe($requestIds['authorization_refusal']);
    expect(collect($records)->first(fn ($record): bool => $record->level === Level::Info && ($record->context['exception'] ?? null) instanceof RecordNotFoundException)?->context['request_id'] ?? null)
        ->toBe($requestIds['record_not_found']);
    expect(collect($records)->first(fn ($record): bool => $record->level === Level::Info && ($record->context['exception'] ?? null) instanceof RecordsNotFoundException)?->context['request_id'] ?? null)
        ->toBe($requestIds['records_not_found']);
    expect(collect($records)->contains(fn ($record): bool => $record->level->value >= Level::Error->value
        && (($record->context['exception'] ?? null) instanceof RecordNotFoundException
            || ($record->context['exception'] ?? null) instanceof RecordsNotFoundException)))
        ->toBeFalse();
});

it('correlates requests that do not match an API route', function (): void {
    $handler = new TestHandler;
    $logger = Log::channel('stack')->getLogger();
    expect($logger)->toBeInstanceOf(Logger::class);
    $logger->pushHandler($handler);

    $requestId = '237e527a-4de7-4e1f-86ba-babb15921704';
    $this->withHeader('X-Orbit-Request-Id', $requestId)
        ->getJson('/api/test/unmatched-route')
        ->assertNotFound()
        ->assertHeader('X-Orbit-Request-Id', $requestId);

    $record = collect($handler->getRecords())->first(
        fn ($record): bool => $record->level === Level::Info
            && ($record->context['exception'] ?? null) instanceof NotFoundHttpException,
    );

    expect($record?->context['request_id'] ?? null)->toBe($requestId);
});

it('restores the outer request id after a nested request', function (): void {
    $handler = new TestHandler;
    $logger = Log::channel('stack')->getLogger();
    expect($logger)->toBeInstanceOf(Logger::class);
    $logger->pushHandler($handler);

    $innerRequestId = 'b527e527-4de7-4e1f-86ba-babb15921704';
    Route::middleware('api')->get('/api/test/log-context/nested', static function () use ($innerRequestId) {
        Log::info('outer request before nested request');
        app(Kernel::class)->handle(Request::create('/api/test/log-context/inner', 'GET', [], [], [], [
            'HTTP_X_ORBIT_REQUEST_ID' => $innerRequestId,
        ]));
        Log::info('outer request after nested request');

        return response()->json(['ok' => true]);
    });
    Route::middleware('api')->get('/api/test/log-context/inner', static function () {
        Log::info('inner request log sentinel');

        return response()->noContent();
    });

    $outerRequestId = 'a527e527-4de7-4e1f-86ba-babb15921704';
    $response = $this->withHeader('X-Orbit-Request-Id', $outerRequestId)
        ->getJson('/api/test/log-context/nested');

    $records = $handler->getRecords();
    expect(collect($records)->first(fn ($record): bool => $record->message === 'outer request before nested request')?->context['request_id'] ?? null)
        ->toBe($outerRequestId);
    expect(collect($records)->first(fn ($record): bool => $record->message === 'inner request log sentinel')?->context['request_id'] ?? null)
        ->toBe($innerRequestId);
    expect(collect($records)->first(fn ($record): bool => $record->message === 'outer request after nested request')?->context['request_id'] ?? null)
        ->toBe($outerRequestId);

    app()->terminate();
    expect($response->status())->toBe(200);
});

it('retains the request id through streamed and terminating log entries', function (): void {
    $handler = new TestHandler;
    $logger = Log::channel('stack')->getLogger();
    expect($logger)->toBeInstanceOf(Logger::class);
    $logger->pushHandler($handler);

    Route::middleware('api')->get('/api/test/log-context/deferred', function () {
        app()->terminating(static fn () => Log::info('request termination log sentinel'));

        return response()->stream(static function (): void {
            Log::info('request stream log sentinel');
            echo 'streamed';
        });
    });

    $requestId = 'a427e527-4de7-4e1f-86ba-babb15921704';
    $response = $this->withHeader('X-Orbit-Request-Id', $requestId)->get('/api/test/log-context/deferred');

    ob_start();
    $response->sendContent();
    ob_end_clean();
    app()->terminate();

    $records = $handler->getRecords();
    expect(collect($records)->first(fn ($record): bool => $record->message === 'request stream log sentinel')?->context['request_id'] ?? null)
        ->toBe($requestId);
    expect(collect($records)->first(fn ($record): bool => $record->message === 'request termination log sentinel')?->context['request_id'] ?? null)
        ->toBe($requestId);
});
