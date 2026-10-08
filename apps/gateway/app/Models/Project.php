<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Projects\ProjectApps;
use App\Domain\Projects\ProjectCode;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
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
 * @property list<array{name: string, path: string, web_root: ?string, type: string}>|null $apps
 * @property string|null $root
 * @property string|null $task_check
 * @property TaskCompute $task_compute
 * @property bool $task_workspace_routed
 * @property bool $review_and_merge
 * @property string|null $merge_check
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
        'review_and_merge' => false,
    ];

    /** @var list<string> */
    #[\Override]
    protected $fillable = ['name', 'code', 'slug', 'type', 'repository_url', 'source_access', 'default_branch', 'root', 'apps', 'task_check', 'task_workspace_routed', 'task_compute', 'review_and_merge', 'merge_check'];

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['repository_identity'];

    protected static function booted(): void
    {
        self::saving(static function (self $project): void {
            if ($project->isDirty('apps')) {
                $project->apps = ProjectApps::validate($project->apps);
            } elseif ($project->apps === null || $project->isDirty(['root', 'type'])) {
                // Expand-only bridge for the existing root writers; removed with the old interfaces.
                $project->apps = ProjectApps::validate($project->configuredApps());
            }
        });

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

    /** @return list<array{name: string, path: string, web_root: ?string, type: string}> */
    public function configuredApps(): array
    {
        if (! $this->isDirty('apps') && $this->isDirty(['root', 'type'])) {
            $originalApps = $this->getOriginal('apps');
            $originalRoot = $this->getOriginal('root');
            $originalType = $this->getOriginal('type');
            if ($originalApps !== null) {
                if (($originalRoot !== null && ! is_string($originalRoot)) || ! $originalType instanceof ProjectType) {
                    throw new ResourceOperationException('project.apps_invalid', 'The retained legacy Project configuration is invalid.');
                }
                if ($originalApps !== ProjectApps::legacy($originalRoot, $originalType)) {
                    throw new ResourceOperationException('project.apps_invalid', 'Legacy root/type writes cannot replace named app configuration.');
                }
            }

            return ProjectApps::legacy($this->root, $this->type);
        }

        return $this->apps ?? ProjectApps::legacy($this->root, $this->type);
    }

    public function isWebServing(): bool
    {
        return $this->type->isWebServing();
    }

    /**
     * ADR 0203: whether Orbit reviews every push of this Project's tasks, reviews incoming pull requests,
     * and merges reviewed green heads. The switch needs a merge check, the GitHub App, and shared compute.
     */
    public function reviewsAndMerges(): bool
    {
        return $this->review_and_merge && $this->mergeCheckName() !== null
            && $this->source_access === ProjectSourceAccess::GitHubApp && $this->task_compute === TaskCompute::Shared;
    }

    public function mergeCheckName(): ?string
    {
        $name = $this->merge_check;

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
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
            'apps' => 'array',
            'type' => ProjectType::class,
            'source_access' => ProjectSourceAccess::class,
            'task_workspace_routed' => 'boolean',
            'review_and_merge' => 'boolean',
            'task_compute' => TaskCompute::class,
        ];
    }
}
