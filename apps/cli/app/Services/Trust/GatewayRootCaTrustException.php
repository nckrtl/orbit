<?php

declare(strict_types=1);

namespace App\Services\Trust;

use RuntimeException;
use Throwable;

final class GatewayRootCaTrustException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
