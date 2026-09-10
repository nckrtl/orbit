<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Sqlite;

use App\Models\Node;

final readonly class SqliteSeedPlacement
{
    public function __construct(
        public int $appInstanceId,
        public string $environment,
        public string $basePath,
        public string $executionUser,
        public Node $node,
    ) {}
}
