<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

final readonly class LinuxUserName
{
    public static function isValid(string $name): bool
    {
        return preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/', $name) === 1;
    }
}
