<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\GitHubTaskBaseBranchFetcher;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\TaskGroup;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\TestOrbitHome;

/** @param  list<string>  $arguments */
function fetcher_git(string $directory, array $arguments): string
{
    return trim((new Process(['git', '-C', $directory, '-c', 'user.name=t', '-c', 'user.email=t@t', ...$arguments]))->mustRun()->getOutput());
}

function fetcher_group(string $checkout): TaskGroup
{
    $app = OrbitApp::query()->create([
        'name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'git@github.com:acme/shop.git', 'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'fetch-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.150', 'wireguard_ip' => '10.44.0.150', 'user' => 'orbit',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id, 'node_id' => $node->id, 'name' => 'task-7', 'checkout_path' => $checkout,
        'branch' => 'task-7', 'status' => 'source_resolved',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id, 'title' => 'Export orders', 'brief' => 'Add an export.', 'status' => 'settling',
    ]);
    $group->taskable()->associate($instance);
    $group->save();

    return $group->fresh(['app', 'taskable']) ?? $group;
}

function fetcher(SshExecutor $transport): GitHubTaskBaseBranchFetcher
{
    app()->instance(AppDevSshExecutor::class, new AppDevSshExecutor(
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
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_fetch'], 201),
    ]);
});

afterEach(function (): void {
    TestOrbitHome::clearScratch();
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
        ->and(stream_get_contents($command->protectedInput?->stream()))->toContain('git_read git -C "$checkout" fetch --quiet origin "$base"');
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

    fetcher(new LocalShellSshExecutor)->fastForward($group, missingRefOk: $case === 'behind-missing-ok');

    expect(fetcher_git($checkout, ['rev-parse', 'HEAD']))->toBe(in_array($case, ['behind', 'behind-missing-ok'], true) ? $remote : $before)
        ->and(fetcher_git($checkout, ['rev-parse', 'refs/remotes/origin/'.$branch]))->toBe($remote)
        ->and(fetcher_git($checkout, ['rev-parse', '--abbrev-ref', 'HEAD']))->toBe('task-7');
})->with(['behind', 'behind-missing-ok', 'level', 'ahead', 'diverged']);

it('fast-forwards with the task branch as one argument, the token only on standard input, and never forces the workspace', function (): void {
    $transport = new AppDevFakeSshExecutor;
    $group = fetcher_group('/srv/orbit/apps/shop/task-7');

    fetcher($transport)->fastForward($group);

    $command = $transport->commands[0];
    $script = (string) stream_get_contents($command->protectedInput?->stream());
    expect($command->arguments)->toBe(['bash', '-seu', '--', '/srv/orbit/apps/shop/task-7', 'task-'.$group->id])
        ->and($command->input)->toBeNull()
        ->and($script)->toContain(base64_encode('x-access-token:ghs_fetch'))
        ->and($script)->toContain('merge --ff-only')
        ->and($script)->not->toContain('reset')
        ->and($script)->not->toContain('push');
});

it('reports one failure when the task branch fetch fails', function (): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult(128, '', 'fatal: not found', 1, false)]);

    expect(fn () => fetcher($transport)->fastForward(fetcher_group('/srv/orbit/apps/shop/task-7')))
        ->toThrow(TaskPullRequestException::class, 'The task branch could not be fetched.');
    expect($transport->commands)->toHaveCount(1);
});

it('passes missing-ok when a missing task branch should not fail the resume', function (): void {
    $transport = new AppDevFakeSshExecutor;
    $group = fetcher_group('/srv/orbit/apps/shop/task-7');

    fetcher($transport)->fastForward($group, missingRefOk: true);

    $script = (string) stream_get_contents($transport->commands[0]->protectedInput?->stream());
    expect($transport->commands[0]->arguments)->toBe(['bash', '-seu', '--', '/srv/orbit/apps/shop/task-7', 'task-'.$group->id, 'missing-ok'])
        ->and($script)->toContain('ls-remote --exit-code --heads origin "$branch"')
        ->and($script)->toContain('missing-ok');
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
