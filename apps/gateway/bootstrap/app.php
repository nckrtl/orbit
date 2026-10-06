<?php

declare(strict_types=1);

use App\Data\Instances\InstanceRemovalData;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Instances\Removal\InstanceRemovalException;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\NodeRemovalException;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Schedules\ScheduleOperationException;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskDefinitionInvalid;
use App\Domain\Tasks\TaskDefinitionViolation;
use App\Domain\Tasks\TaskSchedule;
use App\Domain\Tools\ToolOperationException;
use App\Http\Middleware\EnsureRequestId;
use App\Http\Middleware\GuardBrowserOrigins;
use App\Http\Middleware\NormalizeErrorDetails;
use App\Http\Middleware\RecordCommandActivity;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireEnabledExtension;
use App\Http\Middleware\RequireNodeAccess;
use App\Http\Middleware\ValidateDocumentPostSize;
use App\Infrastructure\Activity\ActivityShutdownFinalizer;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Logging\GatewayExceptionStatus;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

if (
    ini_get('zend.exception_ignore_args') !== '1'
    && (! function_exists('ini_set')
    || ini_set(option: 'zend.exception_ignore_args', value: '1') === false
    || ini_get('zend.exception_ignore_args') !== '1')
) {
    throw new RuntimeException('PHP must omit arguments from exception traces.');
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        health: '/up',
        then: function (): void {
            require __DIR__.'/../routes/channels.php';
        },
    )
    ->withSchedule(function (Schedule $schedule): void {
        app(TaskSchedule::class)->register($schedule);
        $schedule->command('annotations:dispatch')->everyTenSeconds()->withoutOverlapping();
        $schedule->command('orbit:deploy-development-defaults')->everyMinute()->withoutOverlapping(90);
        $schedule->command('orbit:activity-finalize-interrupted')->everyFiveMinutes()->withoutOverlapping(10);
        $schedule->command('project-documents:probes:reconcile')->everyMinute()->withoutOverlapping(10);
        $schedule->command('project-documents:cleanup:work')->everyFiveMinutes()->withoutOverlapping(60);
    })
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(ValidatePostSize::class, ValidateDocumentPostSize::class);
        $middleware->trimStrings(except: [ValidateDocumentPostSize::preservesExactJson(...)]);
        $middleware->convertEmptyStringsToNull(except: [ValidateDocumentPostSize::preservesExactJson(...)]);
        $middleware->prepend(GuardBrowserOrigins::class);
        $middleware->prepend(EnsureRequestId::class);
        $middleware->api(prepend: [NormalizeErrorDetails::class, RecordCommandActivity::class, RequireEnabledExtension::class]);
        $middleware->prependToPriorityList(SubstituteBindings::class, RequireActiveWireGuardPeer::class);
        $middleware->appendToPriorityList(SubstituteBindings::class, RequireNodeAccess::class);
        $middleware->appendToPriorityList(RequireNodeAccess::class, RequireEnabledExtension::class);
    })
    ->withExceptions(
        function (Exceptions $exceptions): void {
            // Runs before log context is built, which can exhaust memory again and stop later shutdown code.
            $exceptions->report(function (FatalError $exception): void {
                ActivityShutdownFinalizer::finalizeArmed();
            });
            // Laravel ignores HttpException before report callbacks; status filtering keeps 4xx refusals silent.
            $exceptions->stopIgnoring(HttpException::class);
            $exceptions->report(function (Throwable $exception): bool {
                return GatewayExceptionStatus::for($exception, request()->is('api/*')) >= 500;
            });

            $exceptions->render(function (InstanceRemovalException $exception, Request $request): JsonResponse {
                $request->attributes->set('orbit.error_code', $exception->errorCode);
                $removal = InstanceRemovalData::fromModel($exception->removal)->toArray();
                $request->attributes->set('orbit.instance_removal', $removal);
                $requestId = $request->attributes->get('orbit.request_id');

                if (! is_string($requestId) || $requestId === '') {
                    $requestId = $request->header('X-Orbit-Request-Id', '');
                }

                return response()
                    ->json([
                        'error' => [
                            'code' => $exception->errorCode,
                            'message' => $exception->getMessage(),
                            'details' => ['removal' => $removal],
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', is_string($requestId) ? $requestId : '');
            });

            $notFound = static function (Request $request): JsonResponse {
                $requestId = $request->attributes->get('orbit.request_id');

                if (! is_string($requestId) || $requestId === '') {
                    $requestId = $request->header('X-Orbit-Request-Id', '');
                }

                return response()
                    ->json([
                        'error' => [
                            'code' => 'http.404',
                            'message' => 'Resource not found.',
                            'details' => [],
                        ],
                    ], 404)
                    ->header('X-Orbit-Request-Id', is_string($requestId) ? $requestId : '');
            };

            $exceptions->render(function (ValidationException $exception, Request $request): JsonResponse {
                $requestId = $request->attributes->get('orbit.request_id');

                if (! is_string($requestId) || $requestId === '') {
                    $requestId = $request->header('X-Orbit-Request-Id', '');
                }

                return response()
                    ->json([
                        'error' => [
                            'code' => 'validation.failed',
                            'message' => 'The request data is invalid.',
                            'details' => $exception->errors(),
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', is_string($requestId) ? $requestId : '');
            });
            $exceptions->render(function (NodeRoleValidationException $exception, Request $request): JsonResponse {
                $request->attributes->set('orbit.error_code', 'validation.failed');
                $requestId = $request->attributes->get('orbit.request_id');

                if (! is_string($requestId) || $requestId === '') {
                    $requestId = $request->header('X-Orbit-Request-Id', '');
                }

                return response()
                    ->json([
                        'error' => [
                            'code' => 'validation.failed',
                            'message' => $exception->getMessage(),
                            'details' => $exception->details,
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', is_string($requestId) ? $requestId : '');
            });
            $exceptions->render(function (NodeRoleOperationException $exception, Request $request): JsonResponse {
                $request->attributes->set('orbit.error_code', $exception->errorCode);
                $request->attributes->set('orbit.error_message', $exception->getMessage());
                $request->attributes->set('orbit.command_result', $exception->result);
                $requestId = $request->attributes->get('orbit.request_id');

                if (! is_string($requestId) || $requestId === '') {
                    $requestId = $request->header('X-Orbit-Request-Id', '');
                }

                return response()
                    ->json([
                        'error' => [
                            'code' => $exception->errorCode,
                            'message' => $exception->getMessage(),
                            'details' => ['step' => $exception->step, ...NodeRoleOperationException::detailsIn($exception), ...NodeCaddyBuildException::detailsIn($exception)],
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', is_string($requestId) ? $requestId : '');
            });
            $exceptions->render(function (NodeProvisioningException $exception, Request $request): JsonResponse {
                $request->attributes->set('orbit.error_code', $exception->errorCode);
                $request->attributes->set('orbit.command_result', $exception->result);
                $requestId = $request->attributes->get('orbit.request_id');

                if (! is_string($requestId) || $requestId === '') {
                    $requestId = $request->header('X-Orbit-Request-Id', '');
                }

                return response()
                    ->json([
                        'error' => [
                            'code' => $exception->errorCode,
                            'message' => $exception->getMessage(),
                            'details' => ['step' => $exception->step, ...NodeRoleOperationException::detailsIn($exception), ...NodeCaddyBuildException::detailsIn($exception)],
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', is_string($requestId) ? $requestId : '');
            });
            $exceptions->render(function (NodeRemovalException $exception, Request $request): JsonResponse {
                $request->attributes->set('orbit.error_code', $exception->errorCode);
                $request->attributes->set('orbit.command_result', $exception->result);
                $requestId = $request->attributes->get('orbit.request_id');

                if (! is_string($requestId) || $requestId === '') {
                    $requestId = $request->header('X-Orbit-Request-Id', '');
                }

                return response()
                    ->json([
                        'error' => [
                            'code' => $exception->errorCode,
                            'message' => $exception->getMessage(),
                            'details' => ['step' => $exception->step, ...NodeRoleOperationException::detailsIn($exception)],
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', is_string($requestId) ? $requestId : '');
            });
            $exceptions->render(function (RuntimeConvergenceException $exception, Request $request): JsonResponse {
                $request->attributes->set('orbit.error_code', $exception->errorCode);
                $request->attributes->set('orbit.command_result', $exception->result);
                $requestId = $request->attributes->get('orbit.request_id');

                if (! is_string($requestId) || $requestId === '') {
                    $requestId = $request->header('X-Orbit-Request-Id', '');
                }

                return response()
                    ->json([
                        'error' => [
                            'code' => $exception->errorCode,
                            'message' => $exception->getMessage(),
                            'details' => ['step' => $exception->step, ...NodeCaddyBuildException::detailsIn($exception)],
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', is_string($requestId) ? $requestId : '');
            });
            $exceptions->render(function (ProcessOperationException $exception, Request $request): JsonResponse {
                $request->attributes->set('orbit.error_code', $exception->errorCode);
                $request->attributes->set('orbit.command_result', $exception->result);
                $requestId = $request->attributes->get('orbit.request_id');

                if (! is_string($requestId) || $requestId === '') {
                    $requestId = $request->header('X-Orbit-Request-Id', '');
                }

                return response()
                    ->json([
                        'error' => [
                            'code' => $exception->errorCode,
                            'message' => $exception->getMessage(),
                            'details' => ['step' => $exception->step],
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', is_string($requestId) ? $requestId : '');
            });
            $exceptions->render(function (ScheduleOperationException $exception, Request $request): JsonResponse {
                $request->attributes->set('orbit.error_code', $exception->errorCode);
                $requestId = $request->attributes->get('orbit.request_id');

                if (! is_string($requestId) || $requestId === '') {
                    $requestId = $request->header('X-Orbit-Request-Id', '');
                }

                $status = GatewayExceptionStatus::for($exception);

                return response()
                    ->json([
                        'error' => [
                            'code' => $exception->errorCode,
                            'message' => $exception->getMessage(),
                            'details' => ['step' => $exception->step],
                        ],
                    ], $status)
                    ->header('X-Orbit-Request-Id', is_string($requestId) ? $requestId : '');
            });
            $exceptions->render(function (FirewallOperationException $exception, Request $request): JsonResponse {
                $request->attributes->set('orbit.error_code', $exception->errorCode);
                $request->attributes->set('orbit.command_result', $exception->result);
                $requestId = $request->attributes->get('orbit.request_id');

                if (! is_string($requestId) || $requestId === '') {
                    $requestId = $request->header('X-Orbit-Request-Id', '');
                }

                return response()
                    ->json([
                        'error' => [
                            'code' => $exception->errorCode,
                            'message' => $exception->getMessage(),
                            'details' => ['step' => $exception->step],
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', is_string($requestId) ? $requestId : '');
            });
            $exceptions->render(function (ToolOperationException $exception, Request $request): JsonResponse {
                $request->attributes->set('orbit.error_code', $exception->errorCode);
                $request->attributes->set('orbit.tool_exception', $exception);
                $requestId = $request->attributes->get('orbit.request_id');

                if (! is_string($requestId) || $requestId === '') {
                    $requestId = $request->header('X-Orbit-Request-Id', '');
                }

                $details = [
                    'step' => $exception->step,
                    'outcome' => $exception->outcome->value,
                ];

                if ($exception->toolId !== null) {
                    $details['id'] = $exception->toolId;
                }

                if ($exception->adoptionBlock !== null) {
                    $details['adoption_block'] = $exception->adoptionBlock;
                }

                return response()
                    ->json([
                        'error' => [
                            'code' => $exception->errorCode,
                            'message' => $exception->getMessage(),
                            'details' => $details,
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', is_string($requestId) ? $requestId : '');
            });
            $exceptions->render(function (TaskDefinitionInvalid $exception, Request $request): JsonResponse {
                $request->attributes->set('orbit.error_code', TaskDefinitionInvalid::CODE);
                $request->attributes->set('orbit.error_message', $exception->getMessage());
                $requestId = $request->attributes->get('orbit.request_id');

                if (! is_string($requestId) || $requestId === '') {
                    $requestId = $request->header('X-Orbit-Request-Id', '');
                }

                return response()
                    ->json([
                        'error' => [
                            'code' => TaskDefinitionInvalid::CODE,
                            'message' => $exception->getMessage(),
                            'details' => [
                                'rules' => array_map(
                                    static fn (TaskDefinitionViolation $violation): array => $violation->toArray(),
                                    $exception->rules,
                                ),
                            ],
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', is_string($requestId) ? $requestId : '');
            });
            $exceptions->render(function (ResourceOperationException $exception, Request $request): JsonResponse {
                $request->attributes->set('orbit.error_code', $exception->errorCode);
                $request->attributes->set('orbit.error_message', $exception->getMessage());
                $requestId = $request->attributes->get('orbit.request_id');

                if (! is_string($requestId) || $requestId === '') {
                    $requestId = $request->header('X-Orbit-Request-Id', '');
                }

                return response()
                    ->json([
                        'error' => [
                            'code' => $exception->errorCode,
                            'message' => $exception->getMessage(),
                            'details' => [...NodeCaddyBuildException::detailsIn($exception), ...$exception->details],
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', is_string($requestId) ? $requestId : '');
            });
            $exceptions->render(function (RoleAssignmentException $exception, Request $request): JsonResponse {
                $requestId = $request->attributes->get('orbit.request_id');

                if (! is_string($requestId) || $requestId === '') {
                    $requestId = $request->header('X-Orbit-Request-Id', '');
                }

                return response()
                    ->json([
                        'error' => [
                            'code' => 'node.role_conflict',
                            'message' => $exception->getMessage(),
                            'details' => [],
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', is_string($requestId) ? $requestId : '');
            });
            $exceptions->render(
                fn (ModelNotFoundException $exception, Request $request): JsonResponse => $notFound($request),
            );
            $exceptions->render(
                fn (NotFoundHttpException $exception, Request $request): JsonResponse => $notFound($request),
            );
            $exceptions->render(function (Throwable $exception, Request $request): ?JsonResponse {
                if (! $request->is('api/*')) {
                    return null;
                }

                $request->attributes->set('orbit.error_code', 'gateway.unhandled');
                $requestId = $request->attributes->get('orbit.request_id');

                if (! is_string($requestId) || $requestId === '') {
                    $requestId = $request->header('X-Orbit-Request-Id', '');
                }

                return response()
                    ->json([
                        'error' => [
                            'code' => 'gateway.unhandled',
                            'message' => 'The gateway could not complete the request.',
                            'details' => [],
                        ],
                    ], GatewayExceptionStatus::for($exception, $request->is('api/*')))
                    ->header('X-Orbit-Request-Id', is_string($requestId) ? $requestId : '');
            });
            $exceptions->shouldRenderJsonWhen(
                fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
            );
        },
    )
    ->create();
