<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceRemovalStep;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int $requested_instance_id
 * @property string $requested_name
 * @property bool $force
 * @property string $inventory_digest
 * @property int $total
 * @property InstanceRemovalStatus $status
 * @property InstanceRemovalStep|null $current_step
 * @property InstanceRemovalStep|null $failed_step
 * @property string|null $error_code
 * @property-read Collection<int, InstanceRemovalMember> $members
 */
final class InstanceRemoval extends Model
{
    #[\Override]
    protected $table = 'instance_removals';

    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'string';

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'id',
        'requested_instance_id',
        'requested_name',
        'force',
        'inventory_digest',
        'total',
        'status',
        'current_step',
        'failed_step',
        'error_code',
    ];

    /** @return HasMany<InstanceRemovalMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(InstanceRemovalMember::class, 'instance_removal_id')->orderBy('position');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'force' => 'boolean',
            'status' => InstanceRemovalStatus::class,
            'current_step' => InstanceRemovalStep::class,
            'failed_step' => InstanceRemovalStep::class,
        ];
    }
}
