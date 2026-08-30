<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use RuntimeException;
use Throwable;

final class ResourceOperationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
