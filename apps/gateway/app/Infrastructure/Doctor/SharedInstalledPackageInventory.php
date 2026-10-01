<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\InstalledPackageInventory;
use App\Domain\Tools\ToolInventoryScan;
use App\Domain\Tools\ToolManagerName;
use App\Infrastructure\Tools\HomebrewInventoryInspector;
use App\Infrastructure\Tools\ToolCommandBudget;
use App\Infrastructure\Tools\VpInventoryInspector;
use App\Models\Node;
use LogicException;

/**
 * Reads installed packages through the shared Homebrew and Vite+ inspectors.
 * The read matches tool:scan: no manager lock, no Tool row, and no stored inventory.
 */
final readonly class SharedInstalledPackageInventory implements InstalledPackageInventory
{
    /** Matches the other Doctor SSH reads, so one hung package command cannot spend the request deadline. */
    private const float COMMAND_TIMEOUT_SECONDS = 30.0;

    public function __construct(
        private HomebrewInventoryInspector $homebrew,
        private VpInventoryInspector $vp,
        private ToolCommandBudget $budget,
    ) {}

    public function inspect(Node $node): array
    {
        return $this->budget->limit(self::COMMAND_TIMEOUT_SECONDS, fn (): array => $this->read($node));
    }

    /** @return list<ToolInventoryScan> */
    private function read(Node $node): array
    {
        $byManager = [];

        foreach ([...$this->homebrew->inspect($node), $this->vp->inspect($node)] as $scan) {
            $byManager[$scan->manager->value] = $scan;
        }

        $ordered = [];

        foreach ([ToolManagerName::Brew, ToolManagerName::BrewCask, ToolManagerName::Vp] as $manager) {
            $scan = $byManager[$manager->value] ?? null;

            if (! $scan instanceof ToolInventoryScan || $scan->manager !== $manager) {
                throw new LogicException('The tool inventory scan did not return every manager.');
            }

            $ordered[] = $scan;
        }

        return $ordered;
    }
}
