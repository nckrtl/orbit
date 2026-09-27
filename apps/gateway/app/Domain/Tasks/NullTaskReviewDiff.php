<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;

final readonly class NullTaskReviewDiff implements TaskReviewDiff
{
    public function read(AppInstance $instance, string $startCommit): array
    {
        return [
            'files' => [],
            'diff' => '',
            'files_complete' => true,
            'diff_available' => true,
            'summary' => ['files' => 0, 'insertions' => 0, 'deletions' => 0],
        ];
    }
}
