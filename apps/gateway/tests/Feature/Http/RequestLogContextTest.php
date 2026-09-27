<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceRemovalStatus;
use App\Domain\AppInstances\Removal\AppInstanceRemovalException;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\NodeRemovalException;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleOperationException;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolOperationException;
use App\Domain\Tools\ToolOutcome;
use App\Http\Mcp\OrbitServer;
use App\Infrastructure\Logging\GatewayExceptionStatus;
use App\Infrastructure\Processes\CommandResult;
use App\Models\AppInstanceRemoval;
use App\Models\Node;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
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
    Route::middleware('api')->get('/api/test/log-context/authentication-refusal', function () {
        throw new AuthenticationException('Authentication required.');
    });
    Route::middleware('api')->get('/api/test/log-context/framework-server-error', function () {
        throw new HttpException(503, 'Framework server error.');
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
        'authentication_refusal' => '437e527b-4de7-4e1f-86ba-babb15921704',
        'framework_server_error' => '537e527b-4de7-4e1f-86ba-babb15921704',
        'record_not_found' => '237e527b-4de7-4e1f-86ba-babb15921704',
        'records_not_found' => '337e527b-4de7-4e1f-86ba-babb15921704',
    ];

    $this->withHeader('X-Orbit-Request-Id', $requestIds['success'])->getJson('/api/test/log-context')->assertOk();
    $this->withHeader('X-Orbit-Request-Id', $requestIds['server_error'])->getJson('/api/test/log-context/server-error')->assertInternalServerError();
    $this->withHeader('X-Orbit-Request-Id', $requestIds['refusal'])->getJson('/api/test/log-context/refusal')->assertStatus(409);
    $this->withHeader('X-Orbit-Request-Id', $requestIds['role_refusal'])->getJson('/api/test/log-context/role-refusal')->assertStatus(422);
    $this->withHeader('X-Orbit-Request-Id', $requestIds['not_found'])->getJson('/api/test/log-context/not-found')->assertNotFound();
    $this->withHeader('X-Orbit-Request-Id', $requestIds['server_refusal'])->getJson('/api/test/log-context/server-refusal')->assertStatus(503);
    $this->withHeader('X-Orbit-Request-Id', $requestIds['http_refusal'])->getJson('/api/test/log-context/http-refusal')->assertForbidden();
    $this->withHeader('X-Orbit-Request-Id', $requestIds['authorization_refusal'])->getJson('/api/test/log-context/authorization-refusal')->assertForbidden();
    $this->withHeader('X-Orbit-Request-Id', $requestIds['authentication_refusal'])->getJson('/api/test/log-context/authentication-refusal')->assertUnauthorized();
    $this->withHeader('X-Orbit-Request-Id', $requestIds['framework_server_error'])->getJson('/api/test/log-context/framework-server-error')->assertStatus(503);
    $this->withHeader('X-Orbit-Request-Id', $requestIds['record_not_found'])->getJson('/api/test/log-context/record-not-found')->assertNotFound();
    $this->withHeader('X-Orbit-Request-Id', $requestIds['records_not_found'])->getJson('/api/test/log-context/records-not-found')->assertNotFound();

    $records = $handler->getRecords();

    expect(collect($records)->first(fn ($record): bool => $record->message === 'request log context sentinel')?->context['request_id'] ?? null)
        ->toBe($requestIds['success']);
    expect(collect($records)->first(fn ($record): bool => $record->level === Level::Error && str_contains($record->message, 'request log context server error'))?->context['request_id'] ?? null)
        ->toBe($requestIds['server_error']);
    expect(collect($records)->contains(fn ($record): bool => ($record->context['exception'] ?? null) instanceof ResourceOperationException && $record->context['exception']->errorCode === 'test.refused'))->toBeFalse();
    expect(collect($records)->contains(fn ($record): bool => ($record->context['exception'] ?? null) instanceof RoleAssignmentException))->toBeFalse();
    expect(collect($records)->contains(fn ($record): bool => ($record->context['exception'] ?? null) instanceof NotFoundHttpException))->toBeFalse();
    expect(collect($records)->contains(fn ($record): bool => $record->level->value >= Level::Error->value && ($record->context['exception'] ?? null) instanceof ResourceOperationException && $record->context['exception']->errorCode === 'test.refused'))
        ->toBeFalse();
    expect(collect($records)->first(fn ($record): bool => $record->level === Level::Error && ($record->context['exception'] ?? null) instanceof ResourceOperationException && $record->context['exception']->errorCode === 'test.server_refusal')?->context['request_id'] ?? null)
        ->toBe($requestIds['server_refusal']);
    $frameworkServerErrors = collect($records)->filter(fn ($record): bool => ($record->context['exception'] ?? null) instanceof HttpException
        && $record->context['exception']->getStatusCode() === 503);
    expect($frameworkServerErrors)->toHaveCount(1)
        ->and($frameworkServerErrors->first()->level)->toBe(Level::Error)
        ->and($frameworkServerErrors->first()->context['request_id'])->toBe($requestIds['framework_server_error']);
    expect(collect($records)->contains(fn ($record): bool => ($record->context['exception'] ?? null) instanceof AuthenticationException))->toBeFalse();
    expect(collect($records)->contains(fn ($record): bool => ($record->context['exception'] ?? null) instanceof AuthorizationException))->toBeFalse();
    expect(collect($records)->contains(fn ($record): bool => ($record->context['exception'] ?? null) instanceof RecordNotFoundException))->toBeFalse();
    expect(collect($records)->contains(fn ($record): bool => ($record->context['exception'] ?? null) instanceof RecordsNotFoundException))->toBeFalse();
    expect(collect($records)->contains(fn ($record): bool => $record->level->value >= Level::Error->value
        && (($record->context['exception'] ?? null) instanceof RecordNotFoundException
            || ($record->context['exception'] ?? null) instanceof RecordsNotFoundException)))
        ->toBeFalse();
});

