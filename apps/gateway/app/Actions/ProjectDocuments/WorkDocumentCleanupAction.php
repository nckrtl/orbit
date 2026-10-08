<?php

declare(strict_types=1);

namespace App\Actions\ProjectDocuments;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\ProjectDocuments\CleanupGate;
use App\Infrastructure\ProjectDocuments\DocumentBodies;
use App\Models\ProjectDocumentCleanup;
use App\Models\ProjectDocumentUpload;
use App\Models\ProjectDocumentVersion;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class WorkDocumentCleanupAction
{
    public function __construct(
        private CleanupGate $gate,
        private DocumentBodies $bodies,
        private DocumentTreeAction $tree,
        private RecoverDocumentUploadsAction $uploads,
        private DocumentCleanupControlAction $control,
    ) {}

    /** @return array<string, mixed> */
    public function handle(): array
    {
        $counts = ['claimed_count' => 0, 'deleted_count' => 0, 'failed_count' => 0];
        try {
            // Do not mutate recovery state while paused, including attempts and expired intents.
            $claims = [];
            $this->gate->executeWithPermit(function () use (&$claims): void {
                $this->uploads->handle();
                $claims = $this->claim();
            });
            $counts['claimed_count'] = count($claims);
            foreach ($claims as $id => $token) {
                $this->gate->executeWithPermit(function () use ($id, $token, &$counts): void {
                    try {
                        $record = $this->authorize($id, $token);
                        if ($record === null) {
                            return;
                        }
                        // Recheck after the compatibility commit. Tombstones and fences cannot revive a key.
                        $record = ProjectDocumentCleanup::query()->where('claim_token', $token)->find($id);
                        if ($record === null) {
                            return;
                        }
                        if (ProjectDocumentVersion::query()->where('storage_key', $record->storage_key)->exists()
                            || ProjectDocumentUpload::query()->where('storage_key', $record->storage_key)->whereIn('state', ['active', 'published'])->exists()
                            || ($record->retained_fence && ! ProjectDocumentUpload::query()->where('storage_key', $record->storage_key)->where('state', 'abandoned')->exists())
                            || (! $record->retained_fence && ProjectDocumentUpload::query()->where('storage_key', $record->storage_key)->where('state', 'abandoned')->exists())) {
                            throw $this->conflict();
                        }
                        $this->bodies->delete($record->storage_key);
                        DB::transaction(function () use ($id, $token): void {
                            $this->tree->lock();
                            $record = ProjectDocumentCleanup::query()->where('claim_token', $token)->find($id);
                            if ($record === null) {
                                return;
                            }
                            if (! $record->retained_fence) {
                                $record->delete();
                            } else {
                                $record->update(['pending' => false, 'attempts' => 0, 'last_error_code' => null,
                                    'claim_token' => null, 'claim_expires_at' => null, 'next_attempt_at' => now()->addMinutes(5)]);
                            }
                        });
                        $counts['deleted_count']++;
                    } catch (Throwable $exception) {
                        $this->fail($id, $token, $exception instanceof ResourceOperationException
                            ? $exception->errorCode : 'project_documents.storage_unavailable');
                        $counts['failed_count']++;
                    }
                });
            }
        } catch (Throwable) {
            return $counts + $this->control->handle(false) + ['error_code' => CleanupGate::ERROR_CODE];
        }

        return $counts + $this->control->handle(false);
    }

    /** @return array<int, string> */
    private function claim(): array
    {
        return DB::transaction(function (): array {
            $this->tree->lock();
            $records = ProjectDocumentCleanup::query()
                ->where(fn ($query) => $query->where('pending', true)->orWhere('retained_fence', true))
                ->where('next_attempt_at', '<=', now())
                ->where(fn ($query) => $query->whereNull('claim_expires_at')->orWhere('claim_expires_at', '<=', now()))
                ->orderByDesc('pending')->orderBy('next_attempt_at')->orderBy('id')->limit(100)->lockForUpdate()->get();
            $claims = [];
            foreach ($records as $record) {
                $token = bin2hex(random_bytes(32));
                $record->update(['claim_token' => $token, 'claim_expires_at' => now()->addMinutes(10)]);
                $claims[$record->id] = $token;
            }

            return $claims;
        });
    }

    private function authorize(int $id, string $token): ?ProjectDocumentCleanup
    {
        return DB::transaction(function () use ($id, $token): ?ProjectDocumentCleanup {
            $this->tree->lock();
            $record = ProjectDocumentCleanup::query()->where('claim_token', $token)->where('claim_expires_at', '>', now())->lockForUpdate()->find($id);
            if ($record === null) {
                return null;
            }
            $key = $record->storage_key;
            if (ProjectDocumentVersion::query()->where('storage_key', $key)->exists()
                || ProjectDocumentUpload::query()->where('storage_key', $key)->where('state', 'active')->exists()
                || ($record->retained_fence && ! ProjectDocumentUpload::query()->where('storage_key', $key)->where('state', 'abandoned')->exists())
                || (! $record->retained_fence && ProjectDocumentUpload::query()->where('storage_key', $key)->where('state', 'abandoned')->exists())) {
                throw $this->conflict();
            }
            // Removal retains published rows until this gated transactional handoff.
            $published = ProjectDocumentUpload::query()->where('storage_key', $key)->where('state', 'published')->lockForUpdate()->get();
            if ($record->retained_fence && $published->isNotEmpty()) {
                throw $this->conflict();
            }
            foreach ($published as $upload) {
                $upload->delete();
            }
            if (ProjectDocumentUpload::query()->where('storage_key', $key)->whereIn('state', ['active', 'published'])->exists()) {
                throw $this->conflict();
            }

            return $record;
        });
    }

    private function fail(int $id, string $token, string $code): void
    {
        DB::transaction(function () use ($id, $token, $code): void {
            $this->tree->lock();
            $record = ProjectDocumentCleanup::query()->where('claim_token', $token)->lockForUpdate()->find($id);
            if ($record === null) {
                return;
            }
            $attempts = min(2147483647, $record->attempts + 1);
            $record->update(['pending' => true, 'attempts' => $attempts, 'last_error_code' => $code,
                'claim_token' => null, 'claim_expires_at' => null,
                'next_attempt_at' => now()->addSeconds(min(3600, 300 * (2 ** min(4, $attempts - 1))))]);
        });
    }

    private function conflict(): ResourceOperationException
    {
        return new ResourceOperationException('project_documents.cleanup_reference_conflict', 'Document cleanup references conflict.', 409);
    }
}
