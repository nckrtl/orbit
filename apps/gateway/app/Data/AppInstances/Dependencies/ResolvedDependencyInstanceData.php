<?php

declare(strict_types=1);

namespace App\Data\AppInstances\Dependencies;

use Spatie\LaravelData\Data;

final class ResolvedDependencyInstanceData extends Data
{
    public function __construct(
        public readonly string $domain,
        public readonly int $instance_id,
        public readonly int $app_id,
        public readonly int $node_id,
        public readonly string $environment,
    ) {}
}
