<?php

declare(strict_types=1);

namespace App\Domain\Instances\Removal;

use App\Models\InstanceRemoval;
use RuntimeException;
use Throwable;

final class InstanceRemovalException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        public readonly InstanceRemoval $removal,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Instance removal was accepted but remains incomplete.', 0, $previous);
    }
}
