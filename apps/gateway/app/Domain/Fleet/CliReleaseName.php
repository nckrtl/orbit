<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use InvalidArgumentException;

/**
 * The names of one published Orbit CLI release. CI publishes a release for each green `main` commit:
 * version `0.N.0`, where N is `git rev-list --count` of the commit, tag `cli-v0.N.0`, one binary per
 * platform, and `SHA256SUMS` ([CLI binaries](/reference/cli-binaries#published-releases)).
 */
final readonly class CliReleaseName
{
    /** @var list<string> */
    public const array Platforms = ['linux-x86_64', 'linux-aarch64', 'macos-arm64'];

    public const string ChecksumsAsset = 'SHA256SUMS';

    /**
     * The repository paths a CLI binary is built from: the CLI, the SDK it bundles, the builder, and the build
     * workflow. Two commits with the same files here build the CLI from the same code.
     *
     * @var list<string>
     */
    public const array BuildInputs = [
        'apps/cli',
        'packages/php-sdk',
        'bin/orbit-build-cli-binary',
        'bin/orbit-version',
        '.github/workflows/orbit-cli-binary.yml',
    ];

    public function __construct(public int $number)
    {
        if ($number < 1) {
            throw new InvalidArgumentException('A CLI release number is a positive commit count.');
        }
    }

    public function version(): string
    {
        return '0.'.$this->number.'.0';
    }

    public function tag(): string
    {
        return 'cli-v'.$this->version();
    }

    public function assetName(string $platform): string
    {
        if (! in_array($platform, self::Platforms, true)) {
            throw new InvalidArgumentException('Unsupported CLI release platform.');
        }

        return 'orbit-'.$this->version().'-'.$platform;
    }
}
