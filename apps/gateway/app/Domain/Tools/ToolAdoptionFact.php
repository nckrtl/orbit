<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use InvalidArgumentException;

/**
 * One live package read for adoption.
 * A null version and a null block means the package is not installed.
 * A block means adoption is unsupported. A version and no block means it can be registered.
 */
final readonly class ToolAdoptionFact
{
    public function __construct(
        public ?string $installedVersion,
        public ?string $adoptionBlock,
    ) {
        if (
            $installedVersion !== null
            && ($installedVersion === '' || strlen($installedVersion) > 255 || preg_match('/[\x00-\x1F\x7F]/', $installedVersion) === 1)
        ) {
            throw new InvalidArgumentException('An adoption version must be a bounded installed version.');
        }

        if (
            $adoptionBlock !== null
            && ! in_array($adoptionBlock, [
                ToolInventoryPackage::BLOCK_PROTECTED,
                ToolInventoryPackage::BLOCK_DEPENDENCY,
                ToolInventoryPackage::BLOCK_ARTIFACT,
                ToolInventoryPackage::BLOCK_SOURCE,
                ToolInventoryPackage::BLOCK_BOTTLE,
                ToolInventoryPackage::BLOCK_VERSION,
                ToolInventoryPackage::BLOCK_AUTHORIZATION,
            ], true)
        ) {
            throw new InvalidArgumentException('An adoption block is invalid.');
        }
    }

    public function absent(): bool
    {
        return $this->installedVersion === null && $this->adoptionBlock === null;
    }

    public function supported(): bool
    {
        return $this->installedVersion !== null && $this->adoptionBlock === null;
    }
}
