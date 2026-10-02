<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int|null $app_instance_id
 * @property int $task_id
 * @property-read Task $task
 * @property string $delivery
 * @property array<string, mixed> $context
 * @property string|null $error
 * @property int $submission_order
 * @property int $revision
 * @property string $command_id
 * @property string $message_id
 * @property array<string, mixed>|null $command
 * @property Carbon|null $lease_until
 * @property Carbon $created_at
 * @property-read AppInstance|null $instance
 */
final class Annotation extends Model
{
    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'string';

    #[\Override]
    protected $guarded = [];

    #[\Override]
    protected $hidden = ['command'];

    /** @return BelongsTo<AppInstance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(AppInstance::class, 'app_instance_id');
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['context' => 'array', 'command' => 'array', 'revision' => 'integer', 'lease_until' => 'datetime'];
    }
}
