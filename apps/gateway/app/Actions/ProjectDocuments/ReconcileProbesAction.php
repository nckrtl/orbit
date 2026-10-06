<?php

declare(strict_types=1);

namespace App\Actions\ProjectDocuments;

use App\Infrastructure\ProjectDocuments\DocumentsFilesystem;
use App\Infrastructure\ProjectDocuments\ProbeJournal;
use App\Infrastructure\ProjectDocuments\ProbeResponseBuffer;
use Throwable;

final readonly class ReconcileProbesAction
{
    public function __construct(private ProbeJournal $journal, private DocumentsFilesystem $filesystems) {}

    /** @return array{attempted: int, succeeded: int, failed: int} */
    public function handle(): array
    {
        $counts = ['attempted' => 0, 'succeeded' => 0, 'failed' => 0];
        foreach ($this->journal->claimDue() as $id) {
            $succeeded = false;
            try {
                $this->deleteTracked($id);
                $succeeded = true;
            } catch (Throwable) {
                // Keep the permanent fence and persist only the stable error code, never provider diagnostics.
            }
            $this->journal->completeAttempt($id, $succeeded);
            $counts['attempted']++;
            $counts[$succeeded ? 'succeeded' : 'failed']++;
        }

        return $counts;
    }

    public function deleteTracked(string $id): void
    {
        $record = $this->journal->find($id);
        $this->filesystems->forConfiguration($record->configuration())->getClient()->deleteObject([
            'Bucket' => $record->bucket, 'Key' => $record->key(),
            '@http' => ['stream' => false, 'sink' => new ProbeResponseBuffer(65_536)],
        ]);
    }
}
