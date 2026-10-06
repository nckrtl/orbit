<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\GitHubTaskBaseBranchFetcher;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\TaskWorkerSshExecutor;
use Tests\Support\TestOrbitHome;

/** @param  list<string>  $arguments */
function fetcher_git(string $directory, array $arguments): string
{
    return trim((new Process(['git', '-C', $directory, '-c', 'user.name=t', '-c', 'user.email=t@t', ...$arguments]))->mustRun()->getOutput());
}

function fetcher_group(string $checkout): Task
{
    $project = Project::query()->create([
        'name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'git@github.com:acme/shop.git', 'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'fetch-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.150', 'wireguard_ip' => '10.44.0.150', 'user' => 'orbit',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-7', 'checkout_path' => $checkout,
        'branch' => 'task-7', 'status' => 'source_resolved',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id, 'title' => 'Export orders', 'brief' => 'Add an export.', 'status' => 'settling',
    ]);
    $group->taskable()->associate($instance);
    $group->save();

    return $group->fresh(['project', 'taskable']) ?? $group;
}

function fetcher(SshExecutor $transport): GitHubTaskBaseBranchFetcher
{
    app()->instance(DevelopmentSshExecutor::class, new DevelopmentSshExecutor(
        $transport,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/home/orbit/.orbit/ssh/id_ed25519';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 AAAA';
            }
        },
        new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/home/orbit/.orbit/ssh/known_hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    ));

    return app(GitHubTaskBaseBranchFetcher::class);
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    GitHubTestSupport::storeApp();
    Http::fake([
        'https://api.github.com/repos/acme/shop/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => static fn (Request $request) => ($request->data()['permissions'] ?? []) === ['contents' => 'read']
            ? Http::response(['token' => 'ghs_read'], 201)
            : Http::response(['token' => 'ghs_fetch'], 201),
    ]);
});

afterEach(function (): void {
    TestOrbitHome::clearScratch();
});

it('preserves staged unstaged and untracked candidate work in one pinned fast-forward and reconciles its lost reply', function (): void {
    config()->set('orbit.tasks.worker_user', 'nobody');
    $root = sys_get_temp_dir().'/orbit-handoff-advance-'.bin2hex(random_bytes(6));
    $checkout = $root.'/checkout';
    (new Process(['git', 'init', '-q', '-b', 'task-7', $checkout]))->mustRun();
    $group = fetcher_group($checkout);
    file_put_contents($checkout.'/lifecycle', 'base');
    file_put_contents($checkout.'/staged', 'base');
    fetcher_git($checkout, ['add', '.']);
    fetcher_git($checkout, ['commit', '-qm', 'red base']);
    $old = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    file_put_contents($checkout.'/upstream', 'fixed');
    fetcher_git($checkout, ['add', '.']);
    fetcher_git($checkout, ['commit', '-qm', 'green descendant']);
    $target = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    fetcher_git($checkout, ['update-ref', 'refs/remotes/origin/main', $target]);
    fetcher_git($checkout, ['reset', '--hard', $old]);
    file_put_contents($checkout.'/staged', 'staged candidate');
    fetcher_git($checkout, ['add', 'staged']);
    file_put_contents($checkout.'/lifecycle', 'unstaged candidate');
    file_put_contents($checkout.'/new-file', 'untracked candidate');
    $index = $root.'/snapshot-index';
    copy($checkout.'/.git/index', $index);
    (new Process(['git', '-C', $checkout, 'add', '--all'], env: ['GIT_INDEX_FILE' => $index]))->mustRun();
    $tree = trim((new Process(['git', '-C', $checkout, 'write-tree'], env: ['GIT_INDEX_FILE' => $index]))->mustRun()->getOutput());
    $originalIndex = fetcher_git($checkout, ['write-tree']);
    $transport = TaskWorkerSshExecutor::forCheckout($checkout);
    (new Process(['setfacl', '-R', '-m', 'u:nobody:rwX,d:u:nobody:rwX,d:u:'.posix_geteuid().':rwX', $root]))->mustRun();
    try {
        $bases = fetcher($transport);
        $advanced = $bases->advanceCandidate($group, $old, $tree, $target, $originalIndex);
        expect($advanced->head)->toBe($target);
        expect($bases->advanceCandidate($group, $old, $tree, $target, $originalIndex))->toEqual($advanced);
        expect(file_get_contents($checkout.'/lifecycle'))->toBe('unstaged candidate')
            ->and(file_get_contents($checkout.'/new-file'))->toBe('untracked candidate')
            ->and(fetcher_git($checkout, ['diff', '--cached', '--name-only']))->toBe('staged')
            ->and(fetcher_git($checkout, ['diff', '--name-only']))->toBe('lifecycle')
            ->and(fetcher_git($checkout, ['ls-files', '--others', '--exclude-standard']))->toBe('new-file');
    } finally {
        File::deleteDirectory($root);
    }
});

