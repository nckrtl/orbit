<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use InvalidArgumentException;

/**
 * The cask policy result for one metadata document.
 * A checksum failure is reported to discovery as an unsupported artifact.
 */
final readonly class HomebrewCaskAssessment
{
    public function __construct(
        public ?string $block,
        public ?string $discoveryBlock,
        public string $step,
        public string $message,
        public ?string $version,
    ) {
        if ($block === null && ($discoveryBlock !== null || ! is_string($version) || $version === '')) {
            throw new InvalidArgumentException('A supported Homebrew cask assessment requires a version.');
        }

        if ($block !== null && ($discoveryBlock === null || $discoveryBlock === '')) {
            throw new InvalidArgumentException('A refused Homebrew cask assessment requires a discovery block.');
        }
    }

    public function supported(): bool
    {
        return $this->block === null;
    }
}
