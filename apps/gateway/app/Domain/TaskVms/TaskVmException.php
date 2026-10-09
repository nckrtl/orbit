<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

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
}
