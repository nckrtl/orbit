<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\ProjectDocuments;

final readonly class ProjectDocumentStorageData
{
    public function __construct(
        public bool $configured,
        public ?string $endpoint,
        public ?string $region,
        public ?string $bucket,
        public bool $credentialsConfigured,
        public ?string $updatedAt,
        public int $pendingCleanupCount,
        public ?string $oldestPendingCleanupAt,
        public ?string $lastCleanupErrorCode,
        public string $cleanupState,
        public ?string $cleanupGeneration,
        public ?string $reconciliationReportId,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(#[\SensitiveParameter] array $data): self
    {
        return new self(
            configured: is_bool($data['configured'] ?? null) ? $data['configured'] : false,
            endpoint: is_string($data['endpoint'] ?? null) ? $data['endpoint'] : null,
            region: is_string($data['region'] ?? null) ? $data['region'] : null,
            bucket: is_string($data['bucket'] ?? null) ? $data['bucket'] : null,
            credentialsConfigured: is_bool($data['credentials_configured'] ?? null) ? $data['credentials_configured'] : false,
            updatedAt: is_string($data['updated_at'] ?? null) ? $data['updated_at'] : null,
            pendingCleanupCount: is_int($data['pending_cleanup_count'] ?? null) ? $data['pending_cleanup_count'] : 0,
            oldestPendingCleanupAt: is_string($data['oldest_pending_cleanup_at'] ?? null) ? $data['oldest_pending_cleanup_at'] : null,
            lastCleanupErrorCode: is_string($data['last_cleanup_error_code'] ?? null) ? $data['last_cleanup_error_code'] : null,
            cleanupState: is_string($data['cleanup_state'] ?? null) ? $data['cleanup_state'] : '',
            cleanupGeneration: is_string($data['cleanup_generation'] ?? null) ? $data['cleanup_generation'] : null,
            reconciliationReportId: is_string($data['reconciliation_report_id'] ?? null) ? $data['reconciliation_report_id'] : null,
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
            ...($this->cleanupState === '' ? [] : [
                'cleanup_state' => $this->cleanupState,
                'cleanup_generation' => $this->cleanupGeneration,
                'reconciliation_report_id' => $this->reconciliationReportId,
            ]),
        ];
    }
}
