<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use InvalidArgumentException;

/**
 * One manager's inventory read.
 * Absent, unsupported, incomplete, and conflicting scans carry no package facts.
 */
final readonly class ToolInventoryScan
{
    /**
     * @param  list<ToolInventoryPackage>  $packages
     */
    public function __construct(
        public ToolManagerName $manager,
        public ToolInventoryScanState $scanState,
        public array $packages,
    ) {
        if ($scanState !== ToolInventoryScanState::Complete && $packages !== []) {
            throw new InvalidArgumentException('An unfinished Homebrew inventory has no packages.');
        }

        foreach ($packages as $package) {
            if ($package->manager !== $manager) {
                throw new InvalidArgumentException('A Homebrew inventory package must belong to its manager.');
            }
        }
    }
}