it('refuses pinned advancement on collision divergence candidate movement or ref movement without changing work', function (string $case): void {
    $root = TestOrbitHome::scratch('orbit-handoff-refuse');
    $checkout = $root.'/checkout';
    (new Process(['git', 'init', '-q', '-b', 'task-7', $checkout]))->mustRun();
    file_put_contents($checkout.'/lifecycle', 'base');
    fetcher_git($checkout, ['add', '.']);
    fetcher_git($checkout, ['commit', '-qm', 'base']);
    $old = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    $path = $case === 'tracked collision' ? 'lifecycle' : 'incoming';
    file_put_contents($checkout.'/'.$path, 'upstream');
    fetcher_git($checkout, ['add', '.']);
    fetcher_git($checkout, ['commit', '-qm', 'upstream']);
    $target = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    fetcher_git($checkout, ['update-ref', 'refs/remotes/origin/main', $target]);
    fetcher_git($checkout, ['reset', '--hard', $old]);
    if ($case === 'diverged') {
        file_put_contents($checkout.'/diverged', 'commit');
        fetcher_git($checkout, ['add', '.']);
        fetcher_git($checkout, ['commit', '-qm', 'diverged']);
        $old = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    }
    file_put_contents($checkout.'/'.($case === 'untracked collision' ? 'incoming' : 'lifecycle'), 'candidate');
    $index = $root.'/snapshot-index';
    copy($checkout.'/.git/index', $index);
    (new Process(['git', '-C', $checkout, 'add', '--all'], env: ['GIT_INDEX_FILE' => $index]))->mustRun();
    $tree = trim((new Process(['git', '-C', $checkout, 'write-tree'], env: ['GIT_INDEX_FILE' => $index]))->mustRun()->getOutput());
    if ($case === 'candidate moved') {
        file_put_contents($checkout.'/later', 'later work');
    }
    if ($case === 'ref moved') {
        fetcher_git($checkout, ['update-ref', 'refs/remotes/origin/main', $old]);
    }
    $before = fetcher_git($checkout, ['status', '--porcelain']);
    $originalIndex = fetcher_git($checkout, ['write-tree']);
    $indexHash = hash_file('sha256', $checkout.'/.git/index');
    $process = new Process(['python3', resource_path('tasks/advance-candidate'), $checkout, $old, $tree, $target, 'task-7', 'main', $originalIndex]);
    $process->run();
    expect($process->isSuccessful())->toBeFalse()
        ->and(fetcher_git($checkout, ['rev-parse', 'HEAD']))->toBe($old)
        ->and(fetcher_git($checkout, ['status', '--porcelain']))->toBe($before)
        ->and(hash_file('sha256', $checkout.'/.git/index'))->toBe($indexHash);
})->with(['tracked collision', 'untracked collision', 'diverged', 'candidate moved', 'ref moved']);

it('refuses a baseline reset when the fetched ref differs from the positively checked SHA', function (): void {
    $root = TestOrbitHome::scratch('orbit-baseline-pinned');
    $checkout = $root.'/checkout';
    (new Process(['git', 'init', '-q', '-b', 'task-7', $checkout]))->mustRun();
    fetcher_git($checkout, ['commit', '--allow-empty', '-qm', 'base']);
    $old = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    fetcher_git($checkout, ['update-ref', 'refs/remotes/origin/main', $old]);
    file_put_contents($checkout.'/preserve', 'do not reset');
    $group = fetcher_group($checkout);
    expect(fn () => fetcher(new LocalShellSshExecutor)->resetToDefault($group, str_repeat('f', 40)))->toThrow(TaskPullRequestException::class);
    expect(fetcher_git($checkout, ['rev-parse', 'HEAD']))->toBe($old)->and(file_get_contents($checkout.'/preserve'))->toBe('do not reset');
});