it('renders domain and framework exception statuses from one status source', function (): void {
    $result = new CommandResult(1, '', '', 1, false);
    $removal = new AppInstanceRemoval([
        'id' => 'status-source-removal',
        'requested_app_instance_id' => 1,
        'requested_name' => 'status-source-app',
        'force' => false,
        'inventory_digest' => 'digest',
        'total' => 0,
        'status' => AppInstanceRemovalStatus::Failed,
    ]);
    $exceptions = [
        new AppInstanceRemovalException('app_instance.remove_failed', 503, $removal),
        new NodeRoleValidationException('Role validation failed.'),
        new NodeRoleOperationException('apply', 'node_role.failed', 'failed', 'Role operation failed.', $result),
        new NodeProvisioningException('provision', 'node.provision_failed', 'Provisioning failed.', result: $result),
        new NodeRemovalException('remove', 'node.remove_failed', 'Removal failed.', $result),
        new RuntimeConvergenceException('converge', 'runtime.failed', 'Runtime convergence failed.', result: $result),
        new ProcessOperationException('run', 'process.failed', 'Process operation failed.', $result),
        new ScheduleOperationException('install', ScheduleErrorCode::InstallFailed, 'Schedule operation failed.'),
        new FirewallOperationException('apply', 'firewall.failed', 'Firewall operation failed.', status: 503),
        new ToolOperationException('install', 'tool.failed', ToolOutcome::ManagerFailed, 409, 1, 'apt', 'nginx', null, 'Tool operation failed.'),
        new ResourceOperationException('resource.failed', 'Resource operation failed.', 409),
        new RoleAssignmentException('Role assignment failed.'),
        new HttpException(503, 'Framework server failure.'),
        new AuthenticationException('Authentication required.'),
    ];

    foreach ($exceptions as $index => $exception) {
        $path = '/api/test/log-context/domain-exception-'.$index;
        Route::middleware('api')->get($path, static function () use ($exception): never {
            throw $exception;
        });

        $response = $this->getJson($path);
        expect($response->status())->toBe(GatewayExceptionStatus::for($exception));
    }

    $bootstrap = file_get_contents(base_path('bootstrap/app.php'));
    expect($bootstrap)->not->toContain('], 422)')
        ->and($bootstrap)->not->toContain('], 502)')
        ->and($bootstrap)->not->toContain('$exception->status');
});

it('does not report client refusals and reports server errors at ERROR with the request id', function (): void {
    $handler = new TestHandler;
    $logger = Log::channel('stack')->getLogger();
    expect($logger)->toBeInstanceOf(Logger::class);
    $logger->pushHandler($handler);

    Route::middleware('api')->get('/api/test/log-context/one-status-source-refusal', static function (): never {
        throw new ResourceOperationException('test.refusal', 'Client refusal.', 409);
    });
    Route::middleware('api')->get('/api/test/log-context/one-status-source-failure', static function (): never {
        throw new RuntimeException('Server failure.');
    });

    $requestId = '4a05fa33-2cb4-4762-9a63-4c4385f06a0d';
    $refusalRequestId = 'f4a05fa3-2cb4-4762-9a63-4c4385f06a0d';
    $this->withHeader('X-Orbit-Request-Id', $refusalRequestId)
        ->getJson('/api/test/log-context/one-status-source-refusal')
        ->assertStatus(409);
    $this->withHeader('X-Orbit-Request-Id', $requestId)
        ->getJson('/api/test/log-context/one-status-source-failure')
        ->assertInternalServerError();

    $records = collect($handler->getRecords());
    expect($records->contains(fn ($record): bool => ($record->context['exception'] ?? null) instanceof ResourceOperationException))->toBeFalse()
        ->and($records->contains(fn ($record): bool => ($record->context['request_id'] ?? null) === $refusalRequestId))->toBeFalse();
    expect($records->first(fn ($record): bool => $record->level === Level::Error
        && ($record->context['exception'] ?? null) instanceof RuntimeException)?->context['request_id'] ?? null)
        ->toBe($requestId);
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

    expect(collect($handler->getRecords())->contains(
        fn ($record): bool => ($record->context['exception'] ?? null) instanceof NotFoundHttpException,
    ))->toBeFalse();
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
