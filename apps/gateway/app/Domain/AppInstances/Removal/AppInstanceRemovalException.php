<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Removal;

use App\Models\AppInstanceRemoval;
use RuntimeException;
use Throwable;

final class AppInstanceRemovalException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        public readonly AppInstanceRemoval $removal,
        ?Throwable $previous = null,
    ) {
        parent::__construct('AppInstance removal was accepted but remains incomplete.', 0, $previous);
    }
}
