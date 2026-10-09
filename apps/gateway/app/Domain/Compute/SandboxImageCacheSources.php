<?php

declare(strict_types=1);

namespace App\Domain\Compute;

/** The manifest and lock files that warm the base template caches (ADR 0204). */
interface SandboxImageCacheSources
{
    /**
     * Files for each Project on the UpCloud lane, read from its default branch, and the tools that were left out with the reason.
     *
     * @return array{projects: array<string, array<string, string>>, skipped: array<string, array<string, string>>}
     */
    public function collect(): array;
}
