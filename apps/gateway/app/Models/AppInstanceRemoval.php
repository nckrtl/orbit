<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\AppInstances\AppInstanceRemovalStatus;
use App\Domain\AppInstances\AppInstanceRemovalStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int $requested_app_instance_id
 * @property string $requested_name
 * @property bool $force
 * @property string $inventory_digest
 * @property int $total
 * @property AppInstanceRemovalStatus $status
 * @property AppInstanceRemovalStep|null $current_step
 * @property AppInstanceRemovalStep|null $failed_step
 * @property string|null $error_code
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AppInstanceRemovalMember> $members
 */
final class AppInstanceRemoval extends Model
{
    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'string';

    /** @var array<int, string> */
    #[\Override]
    protected $fillable = [
        'id',
        'requested_app_instance_id',
        'requested_name',
        'force',
        'inventory_digest',
        'total',
        'status',
        'current_step',
        'failed_step',
        'error_code',
    ];

    /** @return HasMany<AppInstanceRemovalMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(AppInstanceRemovalMember::class)->orderBy('position');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'force' => 'boolean',
            'status' => AppInstanceRemovalStatus::class,
            'current_step' => AppInstanceRemovalStep::class,
            'failed_step' => AppInstanceRemovalStep::class,
        ];
    }
}
