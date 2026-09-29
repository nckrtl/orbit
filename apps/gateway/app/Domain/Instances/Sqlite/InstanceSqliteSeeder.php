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
}
