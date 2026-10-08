<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\GitHub\GitHubRepository;
use App\Domain\Instances\InstanceState;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\Tasks\TaskBaseBranchFetcher;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskPullRequestException;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use App\Models\Task;

/** Prepare Git state only; runtime/image preparation must finish before a task can claim this workspace. */
final readonly class SandboxWorkspaceSource
{
    public function __construct(private TaskWorkspaceExecutor $guest, private TaskBaseBranchFetcher $fetcher) {}

    public function prepare(Task $group, ?string $requiredCommit = null): Instance
    {
        $group->loadMissing(['project', 'taskable']);
        $workspace = $group->taskable;
        $repository = GitHubRepository::fromOrigin((string) $group->project->repository_url);
        $base = $group->project->default_branch;
        if ($group->task_compute !== TaskCompute::Vm || ! $workspace instanceof Instance || $workspace->task_sandbox_id === null
            || $workspace->project_id !== $group->project_id || $workspace->taskSandbox?->group_id !== $group->id
            || ! $repository instanceof GitHubRepository || ! is_string($base) || ! GitBranchName::isValid($base)
            || ! in_array($workspace->status, [InstanceState::Reserved, InstanceState::CheckoutPrepared, InstanceState::SourceResolved, InstanceState::Active], true)) {
            throw new TaskPullRequestException('The sandbox source identity is unavailable.');
        }
        $request = ['sandbox_id' => $workspace->task_sandbox_id, 'checkout' => $workspace->checkout_path,
            'repository' => 'https://github.com/'.$repository->owner.'/'.$repository->name.'.git', 'branch' => 'task-'.$group->id, 'base' => $base];
        if ($requiredCommit !== null) {
            if (preg_match('/\A[a-f0-9]{40}\z/D', $requiredCommit) !== 1) {
                throw new TaskPullRequestException('The published recovery commit is invalid.');
            }
            $request['required_commit'] = $requiredCommit;
        }
        $template = $workspace->taskSandbox?->spec['source_template'] ?? null;
        if ($template !== null) {
            if (! is_array($template) || ($template['repository'] ?? null) !== $request['repository'] || ($template['base'] ?? null) !== $base) {
                throw new TaskPullRequestException('The sandbox source template does not match the Project.');
            }
            $request['source_template'] = $template;
        }
        if (in_array($workspace->status, [InstanceState::SourceResolved, InstanceState::Active], true)) {
            $result = $this->execute($workspace, ['operation' => 'inspect', ...$request]);
            if ($requiredCommit !== null && (($result['starting_commit'] ?? null) !== $requiredCommit || $workspace->starting_commit !== $requiredCommit)) {
                throw new TaskPullRequestException('The recorded recovery commit changed.');
            }

            return $workspace;
        }
        if (($this->execute($workspace, ['operation' => 'initialize', ...$request])['initialized'] ?? null) !== true) {
            throw new TaskPullRequestException('The sandbox source could not be initialized.');
        }
        if ($workspace->taskSandbox->provider === 'incus' && $group->project->slug === 'orbit'
            && ($this->execute($workspace, ['operation' => 'github_dns', ...$request])['ready'] ?? null) !== true) {
            throw new TaskPullRequestException('The sandbox GitHub DNS bootstrap is unavailable.');
        }
        $this->fetcher->fetchForTurn($group);
        $result = $this->execute($workspace, ['operation' => 'checkout', ...$request]);
        $commit = $result['starting_commit'] ?? null;
        if (($requiredCommit !== null && $commit !== $requiredCommit) || ! is_string($commit) || preg_match('/\A[a-f0-9]{40}(?:[a-f0-9]{24})?\z/D', $commit) !== 1) {
            throw new TaskPullRequestException('The sandbox source returned an invalid starting commit.');
        }
        $workspace->update(['status' => InstanceState::SourceResolved, 'starting_commit' => $workspace->starting_commit ?? $commit]);

        return $workspace->refresh();
    }

    /** @param array<string, mixed> $request
     * @return array<array-key, mixed>
     */
    private function execute(Instance $workspace, array $request): array
    {
        $name = $request['operation'] === 'github_dns' ? 'guest-github-dns.py' : 'guest-workspace-source.py';
        $program = file_get_contents(resource_path('compute/'.$name));
        if (! is_string($program)) {
            throw new TaskPullRequestException('The sandbox source program is unavailable.');
        }
        $result = $this->guest->execute($workspace, new RemoteCommand([...($request['operation'] === 'github_dns' ? ['sudo', '-n'] : []), 'python3', '-I', '-c', $program],
            input: json_encode($request, JSON_THROW_ON_ERROR), timeout: 180, maxOutputBytes: 8192), 'sandbox-source', 'tasks.source_failed');
        $data = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        if ($result->truncated || ! is_array($data) || array_is_list($data)) {
            throw new TaskPullRequestException('The sandbox source response is invalid.');
        }

        return $data;
    }
}
