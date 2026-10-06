<?php

declare(strict_types=1);

namespace App\Actions\ProjectDocuments;

use App\Models\ProjectDocumentUpload;
use Illuminate\Support\Facades\DB;

/** Only records recovery work. Body deletion must be authorized by the restore-time cleanup gate. */
final readonly class RecoverDocumentUploadsAction
{
    public function __construct(private DocumentTreeAction $tree) {}

    public function handle(): int
    {
        return DB::transaction(function (): int {
            $this->tree->lock();
            $uploads = ProjectDocumentUpload::query()->where('state', 'active')->where('created_at', '<', now()->subHour())
                ->orderBy('id')->limit(100)->lockForUpdate()->get();
            foreach ($uploads as $upload) {
                $this->tree->abandon($upload);
            }

            return $uploads->count();
        });
    }
}
