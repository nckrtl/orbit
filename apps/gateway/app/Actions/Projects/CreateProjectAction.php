<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Data\Projects\CreateProjectData;
use App\Data\Projects\ProjectData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\SourceControl\ProjectRoot;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;

final readonly class CreateProjectAction
{
    public function __construct(
        private RepositoryDefaultBranchResolver $branches,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    /** @return array{project: Project, created: bool} */
    public function execute(CreateProjectData $data): array
    {
        $repositoryUrl = GitRepositoryOrigin::validate($data->repositoryUrl);
        $repositoryIdentity = GitRepositoryIdentity::derive($repositoryUrl);
        $defaultBranch = $data->defaultBranch === null ? null : GitBranchName::validate($data->defaultBranch);
        $root = ProjectRoot::validate($data->root, $data->type);
        $project = Project::query()->where('slug', $data->slug)->first();

        if ($project instanceof Project) {
            $this->assertIdentityMatches($project, $data, $repositoryUrl, $defaultBranch, $root);

            return ['project' => $project, 'created' => false];
        }

        $this->assertRepositoryIdentityAvailable($repositoryIdentity);

        $requestedDefaultBranch = $defaultBranch;

        if ($defaultBranch === null) {
            $defaultBranch = GitBranchName::validate($this->branches->resolve($repositoryUrl, $data->sourceAccess));
        } else {
            $this->branches->verify($repositoryUrl, $defaultBranch, $data->sourceAccess);
        }

        for ($attempt = 0; ; $attempt++) {
            $candidate = new Project([
                'code' => $data->code,
                'slug' => $data->slug,
                'name' => $data->name,
                'type' => $data->type,
                'repository_url' => $repositoryUrl,
                'source_access' => $data->sourceAccess,
                'default_branch' => $defaultBranch,
                'root' => $root,
                'task_check' => $data->resolvedTaskCheck(),
                'task_compute' => $data->taskCompute,
                'task_workspace_routed' => $data->resolvedTaskWorkspaceRouted(),
            ]);
            try {
                $candidate->save();
                $project = $candidate;
                break;
            } catch (UniqueConstraintViolationException $exception) {
                $project = Project::query()->where('slug', $data->slug)->first();

                if ($project instanceof Project) {
                    $this->assertIdentityMatches(
                        $project,
                        $data,
                        $repositoryUrl,
                        $requestedDefaultBranch,
                        $root,
                    );

                    return ['project' => $project, 'created' => false];
                }

                if (Project::query()->where('repository_identity', $repositoryIdentity)->exists()) {
                    throw $this->repositoryIdentityConflict($exception);
                }

                if ($data->code !== null && Project::query()->where('code', $data->code)->exists()) {
                    throw new ResourceOperationException('project.code_conflict', 'This Project code is already in use.', 409, previous: $exception);
                }
                if ($data->code === null && $attempt < 4 && Project::query()->where('code', $candidate->code)->exists()) {
                    continue;
                }

                throw $exception;
            }
        }

        $project = $project->refresh();

        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::ProjectCreated,
            $project->id,
            ProjectData::fromModel($project)->toArray(),
        );

        return ['project' => $project, 'created' => true];
    }

    private function assertRepositoryIdentityAvailable(string $repositoryIdentity): void
    {
        if (Project::query()->where('repository_identity', $repositoryIdentity)->exists()) {
            throw $this->repositoryIdentityConflict();
        }
    }

    private function repositoryIdentityConflict(
        ?UniqueConstraintViolationException $previous = null,
    ): ResourceOperationException {
        return new ResourceOperationException(
            errorCode: 'project.repository_identity_conflict',
            message: 'The repository is already owned by another Project.',
            status: 409,
            previous: $previous,
        );
    }

    private function assertIdentityMatches(
        Project $project,
        CreateProjectData $data,
        string $repositoryUrl,
        ?string $defaultBranch,
        string $root,
    ): void {
        if (
            ($data->code === null || $project->code === $data->code)
            && $project->name === $data->name
            && $project->type === $data->type
            && $project->repository_url === $repositoryUrl
            && $project->source_access === $data->sourceAccess
            && ($defaultBranch === null
            || $project->default_branch === $defaultBranch)
            && $project->root === $root
            && $project->task_compute === $data->taskCompute
            && (! $data->taskCheckProvided || $project->task_check === $data->taskCheck)
            && (! $data->taskWorkspaceRoutedProvided || $project->task_workspace_routed === $data->taskWorkspaceRouted)
        ) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'project.identity_conflict',
            message: "Project [{$project->slug}] already exists with different creation identity.",
            status: 409,
        );
    }
}
