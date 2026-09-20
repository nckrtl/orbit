<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceSigner;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Tests\Support\AppDevFakeSshExecutor;

function task_signer_instance(): AppInstance
{
    $app = OrbitApp::query()->create([
        'name' => 'orbit',
        'slug' => 'orbit',
        'repository_url' => 'git@example.test:orbit.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'signer-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.130',
        'wireguard_ip' => '10.44.0.130',
        'user' => 'orbit',
    ]);

    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'task-9',
        'checkout_path' => '/srv/orbit/apps/orbit/task-9',
        'status' => 'source_resolved',
    ]);
}

function task_signer_ssh(AppDevFakeSshExecutor $transport): AppDevSshExecutor
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

it('commits in the shared checkout and returns the HEAD sha', function (): void {
    $sha = str_repeat('e', 40);
    $transport = new AppDevFakeSshExecutor([new CommandResult(0, $sha."\n", '', 1, false)]);
    $instance = task_signer_instance();

    $result = new RemoteTaskWorkspaceSigner(task_signer_ssh($transport))->commit(
        $instance,
        'Reviewer sign-off: Models',
    );

    expect($result)->toBe($sha)
        ->and($transport->commands)->toHaveCount(1)
        ->and($transport->commands[0]->arguments)->toBe([
            'bash',
            '-seu',
            '--',
            '/srv/orbit/apps/orbit/task-9',
            'Reviewer sign-off: Models',
        ])
        ->and((string) $transport->commands[0]->input)->toContain('git -C "$checkout" commit');
});

it('returns null when the remote git commit fails', function (): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult(1, '', 'failed', 1, false)]);

    expect(new RemoteTaskWorkspaceSigner(task_signer_ssh($transport))->commit(
        task_signer_instance(),
        'Reviewer sign-off: Models',
    ))->toBeNull();
});
