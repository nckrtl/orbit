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

    public function resolveApps(
        ?NodeSettingsData $settings,
        ManagedUserAccount $account,
    ): StoragePath {
        $default = $this->catalog->instanceDefault($account);

        if (! $default instanceof StoragePath) {
            throw new ResourceOperationException(
                'node.managed_user_unavailable',
                'Managed user account is unavailable.',
            );
        }

        $normalized = $this->normalizer->normalize($settings);
        $appsPath = $normalized?->appsPath();

        return $appsPath === null ? $default : StoragePath::parse($appsPath);
    }
}
