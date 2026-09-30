<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use InvalidArgumentException;

/**
 * One package fact from a completed inventory read.
 * A formula and a cask keep separate manager identities.
 */
final readonly class ToolInventoryPackage
{
    public const string SUPPORTED = 'supported';

    public const string UNSUPPORTED = 'unsupported';

    public const string BLOCK_PROTECTED = 'protected';

    public const string BLOCK_DEPENDENCY = 'dependency';

    public const string BLOCK_ARTIFACT = 'unsupported_artifact';

    public const string BLOCK_SOURCE = 'unsupported_source';

    public const string BLOCK_BOTTLE = 'bottle_unavailable';

    public const string BLOCK_VERSION = 'version_unreadable';

    public const string BLOCK_AUTHORIZATION = 'authorization_required';

    public function __construct(
        public ToolManagerName $manager,
        public string $package,
        public ToolInventoryPackageKind $packageKind,
        public ?string $installedVersion,
        public bool $dependency,
        public bool $registered,
        public ?int $toolId,
        public string $adoption,
        public ?string $adoptionBlock,
    ) {
        if ($package === '' || strlen($package) > 255) {
            throw new InvalidArgumentException('A Homebrew inventory package name is invalid.');
        }

        if ($installedVersion !== null && ($installedVersion === '' || strlen($installedVersion) > 255)) {
            throw new InvalidArgumentException('A Homebrew inventory version must be normalized.');
        }

        if ($adoption !== self::SUPPORTED && $adoption !== self::UNSUPPORTED) {
            throw new InvalidArgumentException('A Homebrew inventory adoption value is invalid.');
        }

        if ($adoption === self::SUPPORTED && $adoptionBlock !== null) {
            throw new InvalidArgumentException('A supported Homebrew inventory package has no adoption block.');
        }

        if ($adoption === self::UNSUPPORTED && ($adoptionBlock === null || $adoptionBlock === '')) {
            throw new InvalidArgumentException('An unsupported Homebrew inventory package requires an adoption block.');
        }

        if (
            $adoptionBlock !== null
            && ! in_array($adoptionBlock, [
                self::BLOCK_PROTECTED,
                self::BLOCK_DEPENDENCY,
                self::BLOCK_ARTIFACT,
                self::BLOCK_SOURCE,
                self::BLOCK_BOTTLE,
                self::BLOCK_VERSION,
                self::BLOCK_AUTHORIZATION,
            ], true)
        ) {
            throw new InvalidArgumentException('A Homebrew inventory adoption block is invalid.');
        }

        if ($registered !== ($toolId !== null)) {
            throw new InvalidArgumentException('A Homebrew inventory registration must match its Tool id.');
        }

        if ($packageKind === ToolInventoryPackageKind::Cask && $dependency) {
            throw new InvalidArgumentException('A Homebrew cask inventory package is not a dependency.');
        }
    }
}
