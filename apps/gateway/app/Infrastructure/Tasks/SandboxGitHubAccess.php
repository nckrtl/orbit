<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Tasks\TaskPullRequestException;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use Throwable;

/** Install renewable Git access after Pi readiness and before Project setup. */
final readonly class SandboxGitHubAccess
{
    public function __construct(private TaskWorkspaceExecutor $guest, private LeafCertificateSigner $certificates) {}

    public function prepare(Instance $workspace): void
    {
        $sandbox = $workspace->taskSandbox?->fresh();
        $repository = GitHubRepository::fromOrigin((string) $workspace->project->repository_url);
        $url = config('app.url');
        $program = file_get_contents(resource_path('compute/guest-github-access.py'));
        if ($sandbox === null || ! in_array($sandbox->provider, ['upcloud', 'incus'], true) || $sandbox->pi_ready_at === null
            || ! $repository instanceof GitHubRepository || ! is_string($url) || ! str_starts_with($url, 'https://') || ! is_string($program)) {
            throw new TaskPullRequestException('The sandbox repository access configuration is unavailable.');
        }
        try {
            $program = str_replace("SOURCE = None\n", 'SOURCE = __import__("base64").b64decode("'.base64_encode($program).'").decode()'."\n", $program);
            $request = ['sandbox_id' => $sandbox->id, 'repository' => $repository->owner.'/'.$repository->name,
                'url' => rtrim($url, '/').'/api/v1/compute/github-token', 'gateway_address' => $sandbox->enrollment['gateway_address'] ?? null,
                'ca' => $this->certificates->rootCertificate()];
            $result = $this->guest->execute($workspace, new RemoteCommand(['sudo', '-n', 'python3', '-I', '-c', $program, 'install'],
                protectedInput: ProtectedInput::fromString(json_encode($request, JSON_THROW_ON_ERROR)), timeout: 30, maxOutputBytes: 1024),
                'sandbox-github-access', 'tasks.github_setup_failed');
            if ($result->truncated || json_decode($result->stdout, true) !== ['ready' => true]) {
                throw new TaskPullRequestException('The sandbox repository access did not confirm readiness.');
            }
        } catch (Throwable) {
            throw new TaskPullRequestException('The sandbox repository access did not confirm readiness.');
        }
    }
}
