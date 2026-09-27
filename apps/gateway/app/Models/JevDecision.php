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
            'task_ids' => 'array',
        ];
    }
}
