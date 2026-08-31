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

    public function resolve(?LegacyNodeSettings $settings, ManagedUserAccount $account): EffectiveStorageRoots
    {
        $instanceDefault = $this->catalog->instanceDefault($account);
        $worktreeDefault = $this->catalog->worktreeDefault($account);

        if (! $instanceDefault instanceof StoragePath || ! $worktreeDefault instanceof StoragePath) {
            throw new ResourceOperationException(
                'node.managed_user_unavailable',
                'Managed user account is unavailable.',
            );
        }

        $instancePath = $settings?->instancePath;
        $worktreePath = $settings?->worktreePath;
        $instance = $instancePath === null ? $instanceDefault : StoragePath::parse($instancePath);
        $worktree = $worktreePath === null ? $worktreeDefault : StoragePath::parse($worktreePath);

        return new EffectiveStorageRoots($instance, $worktree);
    }

    public function resolveApps(
        ?NodeSettingsData $settings,
        ?LegacyNodeSettings $legacy,
        ManagedUserAccount $account,
    ): EffectiveStorageRoots {
        $defaults = $this->resolve($legacy, $account);
        $normalized = $this->normalizer->normalize($settings);
        $appsPath = $normalized?->appsPath() ?? $legacy?->instancePath;
        $apps = $appsPath === null ? $defaults->instance : StoragePath::parse($appsPath);

        return new EffectiveStorageRoots($apps, $defaults->worktree);
    }
}
