<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Data\Nodes\NodeSettingsData;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Shared\ResourceOperationException;

final readonly class StorageRootResolver
{
    public function __construct(
        private NodeSettingsNormalizer $normalizer,
        private ProtectedPathCatalog $catalog,
    ) {}

    public function resolve(?NodeSettingsData $settings, ManagedUserAccount $account): EffectiveStorageRoots
    {
        $normalized = $this->normalizer->normalize($settings);
        $instanceDefault = $this->catalog->instanceDefault($account);
        $worktreeDefault = $this->catalog->worktreeDefault($account);

        if (! $instanceDefault instanceof StoragePath || ! $worktreeDefault instanceof StoragePath) {
            throw new ResourceOperationException(
                'node.managed_user_unavailable',
                'Managed user account is unavailable.',
            );
        }

        $instancePath = $normalized instanceof NodeSettingsData ? $normalized->instancePath() : null;
        $worktreePath = $normalized instanceof NodeSettingsData ? $normalized->worktreePath() : null;
        $instance = $instancePath === null ? $instanceDefault : StoragePath::parse($instancePath);
        $worktree = $worktreePath === null ? $worktreeDefault : StoragePath::parse($worktreePath);

        return new EffectiveStorageRoots($instance, $worktree);
    }
}
