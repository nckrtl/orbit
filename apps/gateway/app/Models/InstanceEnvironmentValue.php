<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $instance_id
 * @property string $app
 * @property string $env_key
 * @property string $env_value
 * @property-read Instance $instance
 */
final class InstanceEnvironmentValue extends Model
{
    #[\Override]
    protected $table = 'instance_environment_values';

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'instance_id',
        'app',
        'env_key',
        'env_value',
    ];

    protected static function booted(): void
    {
        self::creating(static function (self $value): void {
            $value->app ??= $value->instance->appConfiguration()['name'];
        });
    }

    /** @var list<string> */
    #[\Override]
    protected $hidden = [
        'env_value',
    ];

    /** @return array<array-key, mixed> */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /** @return BelongsTo<Instance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class, 'instance_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['env_value' => 'encrypted'];
    }
}
