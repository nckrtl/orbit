<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property DependencyEcosystem $ecosystem
 * @property string $name
 * @property-read Collection<int, AppInstanceDependencyResolution> $resolutions
 */
final class DependencyPackage extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'ecosystem',
        'name',
    ];

    /** @return HasMany<AppInstanceDependencyResolution, $this> */
    public function resolutions(): HasMany
    {
        return $this->hasMany(AppInstanceDependencyResolution::class, 'dependency_package_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ecosystem' => DependencyEcosystem::class,
        ];
    }
}
