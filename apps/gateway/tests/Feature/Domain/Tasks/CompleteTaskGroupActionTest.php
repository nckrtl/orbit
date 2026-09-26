<?php

declare(strict_types=1);

use App\Actions\Tasks\CancelTaskGroupAction;
use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Domain\AppInstances\AppInstanceRemover;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;
use App\Models\Node;
use App\Models\TaskGroup;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\LocalShellSshExecutor;

function complete_group(TaskGroupStatus $status = TaskGroupStatus::Settling): TaskGroup
{
    $app = OrbitApp::query()->create([
        'name' => 'complete-app',
        'slug' => 'complete-app',
        'repository_url' => 'git@github.com:nckrtl/orbit.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'complete-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.150',
        'wireguard_ip' => '10.44.0.150',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'task-20',
        'checkout_path' => '/srv/orbit/apps/complete-app/task-20',
        'status' => 'source_resolved',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Complete me',
        'brief' => 'Remove the workspace after merge.',
        'status' => $status,
        'pr_url' => 'https://github.com/nckrtl/orbit/pull/543',
    ]);
    $group->taskable()->associate($instance);
    $group->save();

    return $group->fresh(['app', 'taskable']) ?? $group;
}

it('removes the shared App instance and marks a settling group completed', function (): void {
    app(TaskExtensionState::class)->enable();
    $group = complete_group();
    $instanceId = $group->taskable_id;
    $remover = new class implements AppInstanceRemover
    {
        /** @var list<array{0: int, 1: bool}> */
        public array $calls = [];

        public function execute(AppInstance $instance, bool $force): AppInstanceRemoval
        {
            $this->calls[] = [$instance->id, $force];
            $instance->delete();

            return new AppInstanceRemoval;
        }
    };
    app()->instance(AppInstanceRemover::class, $remover);

    $completed = app(CompleteTaskGroupAction::class)->execute($group);

    expect($completed->status)->toBe(TaskGroupStatus::Completed)
        ->and($completed->taskable_id)->toBeNull()
        ->and($completed->settled_at)->not->toBeNull()
        ->and($remover->calls)->toBe([[$instanceId, true]])
        ->and(AppInstance::query()->find($instanceId))->toBeNull();
});

it('is idempotent for an already completed group and retries a leftover workspace', function (): void {
    app(TaskExtensionState::class)->enable();
    $group = complete_group(TaskGroupStatus::Completed);
    $instanceId = $group->taskable_id;
    $remover = new class implements AppInstanceRemover
    {
        /** @var list<array{0: int, 1: bool}> */
        public array $calls = [];

        public function execute(AppInstance $instance, bool $force): AppInstanceRemoval
        {
            $this->calls[] = [$instance->id, $force];
            $instance->delete();

            return new AppInstanceRemoval;
        }
    };
    app()->instance(AppInstanceRemover::class, $remover);

    $completed = app(CompleteTaskGroupAction::class)->execute($group);
    $again = app(CompleteTaskGroupAction::class)->execute($completed);

    expect($completed->status)->toBe(TaskGroupStatus::Completed)
        ->and($again->status)->toBe(TaskGroupStatus::Completed)
        ->and($remover->calls)->toBe([[$instanceId, true]])
        ->and($again->taskable_id)->toBeNull()
        ->and(AppInstance::query()->find($instanceId))->toBeNull();
});

it('marks the group completed and reports the removal failure when the workspace cannot be removed', function (): void {
    app(TaskExtensionState::class)->enable();
    $remover = new class implements AppInstanceRemover
    {
        public bool $fail = true;

        public function execute(AppInstance $instance, bool $force): AppInstanceRemoval
        {
            if ($this->fail) {
                throw new ResourceOperationException('instance.force_failed', 'The Node is unreachable.', 409);
            }
            $instance->delete();

            return new AppInstanceRemoval;
        }
    };
    app()->instance(AppInstanceRemover::class, $remover);
    $group = complete_group();
    $instanceId = $group->taskable_id;

    $completed = app(CompleteTaskGroupAction::class)->execute($group);

    expect($completed->status)->toBe(TaskGroupStatus::Completed)
        ->and($completed->taskable_id)->toBe($instanceId)
        ->and($completed->assistance_requested)->toBeTrue()
        ->and($completed->assistance_reason)->toBe('Workspace removal failed: The Node is unreachable.')
        ->and(AppInstance::query()->find($instanceId))->not->toBeNull();

    $remover->fail = false;
    $retried = app(CompleteTaskGroupAction::class)->execute($completed);

    expect($retried->status)->toBe(TaskGroupStatus::Completed)
        ->and($retried->taskable_id)->toBeNull()
        ->and($retried->assistance_requested)->toBeFalse()
        ->and(AppInstance::query()->find($instanceId))->toBeNull();
});

