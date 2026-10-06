<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\ResourceOperationException;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $id
 * @property int $instance_id
 * @property int|null $active_instance_id
 * @property int $node_id
 * @property int|null $project_update_id
 * @property string|null $instance_app_update_id
 * @property array<string, mixed> $plan
 * @property string $plan_digest
 * @property string $render_side
 * @property string|null $recovery_direction
 * @property array<string, mixed>|null $completion
 */
final class InstanceAppProjection extends Model
{
    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'string';

    #[\Override]
    protected $fillable = ['id', 'instance_id', 'active_instance_id', 'node_id', 'project_update_id', 'instance_app_update_id', 'plan', 'plan_digest', 'render_side', 'recovery_direction', 'completion'];

    /**
     * Call under the existing Instance operation locks. No implicit owner bypass.
     *
     * @param  list<int>  $instanceIds
     */
    public static function assertAvailable(array $instanceIds): void
    {
        if (self::query()->whereIn('active_instance_id', $instanceIds)->exists()) {
            throw new ResourceOperationException('instance.lifecycle_busy', 'Finish the recorded app mutation before changing this Instance.', 409);
        }
    }

    protected static function booted(): void
    {
        self::saving(function (self $projection): void {
            if (($projection->project_update_id === null) === ($projection->instance_app_update_id === null)) {
                throw new LogicException('An app projection requires exactly one parent operation.');
            }
            if ($projection->exists && $projection->isDirty(['id', 'instance_id', 'node_id', 'project_update_id', 'instance_app_update_id', 'plan', 'plan_digest'])) {
                throw new LogicException('An app projection parent and plan are immutable.');
            }
            if ($projection->exists && $projection->getOriginal('completion') !== null && $projection->isDirty(['completion', 'render_side', 'recovery_direction', 'active_instance_id'])) {
                throw new LogicException('A completed projection receipt is immutable.');
            }
            if ($projection->active_instance_id !== ($projection->completion === null ? $projection->instance_id : null)) {
                throw new LogicException('Only a completed projection may release ownership.');
            }
        });
    }

    #[\Override]
    protected function casts(): array
    {
        return ['plan' => 'array', 'completion' => 'array'];
    }
}
