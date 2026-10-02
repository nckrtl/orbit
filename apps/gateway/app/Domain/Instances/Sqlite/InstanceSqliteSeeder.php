<?php

declare(strict_types=1);

namespace App\Domain\Instances\Sqlite;

use SensitiveParameter;

interface InstanceSqliteSeeder
{
    public function seed(
        SqliteSeedPlacement $source,
        SqliteSeedPlacement $target,
        #[SensitiveParameter]
        string $sourcePath,
    ): SqliteSeedResult;

    /** Abandon the recorded seed and its owned temporary files without removing the installed database. */
    public function abandon(
        SqliteSeedPlacement $source,
        SqliteSeedPlacement $target,
        #[SensitiveParameter]
        string $sourcePath,
    ): bool;
}
