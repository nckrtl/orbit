<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

/**
 * Facts the Gateway observed on the machine after it bootstrapped a Node.
 */
final readonly class NodeObservation
{
    public function __construct(
        public string $architecture,
        public ?string $osVersion = null,
    ) {}
}
