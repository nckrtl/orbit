<?php

declare(strict_types=1);

namespace App\Services\SelfUpdate;

use InvalidArgumentException;

/**
 * Where Orbit's release assets live. The CLI builds every download URL itself from its own configuration, so a
 * Gateway can name which release to install but never where to download it from.
 */
final readonly class ReleaseLocation
{
    public const string Default = 'https://github.com/nckrtl/orbit/releases/download';

    public string $downloads;

    public function __construct(string $downloads = self::Default)
    {
        $downloads = rtrim($downloads, '/');

        if (! str_starts_with($downloads, 'https://') || preg_match('/[\x00-\x20\x7F?#]/', $downloads) === 1) {
            throw new InvalidArgumentException('The release download base must be an HTTPS URL.');
        }

        $this->downloads = $downloads;
    }

    public function cliAssetName(string $version, string $platform): string
    {
        return 'orbit-'.$version.'-'.$platform;
    }

    public function cliAssetUrl(string $version, string $platform): string
    {
        return $this->downloads.'/cli-v'.$version.'/'.$this->cliAssetName($version, $platform);
    }

    public function cliChecksumsUrl(string $version): string
    {
        return $this->downloads.'/cli-v'.$version.'/SHA256SUMS';
    }

    public function agentAssetName(string $version, string $platform): string
    {
        return 'orbit-agent-'.$version.'-'.$platform;
    }

    public function agentAssetUrl(string $version, string $platform): string
    {
        return $this->downloads.'/agent-v'.$version.'/'.$this->agentAssetName($version, $platform);
    }

    public function agentChecksumsUrl(string $version): string
    {
        return $this->downloads.'/agent-v'.$version.'/SHA256SUMS';
    }
}
