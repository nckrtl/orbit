<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Shared\ResourceOperationException;
use Throwable;

final class MetricsFleetReconcileException extends ResourceOperationException
{
    public function __construct(
        public readonly MetricsReconcileComponent $component,
        public readonly int $nodeId,
        string $errorCode,
        string $message,
        int $status,
        Throwable $previous,
        array $details = [],
    ) {
        parent::__construct($errorCode, $message, $status, $previous, $details);
    }
}
