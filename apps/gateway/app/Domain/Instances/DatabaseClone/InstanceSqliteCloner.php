<?php

declare(strict_types=1);

namespace App\Domain\Instances\DatabaseClone;

use App\Models\Instance;

/**
 * Copies a SQLite database between development checkouts, on one Node or between Nodes. On one
 * Node the copy is a reflink taken under SQLite's write lock where the filesystem supports block
 * cloning, and otherwise a snapshot with SQLite's backup API. A failure throws
 * `instance.database_clone_failed`.
 */
interface InstanceSqliteCloner
{
    public function copy(Instance $source, string $sourcePath, Instance $target, string $targetPath): void;

    /** Delete a copied file. A missing file or checkout counts as removed. */
    public function remove(Instance $owner, string $path): void;
}
