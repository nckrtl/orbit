<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $app_instance_id
 * @property string $env_key
 * @property string $env_value
 * @property-read AppInstance $appInstance
 */
final class AppInstanceEnvironmentValue extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'app_instance_id',
        'env_key',
        'env_value',
    ];

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

    /** @return BelongsTo<AppInstance, $this> */
    public function appInstance(): BelongsTo
    {
        return $this->belongsTo(AppInstance::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['env_value' => 'encrypted'];
    }
}
