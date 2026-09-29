<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Instances\Dependencies\DependencyEcosystem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * @property int $id
 * @property int $instance_id
 * @property DependencyEcosystem $ecosystem
 * @property bool $present
 * @property CarbonImmutable $observed_at
 * @property string $project_root
 * @property string|null $source_reference
 * @property array<string, string|null> $file_hashes
 * @property string|null $format
 * @property-read Instance $instance
 * @property-read Collection<int, InstanceDependencyResolution> $resolutions
 * @property-read Collection<int, InstanceDependencyEdge> $edges
 */
final class InstanceDependencyObservation extends Model
{
    #[\Override]
    protected $table = 'instance_dependency_observations';

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'instance_id',
        'ecosystem',
        'present',
        'observed_at',
        'project_root',
        'source_reference',
        'file_hashes',
        'format',
    ];

    protected static function booted(): void
    {
        self::saving(static function (self $record): void {
            if ($record->source_reference !== null && preg_match('/^[^\s:@?\x00-\x1f\x7f]+$/D', $record->source_reference) !== 1) {
                throw new InvalidArgumentException('Dependency source references must be credential-free revisions, not URLs.');
            }

            $fileHashes = $record->getAttribute('file_hashes');
            if (! is_array($fileHashes)) {
                throw new InvalidArgumentException('Dependency input provenance requires a filename-to-hash map.');
            }

            foreach ($fileHashes as $path => $hash) {
                if (! is_string($path) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/D', $path) !== 1
                    || ($hash !== null && (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1))) {
                    throw new InvalidArgumentException('Dependency input provenance requires root filenames and SHA-256 hashes.');
                }
            }
        });
    }

    /** @return BelongsTo<Instance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class, 'instance_id');
    }

    /** @return HasMany<InstanceDependencyResolution, $this> */
    public function resolutions(): HasMany
    {
        return $this->hasMany(InstanceDependencyResolution::class, 'observation_id');
    }

    /** @return HasMany<InstanceDependencyEdge, $this> */
    public function edges(): HasMany
    {
        return $this->hasMany(InstanceDependencyEdge::class, 'observation_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ecosystem' => DependencyEcosystem::class,
            'present' => 'boolean',
            'observed_at' => 'immutable_datetime',
            'file_hashes' => 'array',
        ];
    }
}
