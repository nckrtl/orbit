<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Domain\Tools\VersionConstraint;

final readonly class AppInstancePhpVersionCatalog
{
    /** @return non-empty-list<string> */
    public function versions(): array
    {
        return ['8.5', '8.4'];
    }

    public function select(?string $constraint): string
    {
        if ($constraint === null) {
            return $this->versions()[0];
        }

        $constraints = new VersionConstraint;

        if (! $constraints->isValid($constraint)) {
            throw new \InvalidArgumentException('The Composer PHP constraint is invalid.');
        }

        foreach ($this->versions() as $version) {
            if ($constraints->allows($version.'.0', $constraint)) {
                return $version;
            }
        }

        throw new \InvalidArgumentException('No Orbit-supported PHP version satisfies the Composer constraint.');
    }
}
