<?php

declare(strict_types=1);

namespace App\Domain\Hibernation;

final readonly class RuntimeDependencyState
{
    public function __construct(
        public bool $vendorReconstructable,
        public bool $vendorPresent,
        public bool $nodeModulesReconstructable,
        public bool $nodeModulesPresent,
        public ?int $sourceTreeLastActivityUnix = null,
    ) {}

    public function prunableVendor(): bool
    {
        return $this->vendorReconstructable && $this->vendorPresent;
    }

    public function prunableNodeModules(): bool
    {
        return $this->nodeModulesReconstructable && $this->nodeModulesPresent;
    }

    public function restorableVendor(): bool
    {
        return $this->vendorReconstructable && ! $this->vendorPresent;
    }

    public function restorableNodeModules(): bool
    {
        return $this->nodeModulesReconstructable && ! $this->nodeModulesPresent;
    }

    public function hasPrunable(): bool
    {
        return $this->prunableVendor() || $this->prunableNodeModules();
    }

    public function hasRestorable(): bool
    {
        return $this->restorableVendor() || $this->restorableNodeModules();
    }
}
