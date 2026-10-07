<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayDocumentCleanup;
use Closure;

/**
 * Resumes Project Document cleanup after the runtime handoff. A scheduler start or an FPM reload rotates the cleanup
 * generation and pauses cleanup, so after a scheduler restart the handoff waits for the new generation, reconciles,
 * and resumes with that report. A report that cannot authorize resume leaves cleanup paused; the release reports
 * that and alerts instead of failing, because the release itself is healthy.
 */
final readonly class GatewayCleanupHandoff
{
    /** @var Closure(int): void */
    private Closure $sleep;

    /** @param (Closure(int): void)|null $sleep microseconds */
    public function __construct(
        private GatewayDocumentCleanup $cleanup,
        private int $generationWaitSeconds = 30,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    /** The generation before the scheduler stops, so the handoff can tell when the new scheduler has started. */
    public function generation(): ?string
    {
        return $this->cleanup->configured() ? $this->cleanup->status()['cleanup_generation'] : null;
    }

    /**
     * @return array{outcome: string, paused: bool, error_code?: string, report_id?: string, difference_count?: int}
     */
    public function resume(?string $generationBefore, bool $schedulerRestarted): array
    {
        if (! $this->cleanup->configured()) {
            return ['outcome' => 'skipped', 'paused' => false];
        }

        $status = $schedulerRestarted ? $this->awaitNewGeneration($generationBefore) : $this->cleanup->status();

        if ($status === null) {
            return ['outcome' => 'paused', 'paused' => true, 'error_code' => 'gateway.release_cleanup_generation_unchanged'];
        }

        if ($status['cleanup_state'] === 'running') {
            return ['outcome' => 'running', 'paused' => false];
        }

        $report = $this->cleanup->reconcile();
        $reportId = $report['report_id'] ?? null;

        if (isset($report['error_code']) || $reportId === null || ($report['report_state'] ?? null) !== 'complete' || ($report['difference_count'] ?? 1) !== 0) {
            return array_filter([
                'outcome' => 'paused',
                'paused' => true,
                'error_code' => $report['error_code'] ?? 'gateway.release_cleanup_unresolved',
                'report_id' => $reportId,
                'difference_count' => $report['difference_count'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        $resumed = $this->cleanup->resume($reportId);

        if (isset($resumed['error_code']) || ($resumed['cleanup_state'] ?? null) !== 'running') {
            return ['outcome' => 'paused', 'paused' => true, 'error_code' => $resumed['error_code'] ?? 'gateway.release_cleanup_unresolved', 'report_id' => $reportId];
        }

        return ['outcome' => 'resumed', 'paused' => false, 'report_id' => $reportId];
    }

    /** @return array{cleanup_state: string, cleanup_generation: string|null}|null */
    private function awaitNewGeneration(?string $before): ?array
    {
        $deadline = hrtime(true) + $this->generationWaitSeconds * 1_000_000_000;

        do {
            $status = $this->cleanup->status();

            if ($status['cleanup_generation'] !== null && $status['cleanup_generation'] !== $before) {
                return $status;
            }

            ($this->sleep)(500_000);
        } while (hrtime(true) < $deadline);

        return null;
    }
}
