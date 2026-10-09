<?php

declare(strict_types=1);

namespace Tests\Support\Fleet;

use App\Data\Fleet\DesiredCliReleaseData;
use App\Data\Fleet\FleetReleaseAssetData;
use App\Domain\Fleet\CliReleaseCatalog;
use App\Domain\Fleet\CliReleaseName;

/**
 * Publishes a release with one Linux binary, or reports it pending while `$available` is false or the commit is
 * one of `$missing`.
 */
final class FakeCliReleaseCatalog implements CliReleaseCatalog
{
    /** @param  list<string>  $missing */
    public function __construct(public bool $available = true, public string $sha256 = '', public array $missing = []) {}

    public function find(string $commit, CliReleaseName $release): DesiredCliReleaseData
    {
        if (! $this->available || in_array($commit, $this->missing, true)) {
            // CI publishes the release minutes after the Gateway deploys the commit.
            return DesiredCliReleaseData::pending($release, $commit);
        }

        $base = 'https://github.com/nckrtl/orbit/releases/download/'.$release->tag();

        return DesiredCliReleaseData::available($release, $commit, $base.'/SHA256SUMS', [
            new FleetReleaseAssetData('linux-x86_64', $release->assetName('linux-x86_64'), $base.'/'.$release->assetName('linux-x86_64'), $this->sha256 !== '' ? $this->sha256 : str_repeat('c', 64)),
        ]);
    }
}
