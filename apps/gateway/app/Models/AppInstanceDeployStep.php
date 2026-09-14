<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $app_instance_id
 * @property string $name
 * @property string $phase
 * @property string $command
 * @property int $timeout_seconds
 * @property int $position
 * @property-read AppInstance $appInstance
 */
final class AppInstanceDeployStep extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $hidden = ['command'];

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'app_instance_id',
        'name',
        'phase',
        'command',
        'timeout_seconds',
        'position',
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
            'timeout_seconds' => 'integer',
            'position' => 'integer',
        ];
    }
}
