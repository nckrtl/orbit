<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Models\AppRuntimeMigration;
use App\Models\Node;

interface AppRuntimeMigrationProjector
{
    /** Snapshots stay on the Node; return only bounded ownership/state observations.
     * @return array<string,mixed>
     */
    public function prepare(Node $node, AppRuntimeMigration $migration): array;

    public function activate(Node $node, AppRuntimeMigration $migration): void;

    public function rollback(Node $node, AppRuntimeMigration $migration): void;

    public function cleanup(Node $node, AppRuntimeMigration $migration): void;
}
