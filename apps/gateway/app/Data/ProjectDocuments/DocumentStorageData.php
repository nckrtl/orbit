<?php

declare(strict_types=1);

namespace App\Data\ProjectDocuments;

use App\Models\ProjectDocumentCleanup;
use App\Models\ProjectDocumentStorage;

final readonly class DocumentStorageData
{
    public function __construct(
        public bool $configured,
        public ?string $endpoint,
        public ?string $region,
        public ?string $bucket,
        public bool $credentialsConfigured,
        public ?string $updatedAt,
        public int $pendingCleanupCount = 0,
        public ?string $oldestPendingCleanupAt = null,
        public ?string $lastCleanupErrorCode = null,
    ) {}

    public static function fromModel(ProjectDocumentStorage $storage): self
    {
        $credentialsConfigured = $storage->getRawOriginal('access_key_id') !== null
            && $storage->getRawOriginal('secret_access_key') !== null;

        return new self(
            configured: $credentialsConfigured && $storage->endpoint !== null && $storage->region !== null && $storage->bucket !== null,
            endpoint: $storage->endpoint,
            region: $storage->region,
            bucket: $storage->bucket,
            credentialsConfigured: $credentialsConfigured,
            updatedAt: $storage->updated_at?->toIso8601String(),
            pendingCleanupCount: ProjectDocumentCleanup::query()->where('pending', true)->count(),
            oldestPendingCleanupAt: ProjectDocumentCleanup::query()->where('pending', true)->oldest('created_at')->first()?->created_at?->toIso8601String(),
            lastCleanupErrorCode: ProjectDocumentCleanup::query()->where('pending', true)->whereNotNull('last_error_code')->latest('updated_at')->first()?->last_error_code,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'configured' => $this->configured,
            'endpoint' => $this->endpoint,
            'region' => $this->region,
            'bucket' => $this->bucket,
            'credentials_configured' => $this->credentialsConfigured,
            'updated_at' => $this->updatedAt,
            'pending_cleanup_count' => $this->pendingCleanupCount,
            'oldest_pending_cleanup_at' => $this->oldestPendingCleanupAt,
            'last_cleanup_error_code' => $this->lastCleanupErrorCode,
        ];
    }
}
