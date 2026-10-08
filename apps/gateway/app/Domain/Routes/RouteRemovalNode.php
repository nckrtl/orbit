<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Models\Node;

/** A Node that a Route removal changes, with the removal steps that act on it. */
final readonly class RouteRemovalNode
{
    /** @param list<RouteRemovalStep> $steps */
    public function __construct(
        public Node $node,
        public array $steps,
    ) {}
}
