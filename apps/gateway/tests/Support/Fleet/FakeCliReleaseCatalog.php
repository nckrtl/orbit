<?php

declare(strict_types=1);

namespace Tests\Support\Fleet;

use App\Data\Fleet\DesiredCliReleaseData;
use App\Data\Fleet\FleetReleaseAssetData;
use App\Domain\Fleet\CliReleaseCatalog;
use App\Domain\Fleet\CliReleaseName;

/** Publishes a release with one Linux binary, or reports it pending while `$available` is false. */
final class FakeCliReleaseCatalog implements CliReleaseCatalog
{
    public function __construct(public bool $available = true, public string $sha256 = '') {}

    public function find(string $commit, CliReleaseName $release): DesiredCliReleaseData
    {
        if (! $this->available) {
            // CI publishes the release minutes after the Gateway deploys the commit.
            return DesiredCliReleaseData::pending($release);
        }

        $base = 'https://github.com/nckrtl/orbit/releases/download/'.$release->tag();

        return DesiredCliReleaseData::available($release, $base.'/SHA256SUMS', [
            new FleetReleaseAssetData('linux-x86_64', $release->assetName('linux-x86_64'), $base.'/'.$release->assetName('linux-x86_64'), $this->sha256 !== '' ? $this->sha256 : str_repeat('c', 64)),
        ]);
    }
}
