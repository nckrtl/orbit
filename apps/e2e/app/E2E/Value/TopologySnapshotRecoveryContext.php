<?php

declare(strict_types=1);

namespace App\E2E\Value;

use App\E2E\LegacyTopologySnapshotRecovery;
use App\E2E\TopologySnapshotRebuilder;

final readonly class TopologySnapshotRecoveryContext
{
    public function __construct(
        public string $source,
        public LegacyTopologySnapshotRecovery $recovery,
        public TopologySnapshotRebuilder $rebuilder,
    ) {}
}
