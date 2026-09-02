<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\Value\ProofPromotionRecord;
use RuntimeException;

/** Immutable promotion lineage retained beside the persistent topology snapshot. */
final readonly class TopologySnapshotPromotionStore
{
    public function __construct(
        private AtomicJsonStore $store,
    ) {}

    public function record(ProofPromotionRecord $record): void
    {
        $path = 'topology-snapshot/promotions/'.$record->generationId.'.json';
        $value = $record->toArray();
        $existing = $this->store->read($path);
        if ($existing !== null && $existing !== $value) {
            throw new RuntimeException('Immutable topology snapshot promotion lineage cannot be replaced.');
        }
        if ($existing === null) {
            $this->store->write($path, $value);
        }
    }

    /** @return array<array-key, mixed>|null */
    public function find(string $generationId): ?array
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $generationId) !== 1) {
            throw new RuntimeException('The promoted generation identity is invalid.');
        }

        return $this->store->read('topology-snapshot/promotions/'.$generationId.'.json');
    }
}
