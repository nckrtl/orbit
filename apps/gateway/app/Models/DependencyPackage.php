<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Instances\Dependencies\DependencyEcosystem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property DependencyEcosystem $ecosystem
 * @property string $name
 * @property-read Collection<int, InstanceDependencyResolution> $resolutions
 */
final class DependencyPackage extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'ecosystem',
        'name',
    ];

    /** @return HasMany<InstanceDependencyResolution, $this> */
    public function resolutions(): HasMany
    {
        return $this->hasMany(InstanceDependencyResolution::class, 'dependency_package_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ecosystem' => DependencyEcosystem::class,
        ];
    }
}
