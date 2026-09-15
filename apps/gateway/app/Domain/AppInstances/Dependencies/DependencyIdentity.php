<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Dependencies;

final readonly class DependencyIdentity
{
    public function __construct(
        public DependencyEcosystem $ecosystem,
        /** Canonical package name supplied by the ecosystem parser, not a requirement alias. */
        public string $name,
    ) {}
}
