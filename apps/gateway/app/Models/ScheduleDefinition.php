<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property int $project_id
 * @property string|null $app
 * @property string $name
 * @property list<string> $environments
 * @property array<string, mixed> $spec
 * @property-read Project $project
 */
final class ScheduleDefinition extends Model
{
    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'string';

    /** @var list<string> */
    #[\Override]
    protected $fillable = ['project_id', 'name', 'environments', 'spec', 'app'];

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['spec'];

    protected static function booted(): void
    {
        self::creating(static function (self $definition): void {
            $definition->app = $definition->project->appName($definition->app, 'Schedule');
            $id = $definition->getAttribute('id');
            $definition->id = is_string($id) && $id !== '' ? $id : (string) Str::uuid();
        });
    }

    /** @return array<array-key, mixed> */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    public function getRouteKeyName(): string
    {
        return 'name';
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'environments' => 'array',
            'spec' => 'array',
        ];
    }
}
