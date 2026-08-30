<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Data\Nodes\NodeSettingsData;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ConfiguredStoragePathValidator;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\NodeSettingsPatch;
use App\Domain\Nodes\Storage\NodeStorageRootPreparer;
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

        if ($normalized instanceof NodeSettingsData) {
            $account = $this->accounts->resolve($node);
            $roots = $this->validator->validateEffective($normalized, $node, $account);

            if ($this->hasActiveAppDevRole($node)) {
                $this->preparer->prepare($node, $account, $roots);
            }
        }

        $node->settings = $this->normalizer->stored($normalized);
        $node->save();
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
