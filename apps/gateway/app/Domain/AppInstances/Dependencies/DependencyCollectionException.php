<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Dependencies;

use RuntimeException;

final class DependencyCollectionException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
