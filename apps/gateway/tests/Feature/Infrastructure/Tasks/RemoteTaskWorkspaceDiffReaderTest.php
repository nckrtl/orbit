<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Compute\SandboxFleetIdentity;
use App\Infrastructure\Compute\TaskSandboxDrivers;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\IncusSandboxHost;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceDiffReader;
use App\Infrastructure\Tasks\TaskWorkspaceExecutor;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\LocalShellSshExecutor;

function remote_diff_instance(string $checkout = '/srv/orbit/apps/orbit/task-12'): Instance
{
    $project = Project::query()->create([
        'name' => 'orbit',
        'slug' => 'orbit',
        'repository_url' => 'git@github.com:nckrtl/orbit.git',
        'default_branch' => 'main',
        'apps' => fixture_apps(null),
    ]);
    $node = Node::query()->create([
        'name' => 'diff-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.142',
        'wireguard_ip' => '10.44.0.142',
        'user' => 'orbit',
    ]);

    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'task-12',
        'checkout_path' => $checkout,
        'branch' => 'task-12',
        'status' => 'source_resolved',
    ]);
}

function remote_diff_ssh(SshExecutor $transport): DevelopmentSshExecutor
{
    return new DevelopmentSshExecutor(
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
    );
}

it('reads insertions and deletions from git shortstat', function (): void {
    $transport = new AppDevFakeSshExecutor([
        new CommandResult(0, " 3 files changed, 13 insertions(+), 3 deletions(-)\n", '', 1, false),
    ]);

    $diff = new RemoteTaskWorkspaceDiffReader(new TaskWorkspaceExecutor(remote_diff_ssh($transport), app(IncusSandboxHost::class), app(TaskSandboxDrivers::class), app(SandboxFleetIdentity::class)))->lineChanges(
        remote_diff_instance(),
        'main',
    );

    expect($diff)->toBe(['additions' => 13, 'deletions' => 3])
        ->and($transport->commands[0]->arguments)->toBe([
            'bash',
            '-seu',
            '--',
            '/srv/orbit/apps/orbit/task-12',
            'main',
        ])
        ->and((string) $transport->commands[0]->input)->toContain('git -c core.hooksPath=/dev/null -c core.fsmonitor=false -c safe.directory="$checkout" -C "$checkout" diff --shortstat');
});

it('leaves a merged default branch out of the line counts', function (): void {
    $root = sys_get_temp_dir().'/orbit-task-diff-'.bin2hex(random_bytes(6));
    $git = fn (string $dir, string ...$arguments): string => trim((new Process(['git', '-C', $dir, '-c', 'user.name=Orbit', '-c', 'user.email=orbit@example.test', ...$arguments]))->mustRun()->getOutput());

    try {
        (new Process(['git', 'init', '--quiet', '--bare', '--initial-branch=main', "{$root}/origin.git"]))->mustRun();
        (new Process(['git', 'clone', '--quiet', "{$root}/origin.git", "{$root}/upstream"]))->mustRun();
        File::put("{$root}/upstream/a.txt", "a\n");
        $git("{$root}/upstream", 'add', 'a.txt');
        $git("{$root}/upstream", 'commit', '--quiet', '-m', 'root');
        $git("{$root}/upstream", 'push', '--quiet', 'origin', 'HEAD:main');
        (new Process(['git', 'clone', '--quiet', "{$root}/origin.git", "{$root}/task"]))->mustRun();
        $git("{$root}/task", 'checkout', '--quiet', '-b', 'task-12');
        File::put("{$root}/task/b.txt", "b1\nb2\n");
        $git("{$root}/task", 'add', 'b.txt');
        $git("{$root}/task", 'commit', '--quiet', '-m', 'task');
        File::put("{$root}/upstream/c.txt", "c1\nc2\nc3\n");
        $git("{$root}/upstream", 'add', 'c.txt');
        $git("{$root}/upstream", 'commit', '--quiet', '-m', 'main moves');
        $git("{$root}/upstream", 'push', '--quiet', 'origin', 'HEAD:main');
        // The task merges the fetched main. Its local main stays at root.
        $git("{$root}/task", 'fetch', '--quiet', 'origin');
        $git("{$root}/task", 'merge', '--quiet', '--no-edit', 'origin/main');

        expect(new RemoteTaskWorkspaceDiffReader(new TaskWorkspaceExecutor(remote_diff_ssh(new LocalShellSshExecutor), app(IncusSandboxHost::class), app(TaskSandboxDrivers::class), app(SandboxFleetIdentity::class)))->lineChanges(
            remote_diff_instance("{$root}/task"),
            'main',
        ))->toBe(['additions' => 2, 'deletions' => 0]);
    } finally {
        File::deleteDirectory($root);
    }
});

it('reports commits after a revision or date', function (): void {
    $transport = new AppDevFakeSshExecutor([
        new CommandResult(0, "2\n", '', 1, false),
    ]);

    expect(new RemoteTaskWorkspaceDiffReader(new TaskWorkspaceExecutor(remote_diff_ssh($transport), app(IncusSandboxHost::class), app(TaskSandboxDrivers::class), app(SandboxFleetIdentity::class)))->hasCommitsSince(
        remote_diff_instance(),
        str_repeat('a', 40),
    ))->toBeTrue()
        ->and((string) $transport->commands[0]->input)->toContain('rev-list --count');
});

it('returns false when the workspace has no commits since the marker', function (): void {
    $transport = new AppDevFakeSshExecutor([
        new CommandResult(0, "0\n", '', 1, false),
    ]);

    expect(new RemoteTaskWorkspaceDiffReader(new TaskWorkspaceExecutor(remote_diff_ssh($transport), app(IncusSandboxHost::class), app(TaskSandboxDrivers::class), app(SandboxFleetIdentity::class)))->hasCommitsSince(
        remote_diff_instance(),
        '2026-09-21T00:00:00+00:00',
    ))->toBeFalse();
});

it('returns 0 when remote git cannot read the diff', function (): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult(1, '', 'missing', 1, false)]);

    expect(new RemoteTaskWorkspaceDiffReader(new TaskWorkspaceExecutor(remote_diff_ssh($transport), app(IncusSandboxHost::class), app(TaskSandboxDrivers::class), app(SandboxFleetIdentity::class)))->lineDiff(
        remote_diff_instance(),
        'main',
    ))->toBe(0);
});

it('reads a shortstat with only insertions or only deletions', function (string $output, array $expected): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult(0, $output, '', 1, false)]);

    expect(new RemoteTaskWorkspaceDiffReader(new TaskWorkspaceExecutor(remote_diff_ssh($transport), app(IncusSandboxHost::class), app(TaskSandboxDrivers::class), app(SandboxFleetIdentity::class)))->lineChanges(remote_diff_instance(), 'main'))->toBe($expected);
})->with([
    'insertions' => [" 5500 files changed, 5500 insertions(+)\n", ['additions' => 5500, 'deletions' => 0]],
    'one deletion' => [" 1 file changed, 1 deletion(-)\n", ['additions' => 0, 'deletions' => 1]],
    'no change' => ['', ['additions' => 0, 'deletions' => 0]],
]);
