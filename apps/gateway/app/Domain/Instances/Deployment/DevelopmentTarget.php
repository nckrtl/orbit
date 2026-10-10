<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

/** The fetched commit of a default's branch, and whether the old release layout is still on disk. */
final readonly class DevelopmentTarget
{
    public function __construct(
        public string $commit,
        public bool $releasesRemain,
    ) {}
}
