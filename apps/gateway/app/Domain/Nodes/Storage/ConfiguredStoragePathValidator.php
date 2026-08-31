<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Data\Nodes\NodeSettingsData;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;

/** @mago-expect lint:kan-defect Path grammar, overlap, and protected-root checks stay in one validator. */
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

        foreach ([
            'instance' => $normalized->instancePath(),
            'worktree' => $normalized->worktreePath(),
        ] as $field => $path) {
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
    ): EffectiveStorageRoots {
        $this->validateGrammar($settings);
        $roots = $this->roots->resolve($settings, $account);

        if ($roots->instance->overlaps($roots->worktree)) {
            throw new ResourceOperationException(
                errorCode: 'node.settings_roots_overlap',
                message: 'The instance and worktree roots must not overlap.',
            );
        }

        $this->assertAllowedRoot($roots->instance, $account, $node, 'instance');
        $this->assertAllowedRoot($roots->worktree, $account, $node, 'worktree');

        return $roots;
    }

    private function assertAllowedRoot(
        StoragePath $path,
        ManagedUserAccount $account,
        Node $node,
        string $field,
    ): void {
        if ($this->catalog->isProtected($path, $account, $field)) {
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

        foreach (Instance::query()->where('node_id', $node->id)->get(['checkout_path']) as $instance) {
            $path = StoragePath::tryParse($instance->checkout_path);

            if ($path instanceof StoragePath) {
                $paths[] = $path;
            }
        }

        $workspaces = Workspace::query()
            ->whereHas('instance', static fn ($query) => $query->where('node_id', $node->id))
            ->get(['checkout_path']);

        foreach ($workspaces as $workspace) {
            $path = StoragePath::tryParse($workspace->checkout_path);

            if ($path instanceof StoragePath) {
                $paths[] = $path;
            }
        }

        return $paths;
    }
}
