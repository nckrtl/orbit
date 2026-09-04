<?php

declare(strict_types=1);

namespace App\Domain\Routes;

final readonly class RoutePlacement
{
    public function __construct(
        public ?int $nodeId,
        public ?int $clusterId,
        public ?string $effectiveTld,
    ) {}
}
