<?php

declare(strict_types=1);

namespace App\Actions\ProjectDocuments;

use App\Data\ProjectDocuments\CleanupGateStatus;
use App\Infrastructure\ProjectDocuments\CleanupGate;
use App\Models\ProjectDocumentCleanup;
use Throwable;

final readonly class DocumentCleanupControlAction
{
    public function __construct(private CleanupGate $gate) {}

    /** @return array<string, mixed> */
    public function handle(bool $invalidate, bool $includeCounts = true): array
    {
        try {
            $status = $invalidate ? $this->gate->invalidate() : $this->gate->status();
            $data = $status->toArray();
            if ($includeCounts) {
                $data += [
                    'pending_cleanup_count' => ProjectDocumentCleanup::query()->where('pending', true)->count(),
                    'oldest_pending_cleanup_at' => ProjectDocumentCleanup::query()->where('pending', true)->oldest('created_at')->first()?->created_at?->toIso8601String(),
                    'last_cleanup_error_code' => ProjectDocumentCleanup::query()->where('pending', true)->whereNotNull('last_error_code')->latest('updated_at')->first()?->last_error_code,
                ];
            }
            if ($status->errorCode !== null) {
                $data['error_code'] = $status->errorCode;
            }

            return $data;
        } catch (Throwable) {
            return (new CleanupGateStatus)->toArray() + [
                'pending_cleanup_count' => 0, 'oldest_pending_cleanup_at' => null,
                'last_cleanup_error_code' => null, 'error_code' => CleanupGate::ERROR_CODE,
            ];
        }
    }
}
