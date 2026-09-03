<?php

declare(strict_types=1);

namespace App\Exceptions\E2E;

use App\E2E\Value\ColdTopologyCleanupResult;
use RuntimeException;
use Throwable;

final class ColdTopologyCleanupException extends RuntimeException
{
    public function __construct(
        public readonly ColdTopologyCleanupResult $cleanup,
        Throwable $constructionFailure,
    ) {
        parent::__construct(
            'Cold topology construction failed and exact cleanup was refused: '.implode('; ', $cleanup->refused),
            previous: $constructionFailure,
        );
    }
}
