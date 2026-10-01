<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Doctor\InstalledPackageInventory;
use App\Domain\Tools\ToolInventoryScan;
use App\Domain\Tools\ToolInventoryScanState;
use App\Domain\Tools\ToolManagerName;
use App\Models\Node;

/**
 * A reachable Node whose package managers contribute no Doctor findings.
 * Tests that are not about discovery bind this so they do not open SSH.
 */
final class UnsupportedPackageInventory implements InstalledPackageInventory
{
    public function inspect(Node $node): array
    {
        return [
            new ToolInventoryScan(ToolManagerName::Brew, ToolInventoryScanState::Unsupported, []),
            new ToolInventoryScan(ToolManagerName::BrewCask, ToolInventoryScanState::Unsupported, []),
            new ToolInventoryScan(ToolManagerName::Vp, ToolInventoryScanState::Unsupported, []),
        ];
    }
}
