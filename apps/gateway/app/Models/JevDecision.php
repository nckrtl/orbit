<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class JevDecision extends Model
{
    #[\Override]
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'input_state' => 'array',
            'answers' => 'array',
            'labels' => 'array',
            'task_ids' => 'array',
            'approval_changes' => 'array',
            'merge_changes' => 'array',
            'merge_commit_history' => 'array',
            'merge_history_complete' => 'boolean',
            'merge_changes_redacted' => 'boolean',
            'approval_changes_redacted' => 'boolean',
        ];
    }
}
