<?php

declare(strict_types=1);

namespace App\Data\ProjectDocuments;

final readonly class CleanupGateStatus
{
    public function __construct(
        public string $state = 'paused',
        public ?string $generation = null,
        public ?string $reportId = null,
        public ?string $errorCode = null,
    ) {}

    /** @return array{cleanup_state: string, cleanup_generation: ?string, reconciliation_report_id: ?string} */
    public function toArray(): array
    {
        return ['cleanup_state' => $this->state, 'cleanup_generation' => $this->generation, 'reconciliation_report_id' => $this->reportId];
    }
}
