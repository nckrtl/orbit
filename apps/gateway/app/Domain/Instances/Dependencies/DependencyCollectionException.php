<?php

declare(strict_types=1);

namespace App\Domain\Instances\Dependencies;

use RuntimeException;

final class DependencyCollectionException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
