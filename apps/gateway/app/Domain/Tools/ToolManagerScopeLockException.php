<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use RuntimeException;

final class ToolManagerScopeLockException extends RuntimeException
{
    public function __construct(
        public readonly int $nodeId,
        public readonly ToolManagerName $manager,
    ) {
        parent::__construct('A shared tool manager mutation is already active.');
    }
}
