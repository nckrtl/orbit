<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Domain\Tools\ToolInventoryScan;
use App\Models\Node;

/**
 * The read-only Homebrew and Vite+ inventory Doctor compares with Tool rows.
 * The production reader is the shared package inspector. Nothing here is stored.
 */
interface InstalledPackageInventory
{
    /**
     * Managers stay in brew, brew-cask, vp order.
     *
     * @return list<ToolInventoryScan>
     */
    public function inspect(Node $node): array;
}
