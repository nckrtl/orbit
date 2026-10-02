<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use RuntimeException;

class AgentDriverException extends RuntimeException
{
    /** The conversation created before this failure, when a retry must reuse it. */
    public ?string $createdThreadId = null;

    public static function unsupported(string $operation): self
    {
        return new self('The agent driver does not support '.$operation.'.');
    }
}
