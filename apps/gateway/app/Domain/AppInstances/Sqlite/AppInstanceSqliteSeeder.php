<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Sqlite;

use SensitiveParameter;

interface AppInstanceSqliteSeeder
{
    public function seed(
        SqliteSeedPlacement $source,
        SqliteSeedPlacement $target,
        #[SensitiveParameter]
        string $sourcePath,
    ): SqliteSeedResult;
}
