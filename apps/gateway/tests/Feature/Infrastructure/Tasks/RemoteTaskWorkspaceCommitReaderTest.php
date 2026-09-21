<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceCommitReader;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Tests\Support\AppDevFakeSshExecutor;

function remote_commit_instance(): AppInstance
{
    $app = OrbitApp::query()->create([
        'name' => 'orbit',
        'slug' => 'orbit',
        'repository_url' => 'git@github.com:nckrtl/orbit.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'commit-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.143',
        'wireguard_ip' => '10.44.0.143',
        'user' => 'orbit',
    ]);

    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'task-21',
        'checkout_path' => '/srv/orbit/apps/orbit/task-21',
        'branch' => 'task-21',
        'status' => 'source_resolved',
    ]);
}

function remote_commit_ssh(AppDevFakeSshExecutor $transport): AppDevSshExecutor
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

it('reads the checkout commit times newest first', function (): void {
    $transport = new AppDevFakeSshExecutor([
        new CommandResult(0, "2026-09-21T12:00:00+00:00\n2026-09-21T10:30:00+00:00\n\n", '', 1, false),
    ]);

    $times = new RemoteTaskWorkspaceCommitReader(remote_commit_ssh($transport))->commitTimes(
        remote_commit_instance(),
    );

    expect($times)->toHaveCount(2)
        ->and($times[0]->toIso8601String())->toBe('2026-09-21T12:00:00+00:00')
        ->and($transport->commands[0]->arguments)->toBe([
            'bash',
            '-seu',
            '--',
            '/srv/orbit/apps/orbit/task-21',
            '200',
        ])
        ->and((string) $transport->commands[0]->input)->toContain('git -C "$checkout" log --max-count="$limit" --format=%cI');
});

it('returns null when remote git cannot read the checkout', function (): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult(1, '', 'not a git repository', 1, false)]);

    expect(new RemoteTaskWorkspaceCommitReader(remote_commit_ssh($transport))->commitTimes(
        remote_commit_instance(),
    ))->toBeNull();
});
