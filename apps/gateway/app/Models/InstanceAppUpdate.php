<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $id
 * @property int $instance_id
 * @property string $fingerprint
 * @property array<string, mixed> $request
 * @property array<string, mixed> $previous_overrides
 * @property string $phase
 * @property Carbon|null $published_at
 * @property array<string, mixed>|null $completion
 */
final class InstanceAppUpdate extends Model
{
    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'string';

    #[\Override]
    protected $fillable = ['id', 'instance_id', 'fingerprint', 'request', 'previous_overrides', 'phase', 'published_at', 'completion'];

    protected static function booted(): void
    {
        self::updating(function (self $owner): void {
            if ($owner->getOriginal('completion') !== null && $owner->isDirty(['completion', 'phase'])) {
                throw new LogicException('An app update completion receipt is immutable.');
            }
            if ($owner->isDirty(['id', 'instance_id', 'fingerprint', 'request', 'previous_overrides'])
                || $owner->getOriginal('published_at') !== null && $owner->isDirty('published_at')) {
                throw new LogicException('App update identity and publication boundary are immutable.');
            }
        });
    }

    #[\Override]
    protected function casts(): array
    {
        return ['request' => 'array', 'previous_overrides' => 'array', 'published_at' => 'datetime', 'completion' => 'array'];
    }
}