describe('TaskCheckWorkerUser', function (): void {
    it('updates the checkout with worker filter UIDs and without the fetch credential environment', function (string $operation): void {
        config()->set('orbit.tasks.worker_user', 'nobody');
        $root = sys_get_temp_dir().'/orbit-worker-fast-forward-'.bin2hex(random_bytes(6));
        $checkout = $root.'/checkout';
        (new Process(['git', 'init', '-q', '--bare', $root.'/origin.git']))->mustRun();
        (new Process(['git', 'init', '-q', '-b', 'task-7', $checkout]))->mustRun();
        $group = fetcher_group($checkout);
        $branch = 'task-'.$group->id;
        file_put_contents($checkout.'/.gitattributes', "readme filter=uid\n");
        file_put_contents($checkout.'/readme', 'approved');
        fetcher_git($checkout, ['add', '.']);
        fetcher_git($checkout, ['commit', '-qm', 'approved']);
        $approved = fetcher_git($checkout, ['rev-parse', 'HEAD']);
        file_put_contents($checkout.'/readme', 'upstream');
        fetcher_git($checkout, ['commit', '-qam', 'upstream']);
        $upstream = fetcher_git($checkout, ['rev-parse', 'HEAD']);
        fetcher_git($checkout, ['remote', 'add', 'origin', $root.'/origin.git']);
        fetcher_git($checkout, ['push', '-q', 'origin', 'HEAD:refs/heads/'.$branch, 'HEAD:refs/heads/main']);
        fetcher_git($checkout, ['reset', '--hard', '-q', $approved]);
        foreach (['clean', 'smudge'] as $filter) {
            fetcher_git($checkout, ['config', 'filter.uid.'.$filter, 'printf "%s:%s\\n" "$(id -u)" "${GIT_CONFIG_VALUE_0-absent}" >> .git/filter-users; cat']);
        }
        $transport = TaskWorkerSshExecutor::forCheckout($checkout);
        (new Process(['setfacl', '-R', '-m', 'u:nobody:rwX,d:u:nobody:rwX,d:u:'.posix_geteuid().':rwX', $root]))->mustRun();

        try {
            $bases = fetcher($transport);
            $bases->fetchForTurn($group);
            fetcher_git($checkout, ['remote', 'set-url', 'origin', $root.'/unreachable.git']);
            if ($operation === 'fast-forward') {
                $bases->fastForward($group);
            } else {
                expect($bases->resetToDefault($group))->toBe($upstream);
            }

            expect(fetcher_git($checkout, ['rev-parse', 'HEAD']))->toBe($upstream);
            $users = file($checkout.'/.git/filter-users', FILE_IGNORE_NEW_LINES) ?: [];
            expect($users)->not->toBeEmpty();
            foreach ($users as $user) {
                expect($user)->toBe('65534:absent');
            }
        } finally {
            File::deleteDirectory($root);
        }
    })->with(['fast-forward', 'baseline-reset']);
});

it('fetches the base ref into the remote-tracking ref and leaves the task branch', function (): void {
    $root = TestOrbitHome::scratch('orbit-fetch');
    (new Process(['git', 'init', '--quiet', '--bare', $root.'/origin.git']))->mustRun();
    (new Process(['git', 'init', '--quiet', '-b', 'task-7', $root.'/checkout']))->mustRun();
    fetcher_git($root.'/checkout', ['commit', '--quiet', '--allow-empty', '-m', 'Local']);
    $local = fetcher_git($root.'/checkout', ['rev-parse', 'HEAD']);
    fetcher_git($root.'/checkout', ['commit', '--quiet', '--allow-empty', '-m', 'Base']);
    fetcher_git($root.'/checkout', ['remote', 'add', 'origin', $root.'/origin.git']);
    fetcher_git($root.'/checkout', ['push', '--quiet', 'origin', 'HEAD:refs/heads/main']);
    $base = fetcher_git($root.'/checkout', ['rev-parse', 'HEAD']);
    fetcher_git($root.'/checkout', ['reset', '--quiet', '--hard', $local]);
    fetcher_git($root.'/checkout', ['update-ref', '-d', 'refs/remotes/origin/main']);

    fetcher(new LocalShellSshExecutor)->fetch(fetcher_group($root.'/checkout'), 'main');

    expect(fetcher_git($root.'/checkout', ['rev-parse', 'HEAD']))->toBe($local)
        ->and(fetcher_git($root.'/checkout', ['rev-parse', '--abbrev-ref', 'HEAD']))->toBe('task-7')
        ->and(fetcher_git($root.'/checkout', ['rev-parse', 'refs/remotes/origin/main']))->toBe($base);
});

