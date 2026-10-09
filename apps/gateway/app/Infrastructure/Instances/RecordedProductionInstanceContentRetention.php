<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\ProductionReleaseLayout;
use App\Domain\Instances\Removal\InstanceSourceInventory;
use App\Domain\Instances\Removal\ProductionInstanceContentRetention;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRemovalMember;

final readonly class RecordedProductionInstanceContentRetention implements ProductionInstanceContentRetention
{
    public function __construct(
        private ProductionReleaseLayout $releaseLayout,
    ) {}

    public function inventory(Instance $instance): InstanceSourceInventory
    {
        $instance->loadMissing('project');
        $root = $instance->sourceRoot();

        if (
            ! $instance->placedOnAppProd()
            || $instance->checkout_path === ''
            || $root === ''
            || ! is_string($instance->starting_commit)
            || $instance->starting_commit === ''
        ) {
            $this->conflict($instance->name);
        }

        $sourceIdentity = "production:{$instance->id}:{$instance->node_id}";
        $digest = $this->digest(
            instanceId: $instance->id,
            projectId: $instance->project_id,
            nodeId: $instance->node_id,
            checkoutPath: $instance->checkout_path,
            root: $root,
            branch: $instance->branch,
            startingCommit: $instance->starting_commit,
            sourceIdentity: $sourceIdentity,
        );

        return new InstanceSourceInventory(
            instanceId: $instance->id,
            layout: $instance->source_layout,
            repositoryIdentity: $instance->project->repository_identity,
            checkoutPath: $instance->checkout_path,
            root: $root,
            branch: $instance->branch,
            startingCommit: $instance->starting_commit,
            commonRepositoryPath: $instance->checkout_path,
            sourceIdentity: $sourceIdentity,
            linkedWorktreePaths: [],
            digest: $digest,
        );
    }

    public function prepare(InstanceRemovalMember $member): void
    {
        $this->assertRecorded($member);
    }

    public function revalidate(InstanceRemovalMember $member): void
    {
        $this->assertRecorded($member);
    }

    public function finalize(InstanceRemovalMember $member): string
    {
        $this->assertRecorded($member);
        $instance = Instance::query()->with(['project', 'node'])->findOrFail($member->instance_id);
        $this->releaseLayout->clearCurrent($instance);

        return hash('sha256', "production-retained\0{$member->source_digest}");
    }

    private function assertRecorded(InstanceRemovalMember $member): void
    {
        $instance = Instance::query()->with('project')->find($member->instance_id);

        if (! $instance instanceof Instance || ! $instance->placedOnAppProd()) {
            $this->conflict($member->name);
        }

        $inventory = $this->inventory($instance);

        if (
            $member->project_id !== $instance->project_id
            || $member->node_id !== $instance->node_id
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
        int $instanceId,
        int $projectId,
        int $nodeId,
        string $checkoutPath,
        string $root,
        ?string $branch,
        string $startingCommit,
        string $sourceIdentity,
    ): string {
        return hash('sha256', json_encode([
            'instance_id' => $instanceId,
            'project_id' => $projectId,
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
            message: "Production Instance [{$name}] retained-content evidence changed during removal.",
            status: 409,
        );
    }
}
