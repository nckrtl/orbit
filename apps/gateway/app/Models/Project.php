<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Projects\ProjectCode;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectType;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Domain\Tasks\TaskCompute;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SensitiveParameter;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $slug
 * @property ProjectType $type
 * @property string $repository_url
 * @property string $repository_identity
 * @property ProjectSourceAccess $source_access
 * @property string|null $default_branch
 * @property string|null $root
 * @property string|null $task_check
 * @property TaskCompute $task_compute
 * @property bool $task_workspace_routed
 * @property-read Collection<int, Task> $tasks
 */
final class Project extends Model
{
    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'type' => 'laravel-app',
        'source_access' => 'github_app',
        'task_compute' => 'shared',
    ];

    /** @var list<string> */
    #[\Override]
    protected $fillable = ['name', 'code', 'slug', 'type', 'repository_url', 'source_access', 'default_branch', 'root', 'task_check', 'task_workspace_routed', 'task_compute'];

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['repository_identity'];

    protected static function booted(): void
    {
        self::creating(static function (self $project): void {

            $used = ValidatedData::stringList(self::query()->pluck('code')->all());
            $project->code = ProjectCode::validate($project->code ?? ProjectCode::suggest($project->slug, $used));
            $project->repository_identity = GitRepositoryIdentity::derive($project->repository_url);
        });
    }

    public static function findByRepositoryOrigin(#[SensitiveParameter] string $repository): ?self
    {
        return self::query()
            ->where('repository_identity', GitRepositoryIdentity::derive($repository))
            ->first();
    }

    /** @return HasMany<Instance, $this> */
    public function instances(): HasMany
    {
        return $this->hasMany(Instance::class);
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

    /** @return HasMany<ProjectUpdate, $this> */
    public function updates(): HasMany
    {
        return $this->hasMany(ProjectUpdate::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->topLevel();
    }

    /** @return HasMany<TaskDefinition, $this> */
    public function taskDefinitions(): HasMany
    {
        return $this->hasMany(TaskDefinition::class);
    }

    /** @return HasMany<ProjectNodeExclusion, $this> */
    public function nodeExclusions(): HasMany
    {
        return $this->hasMany(ProjectNodeExclusion::class);
    }

    public function isWebServing(): bool
    {
        return $this->type->isWebServing();
    }

    public function taskCheckCommand(): ?string
    {
        $command = $this->task_check;

        return is_string($command) && trim($command) !== '' ? trim($command) : null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ProjectType::class,
            'source_access' => ProjectSourceAccess::class,
            'task_workspace_routed' => 'boolean',
            'task_compute' => TaskCompute::class,
        ];
    }
}