it('passes the base ref as one argument and the token only on standard input', function (): void {
    $transport = new AppDevFakeSshExecutor;
    $group = fetcher_group('/srv/orbit/apps/shop/task-7');

    fetcher($transport)->fetch($group, 'feature/main');

    $command = $transport->commands[0];
    expect($command->arguments)->toBe(['bash', '-seu', '--', '/srv/orbit/apps/shop/task-7', 'feature/main'])
        ->and($command->input)->toBeNull()
        ->and(stream_get_contents($command->protectedInput?->stream()))->toContain(base64_encode('x-access-token:ghs_fetch'))
        ->and(stream_get_contents($command->protectedInput?->stream()))->toContain('git_read git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" fetch --quiet origin "$base"');
});

it('reports one failure when the base name is invalid or the fetch fails', function (string $base, bool $ssh): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult(128, '', 'fatal: not found', 1, false)]);

    expect(fn () => fetcher($transport)->fetch(fetcher_group('/srv/orbit/apps/shop/task-7'), $base))
        ->toThrow(TaskPullRequestException::class, 'The base branch could not be fetched.');
    expect($transport->commands)->toHaveCount($ssh ? 1 : 0);
})->with([
    'invalid name' => ['HEAD', false],
    'failed fetch' => ['main', true],
]);

it('fast-forwards a workspace that is strictly behind the task branch and leaves a level, ahead, or diverged one', function (string $case): void {
    $root = TestOrbitHome::scratch('orbit-fast-forward');
    $checkout = $root.'/checkout';
    (new Process(['git', 'init', '--quiet', '--bare', $root.'/origin.git']))->mustRun();
    (new Process(['git', 'init', '--quiet', '-b', 'task-7', $checkout]))->mustRun();
    $group = fetcher_group($checkout);
    $branch = 'task-'.$group->id;
    fetcher_git($checkout, ['remote', 'add', 'origin', $root.'/origin.git']);
    fetcher_git($checkout, ['commit', '--quiet', '--allow-empty', '-m', 'Approved']);
    $approved = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    fetcher_git($checkout, ['push', '--quiet', 'origin', 'HEAD:refs/heads/'.$branch]);
    $remote = $approved;
    if ($case === 'behind' || $case === 'diverged' || $case === 'behind-missing-ok') {
        fetcher_git($checkout, ['commit', '--quiet', '--allow-empty', '-m', 'Merge main']);
        $remote = fetcher_git($checkout, ['rev-parse', 'HEAD']);
        fetcher_git($checkout, ['push', '--quiet', 'origin', 'HEAD:refs/heads/'.$branch]);
        fetcher_git($checkout, ['reset', '--quiet', '--hard', $approved]);
        fetcher_git($checkout, ['update-ref', '-d', 'refs/remotes/origin/'.$branch]);
    }
    if ($case === 'ahead' || $case === 'diverged') {
        fetcher_git($checkout, ['commit', '--quiet', '--allow-empty', '-m', 'Local']);
    }
    $before = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    fetcher_git($checkout, ['push', '--quiet', 'origin', $approved.':refs/heads/main']);
    $fetcher = fetcher(new LocalShellSshExecutor);
    $fetcher->fetchForTurn($group);
    // Preparation must use the fetched refs, even if the remote is no longer reachable.
    fetcher_git($checkout, ['remote', 'set-url', 'origin', $root.'/unreachable.git']);

    $fetcher->fastForward($group, missingRefOk: $case === 'behind-missing-ok');

    expect(fetcher_git($checkout, ['rev-parse', 'HEAD']))->toBe(in_array($case, ['behind', 'behind-missing-ok'], true) ? $remote : $before)
        ->and(fetcher_git($checkout, ['rev-parse', 'refs/remotes/origin/'.$branch]))->toBe($remote)
        ->and(fetcher_git($checkout, ['rev-parse', '--abbrev-ref', 'HEAD']))->toBe('task-7');
})->with(['behind', 'behind-missing-ok', 'level', 'ahead', 'diverged']);

