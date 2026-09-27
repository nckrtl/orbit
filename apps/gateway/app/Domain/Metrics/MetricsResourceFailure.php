<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Shared\ResourceOperationException;
use Throwable;

final class MetricsResourceFailure
{
    public static function find(Throwable $exception): ?ResourceOperationException
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof ResourceOperationException) {
                return $cause;
            }
        }

        return null;
    }
}
