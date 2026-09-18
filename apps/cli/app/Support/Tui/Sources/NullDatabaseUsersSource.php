<?php

declare(strict_types=1);

namespace App\Support\Tui\Sources;

/** The default DatabaseUsersSource until the Gateway exposes per-connection users. */
final class NullDatabaseUsersSource implements DatabaseUsersSource
{
    public function forConnection(string $slug): ?array
    {
        return null;
    }
}
