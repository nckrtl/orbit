<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $app_instance_id
 * @property string|null $release
 * @property string|null $branch
 * @property string|null $commit
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_seconds
 * @property string $status
 * @property string|null $failed_step
 * @property string|null $error_code
 * @property string|null $selected_release
 * @property string|null $triggered_by
 * @property list<array<string, mixed>>|null $events
 * @property-read AppInstance $appInstance
 */
final class AppInstanceDeployment extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'app_instance_id',
        'release',
        'branch',
        'commit',
        'started_at',
        'finished_at',
        'duration_seconds',
        'status',
        'failed_step',
        'error_code',
        'selected_release',
        'triggered_by',
        'events',
    ];

    /** @return BelongsTo<AppInstance, $this> */
    public function appInstance(): BelongsTo
    {
        return $this->belongsTo(AppInstance::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_seconds' => 'integer',
            'events' => 'array',
        ];
    }
}