it('fast-forwards the already-fetched task branch without a token, another fetch, or forcing the workspace', function (): void {
    $transport = new AppDevFakeSshExecutor;
    $group = fetcher_group('/srv/orbit/apps/shop/task-7');

    fetcher($transport)->fastForward($group);

    $command = $transport->commands[0];
    $script = (string) $command->input;
    expect($command->arguments)->toBe(['bash', '-seu', '--', '/srv/orbit/apps/shop/task-7', 'task-'.$group->id])
        ->and($command->protectedInput)->toBeNull()
        ->and($script)->not->toContain('fetch', 'ls-remote', 'token')
        ->and($script)->toContain('merge --ff-only')
        ->and($script)->not->toContain('reset')
        ->and($script)->not->toContain('push');
    Http::assertNothingSent();
});

it('reports one failure when fast-forwarding the task branch fails', function (): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult(128, '', 'fatal: not found', 1, false)]);

    expect(fn () => fetcher($transport)->fastForward(fetcher_group('/srv/orbit/apps/shop/task-7')))
        ->toThrow(TaskPullRequestException::class, 'The task branch could not be fetched.');
    expect($transport->commands)->toHaveCount(1);
});

it('passes missing-ok when a missing task branch should not fail the resume', function (): void {
    $transport = new AppDevFakeSshExecutor;
    $group = fetcher_group('/srv/orbit/apps/shop/task-7');

    fetcher($transport)->fastForward($group, missingRefOk: true);

    $script = (string) $transport->commands[0]->input;
    expect($transport->commands[0]->arguments)->toBe(['bash', '-seu', '--', '/srv/orbit/apps/shop/task-7', 'task-'.$group->id, 'missing-ok'])
        ->and($script)->toContain('show-ref --verify --quiet "refs/remotes/origin/$branch"')
        ->and($script)->toContain('missing-ok');
});

it('fetches the turn refs with explicit refspecs, --no-tags, and the read token only on standard input', function (): void {
    $transport = new AppDevFakeSshExecutor;
    $group = fetcher_group('/srv/orbit/apps/shop/task-7');

    fetcher($transport)->fetchForTurn($group);

    $command = $transport->commands[0];
    $script = (string) stream_get_contents($command->protectedInput?->stream());
    expect($transport->commands)->toHaveCount(1)
        ->and($command->arguments)->toBe(['bash', '-seu', '--', '/srv/orbit/apps/shop/task-7', 'main', 'task-'.$group->id, ''])
        ->and($command->input)->toBeNull()
        ->and(implode(' ', $command->arguments))->not->toContain('ghs_read')
        ->and($script)->toContain(base64_encode('x-access-token:ghs_read'))
        ->and($script)->not->toContain('ghs_read')
        ->and($script)->toContain('ls-remote --exit-code --heads origin "refs/heads/$task_branch"')
        ->and($script)->toContain('fetch --no-tags --quiet origin')
        ->and($script)->toContain('+refs/heads/${default_branch}:refs/remotes/origin/${default_branch}')
        ->and($script)->toContain('+refs/heads/${task_branch}:refs/remotes/origin/${task_branch}')
        ->and($script)->not->toContain('merge')
        ->and($script)->not->toContain('rebase')
        ->and($script)->not->toContain('reset');
    Http::assertSent(static fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens')
        && $request->data() === ['repositories' => ['shop'], 'permissions' => ['contents' => 'read']]);
    Http::assertNotSent(static fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens')
        && ($request->data()['permissions']['contents'] ?? null) === 'write');
});

