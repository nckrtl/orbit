<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Problems\ProblemSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $fingerprint
 * @property ProblemSource $source
 * @property Carbon|null $first_seen
 * @property Carbon|null $last_seen
 * @property int $occurrences
 * @property array<string, mixed> $evidence
 * @property int|null $task_group_id
 * @property Carbon|null $muted_until
 * @property Carbon|null $filed_at
 */
final class ProblemFingerprint extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'fingerprint',
        'source',
        'first_seen',
        'last_seen',
        'occurrences',
        'evidence',
        'task_group_id',
        'muted_until',
        'filed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => ProblemSource::class,
            'first_seen' => 'datetime',
            'last_seen' => 'datetime',
            'occurrences' => 'integer',
            'evidence' => 'array',
            'muted_until' => 'datetime',
            'filed_at' => 'datetime',
        ];
    }
}
