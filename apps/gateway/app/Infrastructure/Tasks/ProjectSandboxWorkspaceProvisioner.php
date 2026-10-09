<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Actions\Compute\AllocateTaskSandboxAction;
use App\Actions\Compute\EnrollIncusSandboxAction;
use App\Actions\Compute\EnrollUpCloudSandboxAction;
use App\Actions\Compute\ProvisionTaskSandboxAction;
use App\Actions\Instances\ImportInstanceEnvironmentAction;
use App\Actions\Instances\SynchronizeInstanceEnvironmentAction;
use App\Actions\Nodes\AddNodeAccessAction;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxNetworkPolicy;
use App\Domain\Compute\SandboxState;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Instances\DevelopmentInstanceProvisioner;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProjectSandboxRuntimeGuard;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskExecutionHold;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Infrastructure\Compute\IncusSandboxNodeBootstrap;
use App\Infrastructure\Compute\SandboxFleetIdentity;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskSandbox;
use Illuminate\Support\Facades\DB;

/** One owned Project VM; admission and recovery preserve its recorded provider. */
final readonly class ProjectSandboxWorkspaceProvisioner
{
    public function __construct(private TaskExecutionLock $groups, private ProvisionTaskSandboxAction $compute, private AllocateTaskSandboxAction $allocate,
        private EnrollIncusSandboxAction $localEnroll, private IncusSandboxNodeBootstrap $localBootstrap, private SandboxNetworkPolicy $network,
        private EnrollUpCloudSandboxAction $enroll, private SandboxFleetIdentity $identity,
        private SandboxWorkspaceSource $source, private SandboxPiRuntime $pi, private SandboxPiArtifact $artifact, private SandboxGitHubAccess $gitAccess,
        private DevelopmentInstanceProvisioner $development, private ImportInstanceEnvironmentAction $environmentImport,
        private SynchronizeInstanceEnvironmentAction $environmentSync, private GitHubApi $github, private RepositoryPullRequestAccess $pullRequests, private AddNodeAccessAction $nodeAccess) {}

    public function provision(Task $reserved): Instance
    {
        return $this->prepare($reserved, false);
    }

    public function restore(Task $group): Instance
    {
        return $this->prepare($group, true);
    }

    public function resumeLocal(Task $group): Instance
    {
        return $this->groups->synchronized($group->id, function () use ($group): Instance {
            $group->refresh()->load(['project', 'taskable']);
            $this->assertClaim($group, $group, true);
            $workspace = $group->taskable;
            $sandbox = $workspace instanceof Instance ? $workspace->taskSandbox : null;
            if (! $workspace instanceof Instance || $sandbox === null || $sandbox->provider !== 'incus'
                || ! config('compute.incus.project_workspaces_enabled', false)) {
                throw $this->ownership();
            }
            $this->assertWorkspace($workspace, $group, $sandbox);
            $node = $workspace->node;
            $this->localBootstrap->prepare($sandbox, $node);
            $this->network->ensure($sandbox);
            $this->identity->assertReady($sandbox, $node);
            $this->pi->prepare($workspace);
            $this->gitAccess->prepare($workspace);

            return $workspace->refresh();
        });
    }

    private function prepare(Task $reserved, bool $restore): Instance
    {
        return $this->groups->synchronized($reserved->id, function () use ($reserved, $restore): Instance {
            $group = Task::topLevel()->with(['project', 'taskable'])->findOrFail($reserved->id);
            $this->assertClaim($group, $reserved, $restore);
            if (! config('compute.project_claims_enabled', false)) {
                throw new ComputeException('compute.not_ready', 'The Project sandbox lane is not ready on this Gateway. Project sandbox compute is disabled.');
            }
            if (((! config('compute.upcloud.enabled', false) || ! config('compute.upcloud.enrollment_enabled', false))
                && (! config('compute.incus.enabled', false) || ! config('compute.incus.enrollment_enabled', false) || ! config('compute.incus.project_workspaces_enabled', false)))
                || ! config('compute.model_proxy.enabled', false) || ! is_array(config('compute.pi.models')) || config('compute.pi.models') === []
                || $group->implementer_agent_driver !== 'pi' || $group->reviewer_agent_driver !== 'pi') {
                throw new ComputeException('compute.not_ready', 'Project claims require enrolled compute, model credentials, and Pi configuration.');
            }
            $this->artifact->assertConfigured();
            $existing = TaskSandbox::query()->where('group_id', $group->id)->where('state', '!=', SandboxState::Destroyed)->first();
            if ($existing !== null && ! in_array($existing->provider, ['upcloud', 'incus'], true)) {
                throw new ComputeException('compute.placement_conflict', 'The project reservation has another placement.');
            }
            $restoreCommit = null;
            if ($restore) {
                $previous = TaskSandbox::query()->where('group_id', $group->id)->where('provider', 'upcloud')
                    ->where('state', SandboxState::Destroyed)->whereNull('node_id')->whereNull('server_id')->whereNull('disk_id')->exists();
                if (! $previous || ($existing !== null && ! isset($existing->spec['restore_commit']))) {
                    throw $this->ownership();
                }
                $published = $this->publishedCommit($group);
                $restoreCommit = $existing->spec['restore_commit'] ?? $published;
                if (! is_string($restoreCommit) || preg_match('/\A[a-f0-9]{40}\z/D', $restoreCommit) !== 1) {
                    throw $this->ownership();
                }
            }
            if ($group->taskable_id !== null) {
                if (! $group->taskable instanceof Instance || $existing === null) {
                    throw $this->ownership();
                }
                $this->assertWorkspace($group->taskable, $group, $existing);
            } elseif (Instance::query()->where('project_id', $group->project_id)->where('name', TaskWorkspaceName::for($group))->exists()) {
                throw $this->ownership();
            }
            $sandbox = $restore ? $this->compute->execute($group, $restoreCommit) : $this->allocate->execute($group);
            if (! in_array($sandbox->provider, ['upcloud', 'incus'], true) || $sandbox->group_id !== $group->id
                || $sandbox->state !== SandboxState::Running || $sandbox->desired_power !== 'running') {
                throw new ComputeException('compute.starting', 'The project sandbox is still starting.');
            }
            if ($sandbox->provider === 'incus' && (! config('compute.incus.enrollment_enabled', false) || ! config('compute.incus.project_workspaces_enabled', false))) {
                throw new ComputeException('compute.not_ready', 'Local Project workspace admission is disabled.');
            }
            if ($sandbox->enrolled_at === null) {
                $node = $sandbox->provider === 'incus' ? $this->localEnroll->execute($sandbox) : $this->enroll->execute($sandbox);
            } else {
                $node = Node::query()->find($sandbox->node_id);
                if ($node === null) {
                    throw $this->ownership();
                }
                $this->identity->assertReady($sandbox, $node);
            }
            $sandbox->refresh();
            $this->nodeAccess->execute($node, $node);
            DB::transaction(function () use ($group, $reserved, $sandbox, $node, $restore): void {
                $locked = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
                $this->assertClaim($locked, $reserved, $restore);
                if ($locked->taskable_id !== null) {
                    if (! $locked->taskable instanceof Instance) {
                        throw $this->ownership();
                    }
                    $this->assertWorkspace($locked->taskable, $locked, $sandbox);

                    return;
                }
                if (Instance::query()->where('task_sandbox_id', $sandbox->id)->exists()
                    || Instance::query()->where('project_id', $group->project_id)->where('name', TaskWorkspaceName::for($group))->exists()) {
                    throw $this->ownership();
                }
                $workspace = Instance::query()->create(['project_id' => $group->project_id, 'node_id' => $node->id,
                    'name' => TaskWorkspaceName::for($group), 'branch_override' => TaskWorkspaceName::for($group),
                    'checkout_path' => '/home/orbit/orbit', 'source_layout' => InstanceSourceLayout::Checkout,
                    'root' => $group->project->type->isWebServing() ? $group->project->root : null,
                    'task_workspace_routed' => $group->project->type->isWebServing(), 'task_sandbox_id' => $sandbox->id, 'status' => InstanceState::Reserved]);
                $locked->taskable()->associate($workspace);
                $locked->save();
            });
            $workspace = $this->source->prepare($group->refresh(), $restoreCommit);
            $this->pi->prepare($workspace);
            $this->gitAccess->prepare($workspace);
            if ($workspace->task_workspace_routed) {
                $this->development->reserve($workspace, null);
                foreach ($workspace->routes()->get() as $route) {
                    ProjectSandboxRuntimeGuard::assertRoute($workspace, $route);
                }
                $workspace = $this->development->complete($workspace, null);
                if ($workspace->source_is_laravel === true && ($workspace->environmentValues()->exists()
                    || $this->environmentImport->importExisting($workspace) !== null)) {
                    $this->environmentSync->execute($workspace);
                }
            }
            $this->assertClaim($group->refresh(), $reserved, $restore);

            return $workspace->refresh();
        });
    }

    private function assertClaim(Task $group, Task $reserved, bool $restore): void
    {
        $current = $restore
            ? in_array($group->status, [TaskGroupStatus::Running, ...TaskGroupStatus::awaitingCompletion()], true)
            : $group->status === TaskGroupStatus::Reserved && $group->reserved_at !== null
                && $reserved->reserved_at !== null && $group->reserved_at->equalTo($reserved->reserved_at);
        if ($group->project->slug === 'orbit' || $group->execution_mode !== TaskExecutionMode::Managed || $group->task_compute !== TaskCompute::Vm
            || ! $current || TaskExecutionHold::active($group)) {
            throw new ComputeException('compute.claim_changed', 'The project sandbox claim is no longer current.');
        }
    }

    private function publishedCommit(Task $group): string
    {
        $repository = GitHubRepository::fromOrigin((string) $group->project->repository_url);
        $number = $repository instanceof GitHubRepository && is_string($group->pr_url)
            ? $repository->pullRequestNumber($group->pr_url) : null;
        if (! $repository instanceof GitHubRepository || $number === null) {
            throw new ComputeException('compute.rebuild_required', 'Recovery requires a published pull request in the Project repository.');
        }
        try {
            $pull = $this->github->pullRequest($this->pullRequests->cachedReadToken($repository), $repository, $number);
        } catch (\Throwable) {
            throw new ComputeException('compute.rebuild_required', 'The published pull request could not be confirmed.');
        }
        if ($pull->state !== GitHubPullRequestState::Open || ! is_string($pull->headSha)
            || preg_match('/\A[a-f0-9]{40}\z/D', $pull->headSha) !== 1) {
            throw new ComputeException('compute.rebuild_required', 'Recovery requires an open pull request with a confirmed commit.');
        }

        return $pull->headSha;
    }

    private function assertWorkspace(Instance $workspace, Task $group, TaskSandbox $sandbox): void
    {
        if ($workspace->project_id !== $group->project_id || $workspace->task_sandbox_id !== $sandbox->id
            || $sandbox->node_id === null || $workspace->node_id !== $sandbox->node_id || $workspace->checkout_path !== '/home/orbit/orbit'
            || $workspace->name !== TaskWorkspaceName::for($group) || $workspace->branch_override !== $workspace->name || ! is_bool($workspace->task_workspace_routed)
            || ! in_array($workspace->status, [InstanceState::Reserved, InstanceState::CheckoutPrepared, InstanceState::SourceResolved, InstanceState::Active], true)
            || Task::withoutGlobalScope('subtask')->where('taskable_type', $workspace->getMorphClass())->where('taskable_id', $workspace->id)->whereKeyNot($group->id)->exists()) {
            throw $this->ownership();
        }
    }

    private function ownership(): ComputeException
    {
        return new ComputeException('compute.ownership_mismatch', 'The project workspace does not match its sandbox reservation.');
    }
}
