<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Data\Nodes\NodeSettingsData;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ConfiguredStoragePathValidator;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\NodeSettingsPatch;
use App\Domain\Nodes\Storage\NodeStorageRootPreparer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;

final readonly class UpdateNodeSettingsAction
{
    public function __construct(
        private NodeSettingsNormalizer $normalizer,
        private ConfiguredStoragePathValidator $validator,
        private ManagedUserAccountResolver $accounts,
        private NodeStorageRootPreparer $preparer,
    ) {}

    public function execute(Node $node, NodeSettingsPatch $patch): Node
    {
        $merged = $patch->merge($this->normalizer->fromStored($node->settings));
        $normalized = $this->normalizer->normalize($merged);
        $this->apply($node, $normalized);

        return $node->refresh();
    }

    public function persistDuringProvisioning(Node $node, ?NodeSettingsData $settings): void
    {
        $this->apply($node, $this->normalizer->normalize($settings));
    }

    private function apply(Node $node, ?NodeSettingsData $normalized): void
    {
        $this->validator->validateGrammar($normalized);

        if ($this->hasActiveAppDevRole($node)) {
            $this->prepareEffectiveRoots($node, $normalized);
            $this->persist($node, $normalized);

            return;
        }

        if ($normalized instanceof NodeSettingsData) {
            $this->inspectConfiguredRoots($node, $normalized);
        }

        $this->persist($node, $normalized);
    }

    private function prepareEffectiveRoots(Node $node, ?NodeSettingsData $normalized): void
    {
        $account = $this->accounts->resolve($node);
        $roots = $this->validator->validateEffective($normalized, $node, $account);
        $this->preparer->prepare($node, $account, $roots);
    }

    private function inspectConfiguredRoots(Node $node, NodeSettingsData $settings): void
    {
        $account = $this->accounts->resolve($node);
        $this->validator->validateEffective($settings, $node, $account);
        $this->inspectExplicitRoots($node, $account, $settings);
    }

    private function persist(Node $node, ?NodeSettingsData $normalized): void
    {
        $node->settings = $this->normalizer->stored($normalized);
        $node->save();
    }

    private function inspectExplicitRoots(Node $node, ManagedUserAccount $account, NodeSettingsData $settings): void
    {
        foreach ([$settings->instancePath(), $settings->worktreePath()] as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            $this->preparer->inspect($node, $account, StoragePath::parse($path));
        }
    }

    private function hasActiveAppDevRole(Node $node): bool
    {
        return $node
            ->roles()
            ->where('role', RoleName::AppDev->value)
            ->where('status', LifecycleStatus::Active->value)
            ->exists();
    }
}
