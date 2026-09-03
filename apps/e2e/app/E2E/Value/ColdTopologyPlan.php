<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** @mago-expect lint:cyclomatic-complexity The immutable plan validates every external input before Incus mutation. */
final readonly class ColdTopologyPlan
{
    /**
     * @param array<string, string> $imageFingerprints
     * @param array<string, string> $metadata
     * @mago-expect lint:excessive-parameter-list The plan deliberately carries the complete construction contract.
     */
    public function __construct(
        public TopologyTarget $target,
        public string $sourceWorktree,
        public string $sourceSha,
        public array $imageFingerprints,
        public LaravelRelease $laravel,
        public OperationId $operation,
        public array $metadata,
        public ?int $fixedSlot = null,
    ) {
        $requiredNodes = [];
        foreach (TopologyProfile::ROLES as $role) {
            $requiredNodes[] = $target->recipe->nodeForRole($role)->key;
        }
        if (count($requiredNodes) !== count(array_unique($requiredNodes))) {
            throw new InvalidArgumentException('Each required topology role must resolve to a distinct physical Node.');
        }
        if ($sourceWorktree === '' || ! str_starts_with($sourceWorktree, '/')) {
            throw new InvalidArgumentException('The cold topology source worktree must be absolute.');
        }
        if (preg_match('/\A[a-f0-9]{40}\z/D', $sourceSha) !== 1) {
            throw new InvalidArgumentException('The cold topology source SHA is invalid.');
        }
        $images = array_values(array_unique(array_map(
            static fn (TopologyNode $node): string => $node->image,
            $target->recipe->nodes,
        )));
        sort($images, SORT_STRING);
        $fingerprintImages = array_keys($imageFingerprints);
        sort($fingerprintImages, SORT_STRING);
        if ($images !== $fingerprintImages) {
            throw new InvalidArgumentException('The cold topology image fingerprint inventory is incomplete.');
        }
        foreach ($imageFingerprints as $image => $fingerprint) {
            if (! is_string($image) || preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
                throw new InvalidArgumentException('A cold topology image fingerprint is invalid.');
            }
        }
        if (! IncusMetadata::isValidAdditionalMap($metadata)) {
            throw new InvalidArgumentException('Cold topology ownership metadata is invalid.');
        }
        if (($metadata['user.orbit.e2e.operation'] ?? null) !== $operation->value) {
            throw new InvalidArgumentException('Cold topology metadata must name its exact operation.');
        }
        if ($fixedSlot !== null && $fixedSlot < 1) {
            throw new InvalidArgumentException('Persistent cold topology construction requires a fixed slot.');
        }
    }

    public function isDisposable(): bool
    {
        return $this->fixedSlot === null;
    }
}
