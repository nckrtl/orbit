<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Projects\ProjectType;
use App\Domain\SourceControl\GitRepositoryIdentity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SensitiveParameter;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property ProjectType $type
 * @property string $repository_url
 * @property string $repository_identity
 * @property string|null $default_branch
 * @property string|null $root
 * @property array<string, mixed>|null $defaults
 * @property-read Collection<int, TaskGroup> $taskGroups
 */
final class App extends Model
{
    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'type' => 'laravel-app',
    ];

    /** @var list<string> */
    #[\Override]
    protected $fillable = ['name', 'slug', 'type', 'repository_url', 'default_branch', 'root', 'defaults'];

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['defaults', 'repository_identity'];

    protected static function booted(): void
    {
        self::creating(static function (self $app): void {
            $app->repository_identity = GitRepositoryIdentity::derive($app->repository_url);
        });
    }

    public static function findByRepositoryOrigin(#[SensitiveParameter] string $repository): ?self
    {
        return self::query()
            ->where('repository_identity', GitRepositoryIdentity::derive($repository))
            ->first();
    }

    /** @return HasMany<AppInstance, $this> */
    public function appInstances(): HasMany
    {
        return $this->hasMany(AppInstance::class);
    }

    /** @return HasMany<Route, $this> */
    public function routes(): HasMany
    {
        return $this->hasMany(Route::class);
    }

    /** @return HasMany<ProcessDefinition, $this> */
    public function processDefinitions(): HasMany
    {
        return $this->hasMany(ProcessDefinition::class);
    }

    /** @return HasMany<ScheduleDefinition, $this> */
    public function scheduleDefinitions(): HasMany
    {
        return $this->hasMany(ScheduleDefinition::class);
    }

    /** @return HasMany<AppUpdate, $this> */
    public function updates(): HasMany
    {
        return $this->hasMany(AppUpdate::class);
    }

    /** @return HasMany<TaskGroup, $this> */
    public function taskGroups(): HasMany
    {
        return $this->hasMany(TaskGroup::class);
    }

    public function isWebServing(): bool
    {
        return $this->type->isWebServing();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'defaults' => 'array',
            'type' => ProjectType::class,
        ];
    }
}
