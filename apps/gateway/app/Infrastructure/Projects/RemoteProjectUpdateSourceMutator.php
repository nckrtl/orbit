<?php

declare(strict_types=1);

namespace App\Infrastructure\Projects;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Projects\ProjectUpdateSourceMutator;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\GitHub\GitReadScript;
use App\Infrastructure\SourceControl\WorkspaceGit;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Tasks\TaskWorkerUser;
use App\Models\Instance;

final readonly class RemoteProjectUpdateSourceMutator implements ProjectUpdateSourceMutator
{
    public function __construct(
        private DevelopmentSshExecutor $ssh,
        private RepositoryReadAccess $access,
    ) {}

    public function preflightRepository(array $checkouts, string $currentUrl, string $proposedUrl): void
    {
        $read = null;

        foreach ($this->uniqueCheckouts($checkouts) as $checkout) {
            $read ??= $this->access->for($proposedUrl, $checkout->loadMissing('project')->project->source_access);
            $this->run(
                $checkout,
                [$checkout->checkout_path, $currentUrl, $proposedUrl],
                <<<'BASH'
                    path=$1
                    current=$2
                    proposed=$3
                    test -d "$path"
                    test -d "$path/.git"
                    test ! -f "$path/.git"
                    origin=$(git -C "$path" config --get remote.origin.url)
                    test "$origin" = "$current"
                    git -C "$path" rev-parse --verify --quiet HEAD >/dev/null
                    git_read git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$path" ls-remote --heads -- "$proposed" >/dev/null
                    BASH,
                'app-update-repository-preflight',
                'project.repository_preflight_failed',
                read: $read,
            );
        }
    }

    public function changeOrigins(array $checkouts, string $previousUrl, string $newUrl, array $evidence): array
    {
        $byPath = [];

        foreach ($evidence as $row) {
            $byPath[$row['path']] = $row;
        }

        foreach ($this->uniqueCheckouts($checkouts) as $checkout) {
            $path = rtrim($checkout->checkout_path, '/');

            if (($byPath[$path]['mutated'] ?? false) === true) {
                continue;
            }

            $this->run(
                $checkout,
                [$path, $newUrl],
                <<<'BASH'
                    path=$1
                    url=$2
                    test -d "$path/.git"
                    test ! -f "$path/.git"
                    git -C "$path" remote set-url origin -- "$url"
                    test "$(git -C "$path" config --get remote.origin.url)" = "$url"
                    BASH,
                'app-update-repository-origin',
                'project.repository_origin_failed',
            );

            $byPath[$path] = [
                'path' => $path,
                'previous_url' => $previousUrl,
                'current_url' => $newUrl,
                'mutated' => true,
            ];
        }

        return array_values($byPath);
    }

    public function restoreOrigins(array $mutations): void
    {
        foreach ($mutations as $mutation) {
            if ($mutation['mutated'] !== true) {
                continue;
            }

            $checkout = Instance::query()
                ->where('checkout_path', $mutation['path'])
                ->first();

            if (! $checkout instanceof Instance) {
                continue;
            }

            $this->run(
                $checkout,
                [$mutation['path'], $mutation['previous_url']],
                <<<'BASH'
                    path=$1
                    url=$2
                    git -C "$path" remote set-url origin -- "$url"
                    BASH,
                'app-update-repository-restore',
                'project.repository_origin_failed',
            );
        }
    }

    public function preflightDefaultBranch(Instance $instance, string $newBranch): void
    {
        $this->run(
            $instance,
            [$instance->checkout_path, $newBranch],
            <<<'BASH'
                path=$1
                branch=$2
                test -d "$path"
                git -C "$path" rev-parse --is-inside-work-tree >/dev/null
                git_read git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$path" fetch --prune -- origin
                git -C "$path" show-ref --verify --quiet "refs/remotes/origin/$branch"
                BASH,
            'app-update-default-branch-preflight',
            'project.source_switch_failed',
            read: $this->access->for(
                $instance->loadMissing('project')->project->repository_url,
                $instance->project->source_access,
            ),
        );
    }

    public function switchDefaultBranch(Instance $instance, string $newBranch): void
    {
        $this->run(
            $instance,
            [$instance->checkout_path, $newBranch],
            <<<'BASH'
                path=$1
                branch=$2
                git -C "$path" checkout --track -B "$branch" "origin/$branch"
                BASH,
            'app-update-default-branch-switch',
            'project.source_switch_failed',
        );
    }

    public function restoreDefaultBranch(Instance $instance, string $previousBranch): void
    {
        $this->run(
            $instance,
            [$instance->checkout_path, $previousBranch],
            <<<'BASH'
                path=$1
                branch=$2
                git -C "$path" checkout "$branch" --
                BASH,
            'app-update-default-branch-restore',
            'project.source_switch_failed',
        );
    }

    /**
     * @param  list<Instance>  $checkouts
     * @return list<Instance>
     */
    private function uniqueCheckouts(array $checkouts): array
    {
        $unique = [];

        foreach ($checkouts as $checkout) {
            $unique[rtrim($checkout->checkout_path, '/')] = $checkout;
        }

        return array_values($unique);
    }

    /** @param list<string> $arguments */
    private function run(
        Instance $instance,
        array $arguments,
        string $script,
        string $step,
        string $errorCode,
        ?GitReadEnvironment $read = null,
    ): void {
        $script = WorkspaceGit::bashPreamble().WorkspaceGit::workerPreamble(TaskWorkerUser::name()).$script;
        $readScript = $read instanceof GitReadEnvironment
            ? GitReadScript::for($read, $script)
            : null;

        try {
            $this->ssh->execute(
                $instance->node,
                new RemoteCommand(
                    arguments: ['bash', '-seu', '--', ...$arguments],
                    input: $readScript === null ? $script : $readScript->input,
                    protectedInput: $readScript?->protectedInput,
                ),
                step: $step,
                errorCode: $errorCode,
            );
        } catch (RuntimeConvergenceException $exception) {
            throw new ResourceOperationException(
                errorCode: $exception->errorCode,
                message: $exception->getMessage(),
                status: 409,
                previous: $exception,
            );
        }
    }
}
