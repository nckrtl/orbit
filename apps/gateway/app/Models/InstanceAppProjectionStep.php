<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $id
 * @property string $instance_app_projection_id
 * @property string $step_key
 * @property int $sequence
 * @property string $plan_digest
 * @property array<string, mixed> $intent
 * @property string $receipt_id
 * @property string $status
 * @property array<string, mixed>|null $receipt
 * @property string|null $error_code
 */
final class InstanceAppProjectionStep extends Model
{
    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'string';

    #[\Override]
    protected $fillable = ['id', 'instance_app_projection_id', 'step_key', 'sequence', 'plan_digest', 'intent', 'receipt_id', 'status', 'receipt', 'error_code'];

    protected static function booted(): void
    {
        self::updating(function (self $step): void {
            if ($step->getOriginal('status') === 'complete' && $step->isDirty(['status', 'receipt'])) {
                throw new LogicException('A completed step acknowledgment is immutable.');
            }
            if ($step->isDirty(['id', 'instance_app_projection_id', 'step_key', 'sequence', 'plan_digest', 'intent', 'receipt_id'])) {
                throw new LogicException('App projection step intent is immutable.');
            }
        });
    }

    #[\Override]
    protected function casts(): array
    {
        return ['intent' => 'array', 'receipt' => 'array'];
    }
}
