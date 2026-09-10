<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppInstances\ProductionReleaseLayout;
use App\Domain\AppInstances\Removal\AppInstanceSourceInventory;
use App\Domain\AppInstances\Removal\ProductionAppInstanceContentRetention;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\AppInstanceRemovalMember;

final readonly class RecordedProductionAppInstanceContentRetention implements ProductionAppInstanceContentRetention
{
    public function __construct(
        private ProductionReleaseLayout $releaseLayout,
    ) {}

    public function inventory(AppInstance $appInstance): AppInstanceSourceInventory
    {
        $appInstance->loadMissing('app');
        $root = $appInstance->root ?? $appInstance->app->root;

        if (
            $appInstance->environment !== 'production'
            || $appInstance->checkout_path === ''
            || ! is_string($root)
            || $root === ''
            || ! is_string($appInstance->branch)
            || $appInstance->branch === ''
            || ! is_string($appInstance->starting_commit)
            || $appInstance->starting_commit === ''
        ) {
            $this->conflict($appInstance->name);
        }

        $sourceIdentity = "production:{$appInstance->id}:{$appInstance->node_id}";
        $digest = $this->digest(
            appInstanceId: $appInstance->id,
            appId: $appInstance->app_id,
            nodeId: $appInstance->node_id,
            checkoutPath: $appInstance->checkout_path,
            root: $root,
            branch: $appInstance->branch,
            startingCommit: $appInstance->starting_commit,
            sourceIdentity: $sourceIdentity,
        );

        return new AppInstanceSourceInventory(
            appInstanceId: $appInstance->id,
            layout: $appInstance->source_layout,
            repositoryIdentity: $appInstance->app->repository_identity,
            checkoutPath: $appInstance->checkout_path,
            root: $root,
            branch: $appInstance->branch,
            startingCommit: $appInstance->starting_commit,
            commonRepositoryPath: $appInstance->checkout_path,
            sourceIdentity: $sourceIdentity,
            linkedWorktreePaths: [],
            digest: $digest,
        );
    }

    public function prepare(AppInstanceRemovalMember $member): void
    {
        $this->assertRecorded($member);
    }

    public function revalidate(AppInstanceRemovalMember $member): void
    {
        $this->assertRecorded($member);
    }

    public function finalize(AppInstanceRemovalMember $member): string
    {
        $this->assertRecorded($member);
        $appInstance = AppInstance::query()->with(['app', 'node'])->findOrFail($member->app_instance_id);
        $this->releaseLayout->clearCurrent($appInstance);

        return hash('sha256', "production-retained\0{$member->source_digest}");
    }

    private function assertRecorded(AppInstanceRemovalMember $member): void
    {
        $appInstance = AppInstance::query()->with('app')->find($member->app_instance_id);

        if (! $appInstance instanceof AppInstance || $appInstance->environment !== 'production') {
            $this->conflict($member->name);
        }

        $inventory = $this->inventory($appInstance);

        if (
            $member->app_id !== $appInstance->app_id
            || $member->node_id !== $appInstance->node_id
            || $member->source_layout !== $inventory->layout
            || $member->repository_identity !== $inventory->repositoryIdentity
            || $member->checkout_path !== $inventory->checkoutPath
            || $member->root !== $inventory->root
            || $member->branch !== $inventory->branch
            || $member->starting_commit !== $inventory->startingCommit
            || $member->common_repository_path !== $inventory->commonRepositoryPath
            || $member->source_identity !== $inventory->sourceIdentity
            || $member->linked_worktree_paths !== []
            || $member->source_digest !== $inventory->digest
        ) {
            $this->conflict($member->name);
        }
    }

    private function digest(
        int $appInstanceId,
        int $appId,
        int $nodeId,
        string $checkoutPath,
        string $root,
        string $branch,
        string $startingCommit,
        string $sourceIdentity,
    ): string {
        return hash('sha256', json_encode([
            'app_instance_id' => $appInstanceId,
            'app_id' => $appId,
            'node_id' => $nodeId,
            'checkout_path' => $checkoutPath,
            'root' => $root,
            'branch' => $branch,
            'starting_commit' => $startingCommit,
            'source_identity' => $sourceIdentity,
            'outcome' => 'retained',
        ], JSON_THROW_ON_ERROR));
    }

    private function conflict(string $name): never
    {
        throw new ResourceOperationException(
            errorCode: 'instance.removal_conflict',
            message: "Production AppInstance [{$name}] retained-content evidence changed during removal.",
            status: 409,
        );
    }
}
