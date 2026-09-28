<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Data\Nodes\NodeSettingsData;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;

final readonly class ConfiguredStoragePathValidator
{
    public function __construct(
        private NodeSettingsNormalizer $normalizer,
        private StorageRootResolver $roots,
        private ProtectedPathCatalog $catalog,
    ) {}

    public function validateGrammar(?NodeSettingsData $settings): void
    {
        $normalized = $this->normalizer->normalize($settings);

        if (! $normalized instanceof NodeSettingsData) {
            return;
        }

        foreach (['apps' => $normalized->appsPath()] as $field => $path) {
            if ($path === null) {
                continue;
            }

            if (! StoragePath::tryParse($path) instanceof StoragePath) {
                throw new ResourceOperationException(
                    errorCode: 'node.settings_path_invalid',
                    message: "The {$field} storage path is not a normalized absolute path.",
                );
            }
        }
    }

    public function validateEffective(
        ?NodeSettingsData $settings,
        Node $node,
        ManagedUserAccount $account,
    ): StoragePath {
        $this->validateGrammar($settings);
        $root = $this->roots->resolveApps($settings, $account);

        $this->assertAllowedRoot($root, $account, $node, 'apps');

        return $root;
    }

    private function assertAllowedRoot(
        StoragePath $path,
        ManagedUserAccount $account,
        Node $node,
        string $field,
    ): void {
        if ($this->catalog->isProtected($path, $account)) {
            throw new ResourceOperationException(
                errorCode: 'node.settings_path_protected',
                message: "The {$field} storage path is protected.",
            );
        }

        foreach ($this->managedCheckouts($node) as $checkout) {
            if ($path->equals($checkout) || $path->isInside($checkout)) {
                throw new ResourceOperationException(
                    errorCode: 'node.settings_path_managed',
                    message: "The {$field} storage path overlaps a managed checkout.",
                );
            }
        }
    }

    /** @return list<StoragePath> */
    private function managedCheckouts(Node $node): array
    {
        $paths = [];

        foreach (Instance::query()->where('node_id', $node->id)->get(['checkout_path']) as $appInstance) {
            $path = StoragePath::tryParse($appInstance->checkout_path);

            if ($path instanceof StoragePath) {
                $paths[] = $path;
            }
        }

        return $paths;
    }
}