it('asks for a different pull request base as its own refspec and keeps the write token off the node', function (): void {
    Http::fake([
        'https://api.github.com/repos/acme/shop/pulls/42' => Http::response([
            'merged' => false, 'state' => 'open', 'base' => ['ref' => 'develop'], 'head' => ['sha' => str_repeat('a', 40)],
        ]),
    ]);
    $transport = new AppDevFakeSshExecutor;
    $group = fetcher_group('/srv/orbit/apps/shop/task-7');
    $group->update(['pr_url' => 'https://github.com/acme/shop/pull/42']);

    fetcher($transport)->fetchForTurn($group->fresh() ?? $group);

    $command = $transport->commands[0];
    $script = (string) stream_get_contents($command->protectedInput?->stream());
    expect($command->arguments)->toBe(['bash', '-seu', '--', '/srv/orbit/apps/shop/task-7', 'main', 'task-'.$group->id, 'develop'])
        ->and($command->input)->toBeNull()
        ->and(implode(' ', $command->arguments))->not->toContain('ghs_')
        ->and($script)->toContain(base64_encode('x-access-token:ghs_read'))
        ->and($script)->not->toContain('ghs_read')
        ->and($script)->not->toContain('ghs_fetch')
        ->and($script)->toContain('+refs/heads/${pull_base}:refs/remotes/origin/${pull_base}')
        ->and($script)->toContain('--no-tags');
});

it('updates remote-tracking refs and does not move HEAD when the task branch or a tag is missing', function (bool $taskBranch, bool $staleRef): void {
    $root = TestOrbitHome::scratch('orbit-turn-fetch');
    $checkout = $root.'/checkout';
    (new Process(['git', 'init', '--quiet', '--bare', $root.'/origin.git']))->mustRun();
    (new Process(['git', 'init', '--quiet', '-b', 'workspace', $checkout]))->mustRun();
    $group = fetcher_group($checkout);
    $branch = 'task-'.$group->id;
    $remoteTask = null;
    fetcher_git($checkout, ['remote', 'add', 'origin', $root.'/origin.git']);
    fetcher_git($checkout, ['commit', '--quiet', '--allow-empty', '-m', 'Local']);
    $local = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    fetcher_git($checkout, ['commit', '--quiet', '--allow-empty', '-m', 'Main']);
    $main = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    fetcher_git($checkout, ['tag', 'demo']);
    fetcher_git($checkout, ['push', '--quiet', 'origin', 'HEAD:refs/heads/main']);
    fetcher_git($checkout, ['push', '--quiet', 'origin', 'demo']);
    if ($taskBranch) {
        fetcher_git($checkout, ['commit', '--quiet', '--allow-empty', '-m', 'Task']);
        $remoteTask = fetcher_git($checkout, ['rev-parse', 'HEAD']);
        fetcher_git($checkout, ['push', '--quiet', 'origin', 'HEAD:refs/heads/'.$branch]);
    }
    fetcher_git($checkout, ['reset', '--quiet', '--hard', $local]);
    fetcher_git($checkout, ['tag', '-d', 'demo']);
    fetcher_git($checkout, ['update-ref', '-d', 'refs/remotes/origin/main']);
    if ($taskBranch) {
        fetcher_git($checkout, ['update-ref', '-d', 'refs/remotes/origin/'.$branch]);
    } elseif ($staleRef) {
        fetcher_git($checkout, ['update-ref', 'refs/remotes/origin/'.$branch, $main]);
    }

    fetcher(new LocalShellSshExecutor)->fetchForTurn($group->fresh() ?? $group);

    $tag = (new Process(['git', '-C', $checkout, 'tag', '--list', 'demo']))->mustRun();
    $missingTask = new Process(['git', '-C', $checkout, 'rev-parse', '--verify', '--quiet', 'refs/remotes/origin/'.$branch]);
    $missingTask->run();
    expect(fetcher_git($checkout, ['rev-parse', 'HEAD']))->toBe($local)
        ->and(fetcher_git($checkout, ['rev-parse', '--abbrev-ref', 'HEAD']))->toBe('workspace')
        ->and(fetcher_git($checkout, ['rev-parse', 'refs/remotes/origin/main']))->toBe($main)
        ->and(trim($tag->getOutput()))->toBe('');
    if ($taskBranch) {
        expect(fetcher_git($checkout, ['rev-parse', 'refs/remotes/origin/'.$branch]))->toBe($remoteTask);
    } else {
        expect($missingTask->getExitCode())->not->toBe(0);
        expect(fn () => fetcher(new LocalShellSshExecutor)->fastForward($group))
            ->toThrow(TaskPullRequestException::class, 'The task branch could not be fetched.');
        fetcher(new LocalShellSshExecutor)->fastForward($group, missingRefOk: true);
        expect(fetcher_git($checkout, ['rev-parse', 'HEAD']))->toBe($local);
    }
})->with([
    'missing task branch' => [false, false],
    'deleted task branch with a stale tracking ref' => [false, true],
    'task branch present' => [true, false],
]);

