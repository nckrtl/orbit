<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $instance_id
 * @property string $name
 * @property string $phase
 * @property string $command
 * @property int $timeout_seconds
 * @property int $position
 * @property-read Instance $instance
 */
final class InstanceDeployStep extends Model
{
    #[\Override]
    protected $table = 'instance_deploy_steps';

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['command'];

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'instance_id',
        'name',
        'phase',
        'command',
        'timeout_seconds',
        'position',
    ];

    /** @return BelongsTo<Instance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class, 'instance_id');
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
