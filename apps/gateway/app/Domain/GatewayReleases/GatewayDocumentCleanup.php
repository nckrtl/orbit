<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

/**
 * The Project Document cleanup gate as the release handoff sees it ([Restore-time cleanup gate](/reference/project-documents#restore-time-cleanup-gate)).
 * A scheduler restart pauses cleanup, so the handoff reconciles and resumes it in the new scheduler's generation.
 *
 * @phpstan-type CleanupStatus array{cleanup_state: string, cleanup_generation: string|null, error_code?: string}
 * @phpstan-type CleanupReport array{report_id?: string, report_state?: string, difference_count?: int, error_code?: string}
 */
interface GatewayDocumentCleanup
{
    /** Whether document storage is configured. Without it there is nothing to clean up. */
    public function configured(): bool;

    /** @return CleanupStatus */
    public function status(): array;

    /** @return CleanupReport */
    public function reconcile(): array;

    /** @return array{cleanup_state?: string, error_code?: string} */
    public function resume(string $reportId): array;
}
