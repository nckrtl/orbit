<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Models\Node;

/**
 * Reads one installed package for adoption.
 * Implementations must not install, update, remove, bootstrap, repin, or refresh manager metadata,
 * except the documented macOS Homebrew bottle API refresh.
 */
interface SupportsToolAdoption
{
    /**
     * @throws ToolManagerException When the scope is absent, conflicting, or the package cannot be verified.
     */
    public function inspectForAdoption(Node $node, string $package): ToolAdoptionFact;
}
