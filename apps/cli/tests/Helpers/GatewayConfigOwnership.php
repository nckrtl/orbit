<?php

declare(strict_types=1);

namespace App\Repositories;

function fileowner(string $path): int|false
{
    if (str_contains($path, 'foreign-owner')) {
        return posix_geteuid() + 1;
    }

    return \fileowner($path);
}