it('refreshes the default branch when only a nested branch shares the task name', function (): void {
    $root = TestOrbitHome::scratch('orbit-turn-fetch-suffix');
    $checkout = $root.'/checkout';
    (new Process(['git', 'init', '--quiet', '--bare', $root.'/origin.git']))->mustRun();
    (new Process(['git', 'init', '--quiet', '-b', 'workspace', $checkout]))->mustRun();
    $group = fetcher_group($checkout);
    $branch = 'task-'.$group->id;
    fetcher_git($checkout, ['remote', 'add', 'origin', $root.'/origin.git']);
    fetcher_git($checkout, ['commit', '--quiet', '--allow-empty', '-m', 'Local']);
    $local = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    fetcher_git($checkout, ['commit', '--quiet', '--allow-empty', '-m', 'Main']);
    $main = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    fetcher_git($checkout, ['push', '--quiet', 'origin', 'HEAD:refs/heads/main']);
    fetcher_git($checkout, ['push', '--quiet', 'origin', 'HEAD:refs/heads/archive/'.$branch]);
    fetcher_git($checkout, ['reset', '--quiet', '--hard', $local]);
    fetcher_git($checkout, ['update-ref', '-d', 'refs/remotes/origin/main']);

    fetcher(new LocalShellSshExecutor)->fetchForTurn($group->fresh() ?? $group);

    $missingTask = new Process(['git', '-C', $checkout, 'rev-parse', '--verify', '--quiet', 'refs/remotes/origin/'.$branch]);
    $missingTask->run();
    expect(fetcher_git($checkout, ['rev-parse', 'HEAD']))->toBe($local)
        ->and(fetcher_git($checkout, ['rev-parse', '--abbrev-ref', 'HEAD']))->toBe('workspace')
        ->and(fetcher_git($checkout, ['rev-parse', 'refs/remotes/origin/main']))->toBe($main)
        ->and($missingTask->getExitCode())->not->toBe(0);
});

it('does not run a fetch when the default branch name is invalid', function (): void {
    $transport = new AppDevFakeSshExecutor;
    $group = fetcher_group('/srv/orbit/apps/shop/task-7');
    $group->project->update(['default_branch' => 'HEAD']);

    expect(fn () => fetcher($transport)->fetchForTurn($group->fresh() ?? $group))
        ->toThrow(TaskPullRequestException::class, 'The workspace refs could not be fetched.');
    expect($transport->commands)->toHaveCount(0);
});

it('reads the default tip and ancestry without moving HEAD or requiring a write credential', function (): void {
    $checkout = TestOrbitHome::scratch('orbit-baseline-read').'/checkout';
    (new Process(['git', 'init', '--quiet', '-b', 'task-7', $checkout]))->mustRun();
    $group = fetcher_group($checkout);
    fetcher_git($checkout, ['commit', '--quiet', '--allow-empty', '-m', 'Original baseline']);
    $original = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    fetcher_git($checkout, ['commit', '--quiet', '--allow-empty', '-m', 'Fixed main']);
    $tip = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    fetcher_git($checkout, ['update-ref', 'refs/remotes/origin/main', $tip]);
    fetcher_git($checkout, ['reset', '--quiet', '--hard', $original]);
    $bases = fetcher(new LocalShellSshExecutor);

    expect($bases->defaultTip($group))->toBe($tip);
    expect($bases->isAncestor($group, $original, $tip))->toBeTrue();
    expect($bases->isAncestor($group, $tip, $original))->toBeFalse();
    expect(fetcher_git($checkout, ['rev-parse', 'HEAD']))->toBe($original);
    expect(fetcher_git($checkout, ['rev-parse', '--abbrev-ref', 'HEAD']))->toBe('task-7');
    expect(fn () => $bases->isAncestor($group, str_repeat('f', 40), $tip))->toThrow(TaskPullRequestException::class);
    fetcher_git($checkout, ['update-ref', '-d', 'refs/remotes/origin/main']);
    expect(fn () => $bases->defaultTip($group))->toThrow(TaskPullRequestException::class);
    Http::assertNothingSent();
});

