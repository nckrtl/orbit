<?php

declare(strict_types=1);

namespace App\Actions\ProjectDocuments;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\ProjectDocuments\CleanupGate;
use App\Infrastructure\ProjectDocuments\DocumentRecoveryInventory;
use App\Infrastructure\ProjectDocuments\RecoveryReports;
use Throwable;

final readonly class RecoverDocumentCleanupAction
{
    public function __construct(private CleanupGate $gate, private RecoveryReports $reports, private DocumentRecoveryInventory $inventory, private DocumentCleanupControlAction $control) {}

    /** @return array<string, mixed> */
    public function reconcile(?string $resolutionFile = null): array
    {
        $output = [];
        try {
            $resolution = $this->reports->resolution($resolutionFile);
            $this->gate->reconcile(function (string $generation) use ($resolution, &$output): ?array {
                $id = bin2hex(random_bytes(32));
                $started = now()->toIso8601String();
                $scan = $this->inventory->scan();
                $report = ['schema_version' => 1, 'report_id' => $id, 'cleanup_generation' => $generation,
                    'started_at' => $started, 'completed_at' => now()->toIso8601String(), ...$scan, 'resolution' => $resolution];
                $digest = $this->reports->publish($id, $report);
                $output = ['report_id' => $id, 'report_path' => $this->reports->path($id), 'report_state' => $scan['report_state'], 'difference_count' => count(is_array($scan['differences']) ? $scan['differences'] : [])];
                if (isset($scan['error_code'])) {
                    $output['error_code'] = $scan['error_code'];
                }

                return $scan['report_state'] === 'complete' ? ['id' => $id, 'sha256' => $digest] : null;
            });
        } catch (Throwable $exception) {
            $output['error_code'] = $exception instanceof ResourceOperationException ? $exception->errorCode : CleanupGate::ERROR_CODE;
        }

        return $output + $this->control->handle(false);
    }

    /** @return array<string, mixed> */
    public function resume(?string $id): array
    {
        $output = [];
        try {
            if ($id === null || preg_match('/\A[a-f0-9]{64}\z/D', $id) !== 1) {
                throw new ResourceOperationException('project_documents.cleanup_input_invalid', 'Supply a report identifier.', 422);
            }
            $this->gate->resume($id, function (string $generation, string $digest) use ($id): void {
                $report = $this->reports->read($id, $digest);
                if (($report['cleanup_generation'] ?? null) !== $generation || ($report['report_state'] ?? null) !== 'complete'
                    || ($report['fingerprint_algorithm'] ?? null) !== 'sha256' || ! is_array($report['differences'] ?? null)) {
                    throw new ResourceOperationException('project_documents.cleanup_report_invalid', 'Reconcile again before resuming.', 409);
                }
                if ($report['differences'] !== []) {
                    throw new ResourceOperationException('project_documents.cleanup_unresolved', 'Resolve differences and reconcile again.', 409);
                }
                $scan = $this->inventory->scan();
                if (isset($scan['error_code'])) {
                    throw new ResourceOperationException(is_string($scan['error_code']) ? $scan['error_code'] : CleanupGate::ERROR_CODE, 'Recovery verification failed.', 409);
                }
                if ($scan['report_state'] !== 'complete' || $scan['differences'] !== []
                    || $scan['database_fingerprint'] !== $report['database_fingerprint']
                    || $scan['bucket_fingerprint'] !== $report['bucket_fingerprint']
                    || $scan['verification'] !== $report['verification']) {
                    throw new ResourceOperationException('project_documents.cleanup_inventory_changed', 'Inventory changed; reconcile again.', 409);
                }
            });
        } catch (Throwable $exception) {
            $output['error_code'] = $exception instanceof ResourceOperationException ? $exception->errorCode : CleanupGate::ERROR_CODE;
        }

        return $output + $this->control->handle(false);
    }
}