it('returns 409 tasks.not_settling when the group is still running', function (): void {
    app(TaskExtensionState::class)->enable();
    $group = complete_group(TaskGroupStatus::Running);

    expect(fn () => app(CompleteTaskGroupAction::class)->execute($group))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('tasks.not_settling')
                ->and($exception->status)->toBe(409);
        });
});

it('returns 409 tasks.disabled while the extension is off', function (): void {
    $group = complete_group();

    expect(fn () => app(CompleteTaskGroupAction::class)->execute($group))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('tasks.disabled')
                ->and($exception->status)->toBe(409);
        });
});

it('removes the bridge worktree with the workspace clone when a group is completed', function (): void {
    $world = bridge_workspace(TaskGroupStatus::Settling, 'live');

    try {
        bridge_bind();
        $completed = app(CompleteTaskGroupAction::class)->execute($world['group']);

        expect($completed->status)->toBe(TaskGroupStatus::Completed)
            ->and($completed->assistance_requested)->toBeFalse()
            ->and($completed->assistance_reason)->toBeNull()
            ->and($completed->taskable_id)->toBeNull()
            ->and(is_dir($world['checkout']))->toBeFalse()
            ->and(is_dir($world['bridge']))->toBeFalse()
            ->and(bridge_lists_worktree($world['primary'], $world['bridge']))->toBeFalse()
            ->and(bridge_has_ref($world['primary'], 'refs/heads/'.$world['name'].'-e2e'))->toBeFalse()
            ->and(bridge_has_ref($world['primary'], 'refs/orbit/e2e-bridge/'.$world['name']))->toBeFalse()
            ->and(is_dir($world['userWorktree']))->toBeTrue()
            ->and(bridge_lists_worktree($world['primary'], $world['userWorktree']))->toBeTrue();
    } finally {
        ($world['restore'])();
    }
});

