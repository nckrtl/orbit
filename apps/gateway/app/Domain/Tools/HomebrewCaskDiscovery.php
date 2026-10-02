<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use InvalidArgumentException;

/**
 * One installed official-cask observation. Discovery lists unsupported casks and does not record a Tool.
 */
final readonly class HomebrewCaskDiscovery
{
    public const string SUPPORTED = 'supported';

    public const string UNSUPPORTED = 'unsupported';

    public const string BLOCK_ARTIFACT = 'unsupported_artifact';

    public const string BLOCK_SOURCE = 'unsupported_source';

    public const string BLOCK_AUTHORIZATION = 'authorization_required';

    public const string BLOCK_VERSION = 'version_unreadable';

    public function __construct(
        public string $package,
        public ?string $installedVersion,
        public string $adoption,
        public ?string $adoptionBlock,
    ) {
        if ($adoption === self::SUPPORTED && $adoptionBlock !== null) {
            throw new InvalidArgumentException('A supported Homebrew cask has no adoption block.');
        }

        if ($adoption === self::UNSUPPORTED && ($adoptionBlock === null || $adoptionBlock === '')) {
            throw new InvalidArgumentException('An unsupported Homebrew cask requires an adoption block.');
        }
    }
}
