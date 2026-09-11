<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use RuntimeException;
use Throwable;

final class ScheduleOperationException extends RuntimeException
{
    public readonly string $errorCode;

    public function __construct(
        public readonly string $step,
        public readonly ScheduleErrorCode $error,
        string $message,
        ?Throwable $previous = null,
    ) {
        $this->errorCode = $error->value;
        parent::__construct($message, 0, $previous);
    }
}
