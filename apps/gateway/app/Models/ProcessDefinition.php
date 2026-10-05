<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\ResourceOperationException;
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
final class ProcessDefinition extends Model
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
            $apps = $definition->project->configuredApps();
            if ($definition->app === null && count($apps) === 1) {
                $definition->app = $apps[0]['name'];
            }
            if (! array_any($apps, static fn (array $app): bool => $app['name'] === $definition->app)) {
                throw new ResourceOperationException($definition->app === null ? 'app.required' : 'app.not_found', 'Select a Project app for the Process definition.');
            }
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
