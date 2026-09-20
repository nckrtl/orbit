<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Models\AppInstance;
use App\Models\Node;

final readonly class ServiceMetricsNode
{
    /**
     * @param  list<AppInstance>  $instances
     * @param  list<string>  $hosts
     */
    public function __construct(
        public Node $node,
        public bool $caddy,
        public bool $fpm,
        public array $instances = [],
        public array $hosts = [],
    ) {}
}
