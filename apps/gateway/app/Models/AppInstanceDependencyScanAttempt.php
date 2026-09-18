<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * @property int $id
 * @property int $app_instance_id
 * @property DependencyEcosystem $ecosystem
 * @property CarbonImmutable $attempted_at
 * @property string|null $error_code
 * @property-read AppInstance $appInstance
 */
final class AppInstanceDependencyScanAttempt extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'app_instance_id',
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

    /** @return BelongsTo<AppInstance, $this> */
    public function appInstance(): BelongsTo
    {
        return $this->belongsTo(AppInstance::class, 'app_instance_id');
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
