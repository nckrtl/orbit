<?php

declare(strict_types=1);

namespace App\Domain\Instances\Sqlite;

use App\Models\Node;

final readonly class SqliteSeedPlacement
{
    public function __construct(
        public int $instanceId,
        public string $environment,
        public string $basePath,
        public string $executionUser,
        public Node $node,
        public ?string $operationId = null,
    ) {}
}