it('resets an untouched baseline workspace to the fetched default branch tip without changing its branch', function (): void {
    $root = TestOrbitHome::scratch('orbit-baseline-reset');
    $checkout = $root.'/checkout';
    (new Process(['git', 'init', '--quiet', '--bare', $root.'/origin.git']))->mustRun();
    (new Process(['git', 'init', '--quiet', '-b', 'task-7', $checkout]))->mustRun();
    $group = fetcher_group($checkout);
    fetcher_git($checkout, ['remote', 'add', 'origin', $root.'/origin.git']);
    file_put_contents($checkout.'/tracked.txt', 'Original baseline');
    fetcher_git($checkout, ['add', 'tracked.txt']);
    fetcher_git($checkout, ['commit', '--quiet', '-m', 'Original baseline']);
    $original = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    file_put_contents($checkout.'/tracked.txt', 'Fixed baseline');
    fetcher_git($checkout, ['commit', '--quiet', '-am', 'Fix baseline']);
    $tip = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    fetcher_git($checkout, ['push', '--quiet', 'origin', 'HEAD:refs/heads/main']);
    fetcher_git($checkout, ['reset', '--quiet', '--hard', $original]);
    fetcher_git($checkout, ['update-ref', '-d', 'refs/remotes/origin/main']);
    $bases = fetcher(new LocalShellSshExecutor);
    $bases->fetchForTurn($group);
    expect(fetcher_git($checkout, ['rev-parse', 'HEAD']))->toBe($original);

    expect($bases->defaultTip($group))->toBe($tip);
    expect($bases->isAncestor($group, $original, $tip))->toBeTrue();
    expect($bases->isAncestor($group, $tip, $original))->toBeFalse();
    expect(fetcher_git($checkout, ['rev-parse', 'HEAD']))->toBe($original);
    expect(file_get_contents($checkout.'/tracked.txt'))->toBe('Original baseline');

    $head = $bases->resetToDefault($group);

    expect($head)->toBe($tip);
    expect(fetcher_git($checkout, ['rev-parse', 'HEAD']))->toBe($tip);
    expect(fetcher_git($checkout, ['rev-parse', '--abbrev-ref', 'HEAD']))->toBe('task-7');
    expect(file_get_contents($checkout.'/tracked.txt'))->toBe('Fixed baseline');
});

it('does not reset a baseline when the default branch name is invalid', function (): void {
    $transport = new AppDevFakeSshExecutor;
    $group = fetcher_group('/srv/orbit/apps/shop/task-7');
    $group->project->update(['default_branch' => 'HEAD']);

    expect(fn () => fetcher($transport)->resetToDefault($group->fresh() ?? $group))
        ->toThrow(TaskPullRequestException::class, 'The baseline workspace could not be reset.');
    expect($transport->commands)->toHaveCount(0);
});

it('leaves the workspace alone when the task branch is missing and that absence is allowed', function (): void {
    $root = TestOrbitHome::scratch('orbit-fast-forward-missing');
    $checkout = $root.'/checkout';
    (new Process(['git', 'init', '--quiet', '--bare', $root.'/origin.git']))->mustRun();
    (new Process(['git', 'init', '--quiet', '-b', 'task-7', $checkout]))->mustRun();
    $group = fetcher_group($checkout);
    fetcher_git($checkout, ['remote', 'add', 'origin', $root.'/origin.git']);
    fetcher_git($checkout, ['commit', '--quiet', '--allow-empty', '-m', 'Local']);
    $before = fetcher_git($checkout, ['rev-parse', 'HEAD']);

    fetcher(new LocalShellSshExecutor)->fastForward($group, missingRefOk: true);

    expect(fetcher_git($checkout, ['rev-parse', 'HEAD']))->toBe($before);
    expect(fn () => fetcher(new LocalShellSshExecutor)->fastForward($group))
        ->toThrow(TaskPullRequestException::class, 'The task branch could not be fetched.');
});
