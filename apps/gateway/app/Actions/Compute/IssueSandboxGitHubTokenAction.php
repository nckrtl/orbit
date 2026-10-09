<?php

declare(strict_types=1);

namespace App\Actions\Compute;

use App\Domain\Compute\SandboxState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\Compute\SandboxFleetIdentity;
use App\Models\Instance;
use App\Models\Node;
use App\Models\TaskSandbox;
use SensitiveParameter;
use Throwable;

/** Only the enrolled VM can renew access to its owning Project repository. */
final readonly class IssueSandboxGitHubTokenAction
{
    public function __construct(private RepositoryPullRequestAccess $access, private SandboxFleetIdentity $identity, private TaskExecutionLock $groups) {}

    public function handle(Node $node, #[SensitiveParameter] ?string $secret): string
    {
        $sandbox = TaskSandbox::query()->where('node_id', $node->id)->whereKey($node->compute_sandbox_id)->first();
        if ($sandbox === null || $sandbox->group_id === null) {
            throw $this->refused();
        }

        return $this->groups->synchronized($sandbox->group_id, function () use ($sandbox, $node, $secret): string {
            $sandbox->refresh();
            $group = $sandbox->group;
            $workspace = $group?->taskable;
            $token = $sandbox->pi_token;
            if (! in_array($sandbox->provider, ['upcloud', 'incus'], true) || $sandbox->state !== SandboxState::Running || $sandbox->desired_power !== 'running'
                || ! is_string($secret) || $secret === '' || ! is_string($token) || ! hash_equals($token, $secret)
                || $sandbox->pi_ready_at === null || $sandbox->model_key_revoked_at !== null
                || $group === null || $group->task_compute !== TaskCompute::Vm || $group->execution_mode !== TaskExecutionMode::Managed
                || ! in_array($group->status, TaskGroupStatus::active(), true)
                || ! $workspace instanceof Instance || $workspace->task_sandbox_id !== $sandbox->id
                || $workspace->node_id !== $node->id || $workspace->project_id !== $group->project_id) {
                throw $this->refused();
            }
            $repository = GitHubRepository::fromOrigin((string) $group->project->repository_url);
            if (! $repository instanceof GitHubRepository) {
                throw $this->refused();
            }
            try {
                $this->identity->assertReady($sandbox, $node);

                return $this->access->sandboxToken($repository);
            } catch (Throwable) {
                throw new ResourceOperationException('compute.github_unavailable', 'Temporary repository access could not be issued.', 503);
            }
        });
    }

    private function refused(): ResourceOperationException
    {
        return new ResourceOperationException('compute.github_refused', 'The running sandbox identity is required.', 403);
    }
}
