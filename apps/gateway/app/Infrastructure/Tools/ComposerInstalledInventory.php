<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\ToolManagerException;
use App\Infrastructure\Processes\CommandResult;

final readonly class ComposerInstalledInventory
{
    /**
     * @param  array<string, list<string>>  $versionsByPackage
     */
    public function __construct(
        private array $versionsByPackage,
        private ?CommandResult $result = null,
    ) {}

    public function versionFor(string $package): ?string
    {
        $versions = $this->versionsByPackage[$package] ?? [];

        if ($versions === []) {
            return null;
        }

        if (count($versions) !== 1) {
            throw new ToolManagerException(
                step: 'installed-version',
                message: 'The Composer installed version probe returned malformed output.',
                result: $this->result,
            );
        }

        return $versions[0];
    }
}
