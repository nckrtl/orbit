<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Dependencies;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DependencySnapshot
{
    public function __construct(
        public DependencyEcosystem $ecosystem,
        public DependencySource $source,
        public DateTimeImmutable $observedAt,
        /** Null means verified absence; an empty graph means a present project with no packages. */
        public ?DependencyGraph $graph,
    ) {
        if ($graph !== null && $graph->ecosystem !== $ecosystem) {
            throw new InvalidArgumentException('The snapshot and graph ecosystems must match.');
        }
    }
}
