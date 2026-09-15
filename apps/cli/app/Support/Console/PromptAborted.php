<?php

declare(strict_types=1);

namespace App\Support\Console;

use RuntimeException;
use Throwable;

final class PromptAborted extends RuntimeException
{
    public function __construct(
        string $message = 'Input cancelled.',
        public readonly string $reason = 'cancelled',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
