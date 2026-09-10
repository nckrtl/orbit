<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\Value\PreparedFingerprint;
use App\E2E\Value\TopologySnapshotGeneration;
use RuntimeException;

/** Resolve one complete, available promoted generation before disposable construction. */
final readonly class PromotedTopologySnapshotResolver
{
    public function __construct(
        private PreparedStateFingerprint $fingerprints,
        private TopologySnapshotManifestStore $topologySnapshot,
        private TopologySnapshotAvailability $availability,
    ) {}

    public function resolve(string $worktree): TopologySnapshotGeneration
    {
        $generation = $this->topologySnapshot->promoted() ?? throw new RuntimeException(
            'No promoted topology snapshot generation is available.',
        );
        if ($generation->isLegacy()) {
            throw new RuntimeException(
                'The promoted topology snapshot generation is legacy; refresh it before acquisition.',
            );
        }

        $expectedId = substr($generation->mainSha, 0, 12).'-'.substr($generation->preparedFingerprint, 0, 12);
        if ($generation->id !== $expectedId) {
            throw new RuntimeException('The promoted topology snapshot fingerprint is stale or corrupt.');
        }

        $baseline = DeliveryFlow::forWorktree($worktree) === 'discovery' ? $generation->mainSha : 'main';
        $structural = $this->fingerprints->forCommit($baseline);
        $prepared = $this->fingerprints->withLaravel($structural, $generation->laravel);
        if (
            $structural->value !== $generation->structuralFingerprint
            || $generation->preparedFingerprint !== $prepared->value
        ) {
            throw new RuntimeException('The promoted topology snapshot is stale; refresh it from main first.');
        }

        $this->assertFeatureColdBase($worktree, $prepared);
        $this->availability->assertAvailable($generation);

        return $generation;
    }

    /**
     * Check sync against the topology's recorded generation without resolving the
     * current promoted manifest, which may have advanced since acquisition.
     */
    public function assertExistingGenerationColdBase(
        string $worktree,
        TopologySnapshotGeneration $generation,
    ): void {
        if (DeliveryFlow::forWorktree($worktree) === 'discovery') {
            $feature = $this->featureFingerprint($worktree);
            if (
                ($feature->manifest['cold_epoch'] ?? null) !== $generation->coldEpoch
                || ($feature->manifest['base_image_alias'] ?? null) !== $generation->baseImageAlias
            ) {
                throw new RuntimeException('The feature prepared state changes the cold base contract.');
            }

            return;
        }

        $this->assertFeatureColdBase($worktree, $this->fingerprints->forCommit('main'));
    }

    private function assertFeatureColdBase(string $worktree, PreparedFingerprint $baseline): void
    {
        $feature = $this->featureFingerprint($worktree);
        if (
            ($feature->manifest['cold_epoch'] ?? null) !== ($baseline->manifest['cold_epoch'] ?? null)
            || ($feature->manifest['base_image_alias'] ?? null) !== ($baseline->manifest['base_image_alias'] ?? null)
        ) {
            throw new RuntimeException('The feature prepared state changes the cold base contract.');
        }
    }

    private function featureFingerprint(string $worktree): PreparedFingerprint
    {
        return new PreparedStateFingerprint(new GitRepository($worktree))->forCommit();
    }
}
