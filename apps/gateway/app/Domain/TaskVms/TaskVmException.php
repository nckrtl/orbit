<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Shared\ResourceOperationException;
use Throwable;

/** A task VM failure with a stable `task_vm.*` error code. */
final class TaskVmException extends ResourceOperationException
{
    public function __construct(
        string $errorCode,
        string $message = 'The task VM operation failed.',
        int $status = 409,
        ?Throwable $previous = null,
    ) {
        parent::__construct($errorCode, $message, $status, $previous);
    }

    /** The stable code of any failure: its own code, or `task_vm.job_failed` when it has none. */
    public static function codeOf(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof ResourceOperationException, $exception instanceof NodeProvisioningException => $exception->errorCode,
            default => 'task_vm.job_failed',
        };
    }
}
