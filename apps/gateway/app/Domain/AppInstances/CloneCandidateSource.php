<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\Node;

final readonly class CloneCandidateSource
{
    public function __construct(
        public int $appInstanceId,
        public string $environment,
        public string $basePath,
        public string $executionUser,
        public string $branch,
        public string $commit,
        public Node $node,
    ) {}
}
