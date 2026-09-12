<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleRunStatus;
use App\Domain\Shared\LifecycleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $target_type
 * @property int $target_id
 * @property string|null $source_definition_id
 * @property int $host_node_id
 * @property string $name
 * @property string $calendar
 * @property string $command
 * @property int $timeout_seconds
 * @property DesiredTimerState $desired_timer_state
 * @property LifecycleStatus $status
 * @property string|null $failed_step
 * @property string|null $error_code
 * @property Carbon|null $last_run_at
 * @property ScheduleRunStatus|null $last_run_status
 * @property-read Node|AppInstance $target
 * @property-read Node $hostNode
 */
final class Schedule extends Model
{
    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'string';

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'id',
        'target_type',
        'target_id',
        'source_definition_id',
        'host_node_id',
        'name',
        'calendar',
        'command',
        'timeout_seconds',
        'desired_timer_state',
        'status',
        'failed_step',
        'error_code',
        'last_run_at',
        'last_run_status',
    ];

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['calendar', 'command'];

    protected static function booted(): void
    {
        self::creating(static function (self $schedule): void {
            $id = $schedule->getAttribute('id');
            $schedule->id = is_string($id) && $id !== '' ? $id : (string) Str::uuid();
        });
    }

    /** @return array<array-key, mixed> */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /** @return MorphTo<Model, $this> */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Node, $this> */
    public function hostNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'host_node_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'timeout_seconds' => 'integer',
            'desired_timer_state' => DesiredTimerState::class,
            'status' => LifecycleStatus::class,
            'last_run_at' => 'immutable_datetime',
            'last_run_status' => ScheduleRunStatus::class,
        ];
    }
}
