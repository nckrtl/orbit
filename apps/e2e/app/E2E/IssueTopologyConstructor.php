<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyConstructionInputs;
use App\E2E\Value\TopologyExtension;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologySnapshotIdentity;
use App\E2E\Value\TopologyTarget;
use RuntimeException;

/** Construct one issue topology from the pinned snapshots and its optional base-image extension. */
final readonly class IssueTopologyConstructor
{
    /** @mago-expect lint:excessive-parameter-list Construction keeps each lifecycle authority explicit. */
    public function __construct(
        private IncusHost $host,
        private IncusNetworkLifecycle $networks,
        private HostCapacity $capacity,
        private StatePaths $hostPaths,
        private OperationId $operation,
        private TopologySnapshotManifestStore $topologySnapshot,
        private TopologySnapshotIdentity $topologySnapshotIdentity,
    ) {}

    /**
     * @param array<string, string> $metadata
     * @param array<string, array{device:string,source:string,path:string}> $mounts
     */
    public function construct(
        TopologyTarget $target,
        TopologySnapshotGeneration $generation,
        array $metadata,
        array $mounts = [],
        ?TopologyExtension $extension = null,
    ): TopologyConstructionInputs {
        $expectedRecipe = $extension?->recipe() ?? TopologyRecipe::registered();
        if ($target->recipe->nodeKeys() !== $expectedRecipe->nodeKeys()) {
            throw new RuntimeException('The issue topology recipe does not match its extension declaration.');
        }
        if ($this->host->network($target->network()) !== null) {
            throw new RuntimeException('The issue topology network already exists and cannot be adopted.');
        }
        $instanceNames = array_map($target->instance(...), $target->recipe->nodeKeys());
        if ($this->host->instances($instanceNames) !== []) {
            throw new RuntimeException('An issue topology VM already exists and cannot be adopted.');
        }
        $imageFingerprint = $extension === null
            ? null
            : $this->host->imageFingerprint(TopologyRecipe::BASE_IMAGE);

        $creation = new OperationLock($this->hostPaths);
        if (! $creation->acquire(OrphanNetworkSweep::CREATION_LOCK, $this->operation, timeoutSeconds: 600)) {
            throw new RuntimeException('Another topology creation holds the host.');
        }
        try {
            if (
                $extension !== null
                && $this->host->imageFingerprint(TopologyRecipe::BASE_IMAGE) !== $imageFingerprint
            ) {
                throw new RuntimeException('The issue topology base image changed before construction.');
            }
            $slot = $this->capacity->reserveSlot(count($target->recipe->nodes));
            $lastAddress = max(array_map(
                static fn (\App\E2E\Value\TopologyNode $node): int => $node->address,
                $target->recipe->nodes,
            ));
            $this->networks->create($target->network(), $slot, $metadata, $lastAddress);
            $copies = [];
            $snapshotTarget = TopologyTarget::topologySnapshot($this->topologySnapshotIdentity);
            foreach (TopologyProfile::ROLES as $node) {
                $copies[$node] = [
                    'source' => $snapshotTarget->instance($node),
                    'snapshot' => $generation->snapshots[$node],
                    'target' => $target->instance($node),
                    'metadata' => [...$metadata, 'user.orbit.e2e.generation' => $generation->id],
                    'network' => $target->network(),
                    'role' => $node,
                    'topology' => $target->network(),
                    'slot' => $slot,
                ];
                if (array_key_exists($node, $mounts)) {
                    $copies[$node]['mount'] = $mounts[$node];
                }
            }
            $this->copyPinnedSnapshots($generation, $copies);
            if ($extension !== null) {
                $node = $target->recipe->node('app-prod-2');
                $this->host->initVms([
                    $node->key => [
                        'image' => $node->image,
                        'name' => $target->instance($node->key),
                        'network' => $target->network(),
                        'role' => $node->key,
                        'address' => $node->address,
                        'topology' => $target->network(),
                        'slot' => $slot,
                        'metadata' => [...$metadata, 'user.orbit.e2e.generation' => $generation->id],
                    ],
                ]);
            }

            return TopologyConstructionInputs::create(
                $target,
                $generation,
                $slot,
                $extension,
                $imageFingerprint,
            );
        } finally {
            $creation->release();
        }
    }

    /** @param array<string, array{source:string,snapshot:string,target:string,metadata:array<string, string>,network?:string,role?:string,topology?:string,slot?:int,mount?:array{device:string,source:string,path:string}}> $copies */
    private function copyPinnedSnapshots(TopologySnapshotGeneration $generation, array $copies): void
    {
        $lock = new OperationLock($this->hostPaths);
        if (! $lock->acquire('standby-generation', $this->operation, exclusive: false, timeoutSeconds: 3600)) {
            throw new RuntimeException('The promoted topology snapshot generation is locked.');
        }
        try {
            if ($this->topologySnapshot->promoted()?->toArray() !== $generation->toArray()) {
                throw new RuntimeException('The promoted topology snapshot generation changed before snapshot copy.');
            }
            $this->host->copySnapshots($copies);
        } finally {
            $lock->release();
        }
    }
}
