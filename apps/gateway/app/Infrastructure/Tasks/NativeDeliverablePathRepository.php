<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\Tasks\DeliverablePathRepository;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * Read an immutable tree from the task workspace first, because review-and-merge approvals stay local until
 * Orbit pushes. Without a workspace, or when it lacks the commit, read the origin in a disposable bare repository.
 * The tree is only listed, so neither source runs project code.
 */
final readonly class NativeDeliverablePathRepository implements DeliverablePathRepository
{
    private const int MaxTreeBytes = 16_777_216;

    public function __construct(private ProcessRunner $processes, private RepositoryReadAccess $access, private Filesystem $filesystem, private TaskWorkspaceExecutor $workspaces) {}

    public function defaultBranchCommit(Project $project): string
    {
        $ref = 'refs/heads/'.GitBranchName::validate((string) $project->default_branch);
        $output = $this->run(['git', '-C', '/', 'ls-remote', '--exit-code', '--heads', '--', $project->repository_url, $ref], $project);
        if (preg_match('/\A([0-9a-f]{40}(?:[0-9a-f]{24})?)\t'.preg_quote($ref, '/').'\n?\z/i', $output, $matches) !== 1) {
            throw $this->failure();
        }

        return $matches[1];
    }

    public function files(Project $project, string $commit, ?Instance $workspace = null): array
    {
        if (preg_match('/\A[0-9a-f]{7,64}\z/i', $commit) !== 1) {
            throw $this->failure();
        }
        if ($workspace instanceof Instance && $workspace->checkout_path !== '') {
            $files = $this->workspaceFiles($workspace, $commit);
            if ($files !== null) {
                return $files;
            }
        }

        return $this->originFiles($project, $commit);
    }

    /** @return list<string>|null null when the workspace cannot list this commit, so the origin is tried next. */
    private function workspaceFiles(Instance $workspace, string $commit): ?array
    {
        $git = 'git -c core.hooksPath=/dev/null -c core.fsmonitor=false -c safe.directory="$checkout" -C "$checkout"';
        try {
            $result = $this->workspaces->execute($workspace, new RemoteCommand(
                arguments: TaskWorkerUser::arguments(['bash', '-seu', '--', $workspace->checkout_path, $commit], $workspace),
                input: "checkout=\$1\ncommit=\$2\n{$git} cat-file -e \"\$commit^{commit}\"\n{$git} ls-tree -r -z --full-tree \"\$commit\"\n",
                maxOutputBytes: self::MaxTreeBytes,
            ), 'task-deliverable-paths', 'tasks.deliverable_base_unavailable');
        } catch (Throwable) {
            return null;
        }
        if ($result->truncated) {
            return null;
        }
        try {
            return $this->parseTree($result->stdout);
        } catch (ResourceOperationException) {
            return null;
        }
    }

    /** @return list<string> */
    private function originFiles(Project $project, string $commit): array
    {
        $directory = sys_get_temp_dir().'/orbit-deliverable-path-'.bin2hex(random_bytes(16));
        if (! mkdir($directory, 0700)) {
            throw $this->failure();
        }
        try {
            $this->run(['git', 'init', '--bare', '--', $directory], $project);
            $this->run(['git', '-C', $directory, 'fetch', '--no-tags', '--depth=1', '--filter=blob:none', '--', $project->repository_url, $commit], $project);
            $output = $this->run(['git', '-C', $directory, 'ls-tree', '-r', '-z', $commit], $project, self::MaxTreeBytes);

            return $this->parseTree($output);
        } finally {
            $this->filesystem->deleteDirectory($directory);
        }
    }

    /** @return list<string> */
    private function parseTree(string $output): array
    {
        $files = [];
        foreach (explode("\0", $output) as $entry) {
            if ($entry === '') {
                continue;
            }
            if (preg_match('/\A[0-7]{6} (blob|commit) [0-9a-f]+\t(.+)\z/s', $entry, $matches) !== 1) {
                throw $this->failure();
            }
            if ($matches[1] === 'blob') {
                $files[] = $matches[2];
            }
        }

        return $files;
    }

    /** @param non-empty-list<string> $arguments */
    private function run(array $arguments, Project $project, int $maxOutputBytes = 65_536): string
    {
        try {
            $result = $this->processes->run(new ProcessInvocation(
                arguments: ['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', ...array_slice($arguments, 1)],
                timeout: 60.0,
                maxOutputBytes: $maxOutputBytes,
                environment: $this->access->for($project->repository_url, $project->source_access)->variables,
            ));
        } catch (Throwable) {
            throw $this->failure();
        }
        if (! $result->succeeded() || $result->truncated) {
            throw $this->failure();
        }

        return $result->stdout;
    }

    private function failure(): ResourceOperationException
    {
        return new ResourceOperationException(errorCode: 'tasks.deliverable_base_unavailable', message: 'The repository base for deliverable path validation could not be read.');
    }
}
