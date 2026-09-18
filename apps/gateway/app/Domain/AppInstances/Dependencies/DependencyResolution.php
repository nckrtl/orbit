<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Dependencies;

final readonly class DependencyResolution
{
    public function __construct(
        /** Opaque, graph-local locator; includes installation path or peer context where applicable. */
        public string $id,
        public DependencyIdentity $package,
        public string $version,
        /** Reachable from regular root requirements. Independent of development reachability. */
        public bool $regular,
        public bool $development,
        /** Credential-free source revision; never an authenticated URL. */
        public ?string $sourceReference = null,
        public ?string $integrity = null,
    ) {}
}
