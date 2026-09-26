<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\Tasks\TaskBridgeWorktreeRemover;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;

/**
 * Removes one task group's bridge worktree from the registered primary checkout.
 *
 * The bridge is the linked worktree `<worktree root>/task-{id}-e2e` on branch `task-{id}-e2e`.
 * A missing bridge is success. A worktree at that path on another branch stays.
 */
final readonly class RemoteTaskBridgeWorktreeRemover implements TaskBridgeWorktreeRemover
{
    public function __construct(private AppDevSshExecutor $ssh) {}

    public function remove(AppInstance $instance): void
    {
        if (preg_match('/\Atask-[0-9]+\z/', $instance->name) !== 1) {
            return;
        }

        $instance->loadMissing(['app', 'node']);
        $checkout = StoragePath::tryParse($instance->checkout_path);

        if (! $checkout instanceof StoragePath) {
            throw new RuntimeConvergenceException(
                step: 'task-bridge-removal',
                errorCode: 'tasks.bridge_removal_failed',
                message: 'The task workspace checkout path is invalid.',
            );
        }

        $branch = $instance->branch_override;
        if (! is_string($branch) || $branch === '') {
            $branch = $instance->branch;
        }
        if (! is_string($branch) || $branch === '') {
            $branch = $instance->name;
        }
        if (! GitBranchName::isValid($branch)) {
            throw new RuntimeConvergenceException(
                step: 'task-bridge-removal',
                errorCode: 'tasks.bridge_removal_failed',
                message: 'The task workspace branch is not a valid Git branch name.',
            );
        }

        $repository = $instance->app->repository_url;
        $origin = GitRepositoryOrigin::isValid($repository) ? $repository : '';

        try {
            $this->ssh->execute(
                $instance->node,
                new RemoteCommand(
                    arguments: ['bash', '-seu', '--', $instance->name, $checkout->value, $origin, $branch],
                    input: self::SCRIPT,
                ),
                'task-bridge-removal',
                'tasks.bridge_removal_failed',
                failureLabel: 'Task bridge removal',
            );
        } catch (RuntimeConvergenceException $exception) {
            $detail = $exception->result instanceof CommandResult ? trim($exception->result->stderr) : '';

            if ($detail === '') {
                throw $exception;
            }

            throw new RuntimeConvergenceException(
                step: $exception->step,
                errorCode: $exception->errorCode,
                message: $detail,
                previous: $exception,
                result: $exception->result,
            );
        }
    }

    private const string SCRIPT = <<<'BASH'
#!/usr/bin/env bash
# Remove one task group's ADR 0135 bridge from the registered primary checkout.
# Arguments: instance name, checkout path, fallback origin URL, clone branch.
set -euo pipefail

instance_name=$1
checkout=$2
fallback_origin=$3
clone_branch=$4

if [[ ! $instance_name =~ ^task-[0-9]+$ ]]; then
    exit 0
fi

origin_key() {
    php -r '
        $url = $argv[1] ?? "";
        $pattern = "#\\A(?:[a-z][a-z0-9+.-]*://)?(?:[^@/]+@)?([^:/]+)[:/](.+?)(?:\\.git)?/*\\z#i";
        if ($url === "") {
            exit(0);
        }
        if (preg_match($pattern, $url, $matches) === 1) {
            echo hash("sha256", strtolower($matches[1])."/".$matches[2]);
            exit(0);
        }
        echo hash("sha256", $url);
    ' "$1"
}

origin=$fallback_origin
if [[ -e $checkout ]] && git -C "$checkout" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    toplevel=$(git -C "$checkout" rev-parse --show-toplevel)
    if [[ $(basename "$toplevel") != "$instance_name" ]]; then
        exit 0
    fi
    detected=$(git -C "$checkout" remote get-url origin 2>/dev/null || true)
    if [[ -n $detected ]]; then
        origin=$detected
    fi
    live_branch=$(git -C "$checkout" symbolic-ref --quiet --short HEAD || true)
    if [[ -n $live_branch ]]; then
        clone_branch=$live_branch
    fi
fi

if [[ -z $origin || -z $clone_branch ]]; then
    exit 0
fi

expected_branch=$clone_branch-e2e
key=$(origin_key "$origin")
if [[ ! $key =~ ^[0-9a-f]{64}$ ]]; then
    exit 0
fi

state=${XDG_STATE_HOME:-$HOME/.local/state}
link=$state/orbit/e2e-primary-checkouts/$key
if [[ ! -L $link ]]; then
    exit 0
fi
primary=$(readlink -f "$link" || true)
if [[ ! -d $primary ]]; then
    exit 0
fi
if [[ $(stat -c %u "$primary") != $(id -u) ]]; then
    exit 0
fi
if [[ ! -f $primary/.e2e/topology-snapshot/promoted.json ]]; then
    exit 0
fi

git_dir=$(git -C "$primary" rev-parse --path-format=absolute --git-dir)
common=$(git -C "$primary" rev-parse --path-format=absolute --git-common-dir)
if [[ $git_dir != "$common" ]]; then
    exit 0
fi

primary_origin=$(git -C "$primary" remote get-url origin 2>/dev/null || true)
primary_key=$(origin_key "$primary_origin")
if [[ $primary_key != "$key" ]]; then
    exit 0
fi

root=$(git -C "$primary" config --path --get orbit.worktreeRoot || true)
if [[ -z $root ]]; then
    root=/fast/worktrees/orbit
fi
if [[ -d $root ]]; then
    root=$(readlink -f "$root")
fi
bridge=$root/$instance_name-e2e
bridge_resolved=$bridge
if [[ -d $bridge ]]; then
    bridge_resolved=$(readlink -f "$bridge")
fi

listing=$(git -C "$primary" worktree list --porcelain)
registered_path=
registered_branch=
path=
branch_line=
flush_record() {
    if [[ -n $path ]]; then
        resolved=$path
        if [[ -e $path ]]; then
            resolved=$(readlink -f "$path")
        fi
        if [[ $path == "$bridge" || $resolved == "$bridge_resolved" ]]; then
            registered_path=$path
            registered_branch=$branch_line
        fi
    fi
    path=
    branch_line=
}
while IFS= read -r line; do
    if [[ -z $line ]]; then
        flush_record
    elif [[ $line == worktree\ * ]]; then
        path=${line#worktree }
    elif [[ $line == branch\ * ]]; then
        branch_line=${line#branch }
    fi
done <<< "$listing"
flush_record

removed=0
if [[ -n $registered_path && $registered_branch == "refs/heads/$expected_branch" ]]; then
    git -C "$primary" worktree remove --force "$registered_path"
    removed=1
fi

expected_ref=refs/heads/$expected_branch
staging=refs/orbit/e2e-bridge/$instance_name
listing=$(git -C "$primary" worktree list --porcelain)
checked_out=0
if printf '%s\n' "$listing" | grep -Fxq "branch $expected_ref"; then
    checked_out=1
fi
if [[ $checked_out == 0 ]]; then
    staging_exists=0
    if git -C "$primary" show-ref --verify --quiet "$staging"; then
        staging_exists=1
    fi
    if [[ $removed == 1 || $staging_exists == 1 ]]; then
        if git -C "$primary" show-ref --verify --quiet "$expected_ref"; then
            git -C "$primary" branch -D "$expected_branch"
        fi
    fi
    if [[ $staging_exists == 1 ]]; then
        git -C "$primary" update-ref -d "$staging"
    fi
fi
BASH;
}
