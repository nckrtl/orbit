<?php

declare(strict_types=1);

namespace App\Domain\Instances\Dependencies;

use RuntimeException;

final class DependencyParseException extends RuntimeException
{
    public function __construct(public readonly string $errorCode = 'dependencies.invalid_composer_input')
    {
        parent::__construct($errorCode);
    }
}
