<?php

declare(strict_types=1);

namespace App\Domain\Instances\DatabaseClone;

use App\Models\Instance;

/**
 * Copies a SQLite database between development checkouts with SQLite's backup API, on one Node
 * or between Nodes. A failure throws `instance.database_clone_failed`.
 */
interface InstanceSqliteCloner
{
    public function copy(Instance $source, string $sourcePath, Instance $target, string $targetPath): void;

    /** Delete a copied file. A missing file or checkout counts as removed. */
    public function remove(Instance $owner, string $path): void;
}
