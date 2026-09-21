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

it('sums insertions and deletions from git numstat', function (): void {
    $transport = new AppDevFakeSshExecutor([
        new CommandResult(0, "10\t2\tapp/Models/Task.php\n-\t-\tlogo.png\n3\t1\tdocs/reference/tasks.md\n", '', 1, false),
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
        ->and((string) $transport->commands[0]->input)->toContain('git -C "$checkout" diff --numstat');
});

it('returns 0 when remote git cannot read the diff', function (): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult(1, '', 'missing', 1, false)]);

    expect(new RemoteTaskWorkspaceDiffReader(remote_diff_ssh($transport))->lineDiff(
        remote_diff_instance(),
        'main',
    ))->toBe(0);
});
