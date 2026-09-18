<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Dependencies;

use InvalidArgumentException;

final readonly class DependencyGraph
{
    /**
     * @param  list<DependencyResolution>  $resolutions
     * @param  list<DependencyRequirement>  $requirements
     */
    public function __construct(
        public DependencyEcosystem $ecosystem,
        public array $resolutions,
        public array $requirements,
    ) {
        $ids = [];

        foreach ($resolutions as $resolution) {
            if ($resolution->package->ecosystem !== $ecosystem) {
                throw new InvalidArgumentException('A resolution must belong to the graph ecosystem.');
            }

            if ($resolution->id === '' || isset($ids[$resolution->id])) {
                throw new InvalidArgumentException('Resolution IDs must be nonempty and unique within a graph.');
            }

            $ids[$resolution->id] = true;
        }

        foreach ($requirements as $requirement) {
            foreach ([$requirement->from, $requirement->to] as $id) {
                if ($id !== null && ! isset($ids[$id])) {
                    throw new InvalidArgumentException('Requirement endpoints must reference graph resolutions.');
                }
            }
        }
    }
}
