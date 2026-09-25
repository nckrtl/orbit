<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceDiffReader;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Tests\Support\AppDevFakeSshExecutor;

function remote_diff_instance(): AppInstance
{
    $app = OrbitApp::query()->create([
        'name' => 'orbit',
        'slug' => 'orbit',
        'repository_url' => 'git@github.com:nckrtl/orbit.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'diff-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.142',
        'wireguard_ip' => '10.44.0.142',
        'user' => 'orbit',
    ]);

    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'task-12',
        'checkout_path' => '/srv/orbit/apps/orbit/task-12',
        'branch' => 'task-12',
        'status' => 'source_resolved',
    ]);
}

function remote_diff_ssh(AppDevFakeSshExecutor $transport): AppDevSshExecutor
{
    return new AppDevSshExecutor(
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

    $diff = new RemoteTaskWorkspaceDiffReader(remote_diff_ssh($transport))->lineChanges(
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
        ->and((string) $transport->commands[0]->input)->toContain('git -C "$checkout" diff --shortstat');
});

it('reports commits after a revision or date', function (): void {
    $transport = new AppDevFakeSshExecutor([
        new CommandResult(0, "2\n", '', 1, false),
    ]);

    expect(new RemoteTaskWorkspaceDiffReader(remote_diff_ssh($transport))->hasCommitsSince(
        remote_diff_instance(),
        str_repeat('a', 40),
    ))->toBeTrue()
        ->and((string) $transport->commands[0]->input)->toContain('rev-list --count');
});

it('returns false when the workspace has no commits since the marker', function (): void {
    $transport = new AppDevFakeSshExecutor([
        new CommandResult(0, "0\n", '', 1, false),
    ]);

    expect(new RemoteTaskWorkspaceDiffReader(remote_diff_ssh($transport))->hasCommitsSince(
        remote_diff_instance(),
        '2026-09-21T00:00:00+00:00',
    ))->toBeFalse();
});

it('returns 0 when remote git cannot read the diff', function (): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult(1, '', 'missing', 1, false)]);

    expect(new RemoteTaskWorkspaceDiffReader(remote_diff_ssh($transport))->lineDiff(
        remote_diff_instance(),
        'main',
    ))->toBe(0);
});

it('reads a shortstat with only insertions or only deletions', function (string $output, array $expected): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult(0, $output, '', 1, false)]);

    expect(new RemoteTaskWorkspaceDiffReader(remote_diff_ssh($transport))->lineChanges(remote_diff_instance(), 'main'))->toBe($expected);
})->with([
    'insertions' => [" 5500 files changed, 5500 insertions(+)\n", ['additions' => 5500, 'deletions' => 0]],
    'one deletion' => [" 1 file changed, 1 deletion(-)\n", ['additions' => 0, 'deletions' => 1]],
    'no change' => ['', ['additions' => 0, 'deletions' => 0]],
]);
