<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Data\Fleet\DesiredCliReleaseData;

/** The published CLI releases. A lookup never throws: a release it cannot confirm is unavailable with a reason. */
interface CliReleaseCatalog
{
    /**
     * The release named for this commit, with the SHA-256 of each platform binary from its `SHA256SUMS`, once
     * its tag points at the commit and every asset is published.
     */
    public function find(string $commit, CliReleaseName $release): DesiredCliReleaseData;
}
