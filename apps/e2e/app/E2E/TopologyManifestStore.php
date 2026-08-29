<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\TopologyTarget;

final readonly class TopologyManifestStore
{
    public function __construct(
        private AtomicJsonStore $store,
    ) {}

    public function read(TopologyTarget $target): ?FeatureTopology
    {
        $value = $this->store->read('topologies/'.$target->issue.'.json');

        if ($value === null) {
            return null;
        }

        $topology = FeatureTopology::fromArray($value);

        if ($topology->target->issue !== $target->issue) {
            throw new \InvalidArgumentException('The topology manifest target does not match its path.');
        }

        return $topology;
    }

    public function write(FeatureTopology $topology): void
    {
        $this->store->write('topologies/'.$topology->target->issue.'.json', $topology->toArray());
    }
}
