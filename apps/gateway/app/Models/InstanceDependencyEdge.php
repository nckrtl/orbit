<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Instances\Dependencies\DependencyRequirementKind;
use App\Domain\Instances\Dependencies\DependencyScope;
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
 * @property-read InstanceDependencyObservation $observation
 * @property-read InstanceDependencyResolution|null $fromResolution
 * @property-read InstanceDependencyResolution|null $toResolution
 */
final class InstanceDependencyEdge extends Model
{
    #[\Override]
    protected $table = 'instance_dependency_edges';

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

    /** @return BelongsTo<InstanceDependencyObservation, $this> */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(InstanceDependencyObservation::class, 'observation_id');
    }

    /** @return BelongsTo<InstanceDependencyResolution, $this> */
    public function fromResolution(): BelongsTo
    {
        return $this->belongsTo(InstanceDependencyResolution::class, 'from_resolution_id');
    }

    /** @return BelongsTo<InstanceDependencyResolution, $this> */
    public function toResolution(): BelongsTo
    {
        return $this->belongsTo(InstanceDependencyResolution::class, 'to_resolution_id');
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
