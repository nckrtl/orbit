<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Dependencies;

/** Transient parser input; null manifest and lockfile denote verified absence. */
final readonly class DependencyInput
{
    public function __construct(
        public DependencyEcosystem $ecosystem,
        public ?string $manager,
        public ?string $manifest,
        public ?string $lockfile,
        public ?string $lockfileName,
        public DependencySource $source,
    ) {}
}
