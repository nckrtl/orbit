<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\AppInstances\Dependencies\DependencyRequirementKind;
use App\Domain\AppInstances\Dependencies\DependencyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $observation_id
 * @property int|null $from_resolution_id
 * @property int|null $to_resolution_id
 * @property string $name
 * @property string $constraint
 * @property DependencyRequirementKind $kind
 * @property DependencyScope $scope
 * @property bool $optional
 * @property-read AppInstanceDependencyObservation $observation
 * @property-read AppInstanceDependencyResolution|null $fromResolution
 * @property-read AppInstanceDependencyResolution|null $toResolution
 */
final class AppInstanceDependencyEdge extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'observation_id',
        'from_resolution_id',
        'to_resolution_id',
        'name',
        'constraint',
        'kind',
        'scope',
        'optional',
    ];

    /** @return BelongsTo<AppInstanceDependencyObservation, $this> */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(AppInstanceDependencyObservation::class, 'observation_id');
    }

    /** @return BelongsTo<AppInstanceDependencyResolution, $this> */
    public function fromResolution(): BelongsTo
    {
        return $this->belongsTo(AppInstanceDependencyResolution::class, 'from_resolution_id');
    }

    /** @return BelongsTo<AppInstanceDependencyResolution, $this> */
    public function toResolution(): BelongsTo
    {
        return $this->belongsTo(AppInstanceDependencyResolution::class, 'to_resolution_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => DependencyRequirementKind::class,
            'scope' => DependencyScope::class,
            'optional' => 'boolean',
        ];
    }
}
