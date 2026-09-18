<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * @property int $id
 * @property int $observation_id
 * @property int $dependency_package_id
 * @property DependencyEcosystem $ecosystem
 * @property string $locator
 * @property string $version
 * @property bool $regular
 * @property bool $development
 * @property string|null $source_reference
 * @property string|null $integrity
 * @property-read AppInstanceDependencyObservation $observation
 * @property-read DependencyPackage $package
 * @property-read Collection<int, AppInstanceDependencyEdge> $requirements
 * @property-read Collection<int, AppInstanceDependencyEdge> $incomingRequirements
 */
final class AppInstanceDependencyResolution extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'observation_id',
        'dependency_package_id',
        'ecosystem',
        'locator',
        'version',
        'regular',
        'development',
        'source_reference',
        'integrity',
    ];

    protected static function booted(): void
    {
        self::saving(static function (self $record): void {
            if ($record->source_reference !== null && preg_match('/^[^\s:@?\x00-\x1f\x7f]+$/D', $record->source_reference) !== 1) {
                throw new InvalidArgumentException('Dependency source references must be credential-free revisions, not URLs.');
            }
        });
    }

    /** @return BelongsTo<AppInstanceDependencyObservation, $this> */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(AppInstanceDependencyObservation::class, 'observation_id');
    }

    /** @return BelongsTo<DependencyPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(DependencyPackage::class, 'dependency_package_id');
    }

    /** @return HasMany<AppInstanceDependencyEdge, $this> */
    public function requirements(): HasMany
    {
        return $this->hasMany(AppInstanceDependencyEdge::class, 'from_resolution_id');
    }

    /** @return HasMany<AppInstanceDependencyEdge, $this> */
    public function incomingRequirements(): HasMany
    {
        return $this->hasMany(AppInstanceDependencyEdge::class, 'to_resolution_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ecosystem' => DependencyEcosystem::class,
            'regular' => 'boolean',
            'development' => 'boolean',
        ];
    }
}
