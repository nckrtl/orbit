<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class GatewayConfigException extends RuntimeException
{
    public const string CONFIG_NOT_PRIVATE = 'gateway.config_not_private';

    public function __construct(
        string $message,
        ?Throwable $previous = null,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public function isPrivacyFailure(): bool
    {
        return $this->errorCode === self::CONFIG_NOT_PRIVATE;
    }
}
