<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Instances\Removal\InstanceRemovalException;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\NodeRemovalException;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleOperationException;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolOperationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\OriginMismatchException;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class GatewayExceptionStatus
{
    public static function for(Throwable $exception, bool $apiRequest = false): int
    {
        if ($apiRequest && ! self::hasApiExceptionRenderer($exception)) {
            return Response::HTTP_INTERNAL_SERVER_ERROR;
        }

        return match (true) {
            $exception instanceof InstanceRemovalException,
            $exception instanceof FirewallOperationException,
            $exception instanceof ResourceOperationException,
            $exception instanceof ToolOperationException => $exception->status,
            $exception instanceof ScheduleOperationException => self::scheduleStatus($exception),
            $exception instanceof RoleAssignmentException,
            $exception instanceof NodeRoleValidationException => Response::HTTP_UNPROCESSABLE_ENTITY,
            $exception instanceof NodeRoleOperationException,
            $exception instanceof NodeProvisioningException,
            $exception instanceof NodeRemovalException,
            $exception instanceof RuntimeConvergenceException,
            $exception instanceof ProcessOperationException => Response::HTTP_BAD_GATEWAY,
            $exception instanceof ValidationException => $exception->status,
            $exception instanceof AuthenticationException => Response::HTTP_UNAUTHORIZED,
            $exception instanceof AuthorizationException => $exception->status() ?? Response::HTTP_FORBIDDEN,
            $exception instanceof OriginMismatchException => Response::HTTP_FORBIDDEN,
            $exception instanceof TokenMismatchException => 419,
            $exception instanceof BackedEnumCaseNotFoundException,
            $exception instanceof ModelNotFoundException,
            $exception instanceof RecordNotFoundException,
            $exception instanceof RecordsNotFoundException => Response::HTTP_NOT_FOUND,
            $exception instanceof RequestExceptionInterface => Response::HTTP_BAD_REQUEST,
            $exception instanceof HttpResponseException => $exception->getResponse()->getStatusCode(),
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

    private static function hasApiExceptionRenderer(Throwable $exception): bool
    {
        return $exception instanceof InstanceRemovalException
            || $exception instanceof FirewallOperationException
            || $exception instanceof NodeProvisioningException
            || $exception instanceof NodeRemovalException
            || $exception instanceof NodeRoleOperationException
            || $exception instanceof NodeRoleValidationException
            || $exception instanceof ProcessOperationException
            || $exception instanceof ResourceOperationException
            || $exception instanceof RoleAssignmentException
            || $exception instanceof RuntimeConvergenceException
            || $exception instanceof ScheduleOperationException
            || $exception instanceof ToolOperationException
            || $exception instanceof ValidationException
            || $exception instanceof AuthenticationException
            || $exception instanceof AuthorizationException
            || $exception instanceof HttpExceptionInterface
            || $exception instanceof BackedEnumCaseNotFoundException
            || $exception instanceof ModelNotFoundException
            || $exception instanceof RecordNotFoundException
            || $exception instanceof RecordsNotFoundException
            || $exception instanceof NotFoundHttpException;
    }

    private static function scheduleStatus(ScheduleOperationException $exception): int
    {
        return match ($exception->error) {
            ScheduleErrorCode::ArtifactConflict,
            ScheduleErrorCode::RetryConflict,
            ScheduleErrorCode::StateInvalid,
            ScheduleErrorCode::TargetUnavailable,
            ScheduleErrorCode::TargetInUse => Response::HTTP_CONFLICT,
            ScheduleErrorCode::CalendarInvalid,
            ScheduleErrorCode::NameInvalid,
            ScheduleErrorCode::TargetInvalid,
            ScheduleErrorCode::CommandInvalid,
            ScheduleErrorCode::TimeoutInvalid => Response::HTTP_UNPROCESSABLE_ENTITY,
            default => Response::HTTP_BAD_GATEWAY,
        };
    }
}
