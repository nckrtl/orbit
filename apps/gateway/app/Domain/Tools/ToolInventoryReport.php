<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use InvalidArgumentException;

/**
 * The read-only inventory for one Node.
 * Managers stay in brew, brew-cask, vp order. Nothing here is stored.
 */
final readonly class ToolInventoryReport
{
    /**
     * @param  list<ToolInventoryScan>  $managers
     */
    public function __construct(
        public int $nodeId,
        public string $observedAt,
        public array $managers,
    ) {
        if ($nodeId < 1) {
            throw new InvalidArgumentException('A tool inventory needs a node.');
        }

        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/D', $observedAt) !== 1) {
            throw new InvalidArgumentException('A tool inventory observation time must be UTC.');
        }

        $expected = [ToolManagerName::Brew, ToolManagerName::BrewCask, ToolManagerName::Vp];

        if (count($managers) !== count($expected)) {
            throw new InvalidArgumentException('A tool inventory returns brew, brew-cask, and vp.');
        }

        foreach ($expected as $index => $manager) {
            if ($managers[$index]->manager !== $manager) {
                throw new InvalidArgumentException('A tool inventory returns brew, brew-cask, and vp.');
            }
        }
    }
}
