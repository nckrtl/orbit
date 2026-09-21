<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use RuntimeException;

class AgentDriverException extends RuntimeException
{
    public static function unsupported(string $operation): self
    {
        return new self('The agent driver does not support '.$operation.'.');
    }
}
