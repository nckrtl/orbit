<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Instances\Dependencies\DependencyEcosystem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * @property int $id
 * @property int $instance_id
 * @property DependencyEcosystem $ecosystem
 * @property CarbonImmutable $attempted_at
 * @property string|null $error_code
 * @property-read Instance $instance
 */
final class InstanceDependencyScanAttempt extends Model
{
    #[\Override]
    protected $table = 'instance_dependency_scan_attempts';

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'instance_id',
        'ecosystem',
        'attempted_at',
        'error_code',
    ];

    protected static function booted(): void
    {
        self::saving(static function (self $record): void {
            if ($record->error_code !== null && (strlen($record->error_code) > 128
                || preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/D', $record->error_code) !== 1)) {
                throw new InvalidArgumentException('Dependency attempts require a stable error code, not process output.');
            }
        });
    }

    /** @return BelongsTo<Instance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class, 'instance_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ecosystem' => DependencyEcosystem::class,
            'attempted_at' => 'immutable_datetime',
        ];
    }
}
