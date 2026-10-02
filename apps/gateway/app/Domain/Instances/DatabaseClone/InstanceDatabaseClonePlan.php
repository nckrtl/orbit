<?php

declare(strict_types=1);

namespace App\Domain\Instances\DatabaseClone;

use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Instance;

/** The default Instance's `DB` database that a new development Instance gets a copy of. */
final readonly class InstanceDatabaseClonePlan
{
    public function __construct(
        public Instance $source,
        public DatabaseConnection $connection,
        public ?DatabaseServer $server,
        public ?string $relativePath,
    ) {}
}