it('removes the bridge worktree with the workspace clone when a group is cancelled', function (): void {
    $world = bridge_workspace(TaskGroupStatus::Running, 'live');

    try {
        bridge_bind();
        $cancelled = app(CancelTaskGroupAction::class)->execute($world['group']);

        expect($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
            ->and($cancelled->assistance_requested)->toBeFalse()
            ->and($cancelled->taskable_id)->toBeNull()
            ->and(is_dir($world['checkout']))->toBeFalse()
            ->and(is_dir($world['bridge']))->toBeFalse()
            ->and(bridge_has_ref($world['primary'], 'refs/heads/'.$world['name'].'-e2e'))->toBeFalse()
            ->and(bridge_has_ref($world['primary'], 'refs/orbit/e2e-bridge/'.$world['name']))->toBeFalse()
            ->and(is_dir($world['userWorktree']))->toBeTrue();
    } finally {
        ($world['restore'])();
    }
});

it('completes a group when no bridge worktree is registered', function (): void {
    $world = bridge_workspace(TaskGroupStatus::Settling, 'missing');

    try {
        bridge_bind();
        $completed = app(CompleteTaskGroupAction::class)->execute($world['group']);

        expect($completed->status)->toBe(TaskGroupStatus::Completed)
            ->and($completed->assistance_requested)->toBeFalse()
            ->and($completed->assistance_reason)->toBeNull()
            ->and(is_dir($world['checkout']))->toBeFalse()
            ->and(is_dir($world['bridge']))->toBeFalse();
    } finally {
        ($world['restore'])();
    }
});

it('keeps a user worktree at the bridge path when the branch is not the group bridge', function (): void {
    $world = bridge_workspace(TaskGroupStatus::Settling, 'foreign');

    try {
        bridge_bind();
        $completed = app(CompleteTaskGroupAction::class)->execute($world['group']);

        expect($completed->assistance_requested)->toBeFalse()
            ->and($completed->assistance_reason)->toBeNull()
            ->and(is_dir($world['bridge']))->toBeTrue()
            ->and(bridge_lists_worktree($world['primary'], $world['bridge']))->toBeTrue()
            ->and(trim(bridge_git(['-C', $world['bridge'], 'symbolic-ref', '--short', 'HEAD'])))->toBe('orb-user')
            ->and(bridge_has_ref($world['primary'], 'refs/heads/'.$world['name'].'-e2e'))->toBeFalse()
            ->and(bridge_has_ref($world['primary'], 'refs/orbit/e2e-bridge/'.$world['name']))->toBeFalse();
    } finally {
        ($world['restore'])();
    }
});

it('prunes a bridge branch and ref left after the bridge directory is gone', function (): void {
    $world = bridge_workspace(TaskGroupStatus::Settling, 'stale');

    try {
        bridge_bind();
        $completed = app(CompleteTaskGroupAction::class)->execute($world['group']);

        expect($completed->status)->toBe(TaskGroupStatus::Completed)
            ->and($completed->assistance_requested)->toBeFalse()
            ->and($completed->assistance_reason)->toBeNull()
            ->and(is_dir($world['checkout']))->toBeFalse()
            ->and(bridge_has_ref($world['primary'], 'refs/heads/'.$world['name'].'-e2e'))->toBeFalse()
            ->and(bridge_has_ref($world['primary'], 'refs/orbit/e2e-bridge/'.$world['name']))->toBeFalse();
    } finally {
        ($world['restore'])();
    }
});

/**
 * A primary checkout, a task clone, and the bridge shape named by $kind.
 *
 * @return array{
 *     group: TaskGroup,
 *     primary: string,
 *     checkout: string,
 *     bridge: string,
 *     userWorktree: string,
 *     name: string,
 *     restore: Closure(): void,
 * }
 */
function bridge_workspace(TaskGroupStatus $status, string $kind): array
{
    $origin = 'ssh://git@example.test/acme/orbit.git';
    $sandbox = sys_get_temp_dir().'/orbit-task-bridge-'.bin2hex(random_bytes(4));
    $previousState = getenv('XDG_STATE_HOME');
    $previousEnv = $_ENV['XDG_STATE_HOME'] ?? null;
    $previousServer = $_SERVER['XDG_STATE_HOME'] ?? null;
    $files = new Filesystem;
    $restore = function () use ($sandbox, $files, $previousState, $previousEnv, $previousServer): void {
        if ($previousState === false) {
            putenv('XDG_STATE_HOME');
        } else {
            putenv('XDG_STATE_HOME='.$previousState);
        }
        if (is_string($previousEnv)) {
            $_ENV['XDG_STATE_HOME'] = $previousEnv;
        } else {
            unset($_ENV['XDG_STATE_HOME']);
        }
        if (is_string($previousServer)) {
            $_SERVER['XDG_STATE_HOME'] = $previousServer;
        } else {
            unset($_SERVER['XDG_STATE_HOME']);
        }
        $files->deleteDirectory($sandbox);
    };

    try {
        $state = $sandbox.'/state';
        $primary = $sandbox.'/primary';
        $worktrees = $sandbox.'/worktrees';
        $files->ensureDirectoryExists($worktrees);
        $files->ensureDirectoryExists($state.'/orbit/e2e-primary-checkouts');
        bridge_git(['init', '-q', '-b', 'main', $primary]);
        bridge_git(['-C', $primary, 'config', 'user.email', 'bridge@example.test']);
        bridge_git(['-C', $primary, 'config', 'user.name', 'Bridge']);
        $files->put($primary.'/README', "primary\n");
        bridge_git(['-C', $primary, 'add', 'README']);
        bridge_git(['-C', $primary, 'commit', '-q', '-m', 'init']);
        bridge_git(['-C', $primary, 'remote', 'add', 'origin', $origin]);
        bridge_git(['-C', $primary, 'config', 'orbit.worktreeRoot', $worktrees]);
        $files->ensureDirectoryExists($primary.'/.e2e/topology-snapshot');
        $files->put($primary.'/.e2e/topology-snapshot/promoted.json', "{}\n");
        $key = bridge_origin_key($origin);
        if (! symlink($primary, $state.'/orbit/e2e-primary-checkouts/'.$key)) {
            throw new RuntimeException('The bridge fixture could not register the primary checkout.');
        }

        $group = complete_group($status);
        $name = 'task-'.$group->id;
        $checkout = $sandbox.'/checkouts/'.$name;
        $bridge = $worktrees.'/'.$name.'-e2e';
        $userWorktree = $worktrees.'/orb-1';
        $files->ensureDirectoryExists($sandbox.'/checkouts');
        bridge_git(['init', '-q', '-b', $name, $checkout]);
        bridge_git(['-C', $checkout, 'config', 'user.email', 'bridge@example.test']);
        bridge_git(['-C', $checkout, 'config', 'user.name', 'Bridge']);
        $files->put($checkout.'/C', "clone\n");
        bridge_git(['-C', $checkout, 'add', 'C']);
        bridge_git(['-C', $checkout, 'commit', '-q', '-m', 'clone']);
        bridge_git(['-C', $checkout, 'remote', 'add', 'origin', $origin]);

        if ($kind === 'live') {
            bridge_git(['-C', $primary, 'worktree', 'add', '-q', '-b', $name.'-e2e', $bridge]);
            $files->put($bridge.'/README', "changed\n");
            $files->put($bridge.'/dirt.txt', "dirt\n");
            $files->ensureDirectoryExists($bridge.'/.e2e');
            $files->put($bridge.'/.e2e/attempt.json', "{}\n");
            bridge_git(['-C', $primary, 'update-ref', 'refs/orbit/e2e-bridge/'.$name, 'HEAD']);
            bridge_git(['-C', $primary, 'branch', 'orb-1']);
            bridge_git(['-C', $primary, 'worktree', 'add', '-q', $userWorktree, 'orb-1']);
        } elseif ($kind === 'foreign') {
            bridge_git(['-C', $primary, 'branch', 'orb-user']);
            bridge_git(['-C', $primary, 'worktree', 'add', '-q', $bridge, 'orb-user']);
            bridge_git(['-C', $primary, 'branch', $name.'-e2e']);
            bridge_git(['-C', $primary, 'update-ref', 'refs/orbit/e2e-bridge/'.$name, 'HEAD']);
        } elseif ($kind === 'stale') {
            bridge_git(['-C', $primary, 'branch', $name.'-e2e']);
            bridge_git(['-C', $primary, 'update-ref', 'refs/orbit/e2e-bridge/'.$name, 'HEAD']);
        }

        $instance = $group->taskable;
        if (! $instance instanceof AppInstance) {
            throw new RuntimeException('The bridge fixture has no workspace.');
        }
        $instance->update([
            'name' => $name,
            'branch_override' => $name,
            'branch' => $name,
            'checkout_path' => $checkout,
        ]);
        $group->app?->update(['repository_url' => $origin]);
        $group = $group->fresh(['app', 'taskable']) ?? $group;

        putenv('XDG_STATE_HOME='.$state);
        $_ENV['XDG_STATE_HOME'] = $state;
        $_SERVER['XDG_STATE_HOME'] = $state;

        return [
            'group' => $group,
            'primary' => $primary,
            'checkout' => $checkout,
            'bridge' => $bridge,
            'userWorktree' => $userWorktree,
            'name' => $name,
            'restore' => $restore,
        ];
    } catch (Throwable $exception) {
        $restore();

        throw $exception;
    }
}

function bridge_bind(): void
{
    app(TaskExtensionState::class)->enable();
    bind_task_node_reachability();
    config(['orbit.tasks.remove_bridge_worktree' => true]);
    app()->instance(SshExecutor::class, new LocalShellSshExecutor);
    app()->instance(AppInstanceRemover::class, new class implements AppInstanceRemover
    {
        public function execute(AppInstance $instance, bool $force): AppInstanceRemoval
        {
            (new Filesystem)->deleteDirectory($instance->checkout_path);
            $instance->delete();

            return new AppInstanceRemoval;
        }
    });
}

function bridge_origin_key(string $url): string
{
    $pattern = '#\A(?:[a-z][a-z0-9+.-]*://)?(?:[^@/]+@)?([^:/]+)[:/](.+?)(?:\.git)?/*\z#i';
    if (preg_match($pattern, $url, $matches) === 1) {
        return hash('sha256', strtolower($matches[1]).'/'.$matches[2]);
    }

    return hash('sha256', $url);
}

/** @param list<string> $arguments */
function bridge_git(array $arguments): string
{
    $process = new Process(['git', ...$arguments]);
    $process->mustRun();

    return $process->getOutput();
}

function bridge_has_ref(string $primary, string $ref): bool
{
    $process = new Process(['git', '-C', $primary, 'show-ref', '--verify', '--quiet', $ref]);
    $process->run();

    return $process->isSuccessful();
}

function bridge_lists_worktree(string $primary, string $path): bool
{
    return str_contains(bridge_git(['-C', $primary, 'worktree', 'list', '--porcelain']), "worktree {$path}\n");
}
