<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\Storage\EffectiveStorageRoots;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Nodes\RemoteNodeStorageRootPreparer;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Tests\Support\AppDevFakeSshExecutor;

it('prepares configured roots through a narrow sudo bash command', function (): void {
    $node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.10',
        'wireguard_address' => '10.44.0.3',
        'user' => 'orbit',
    ]);
    $ssh = new AppDevFakeSshExecutor;
    $preparer = new RemoteNodeStorageRootPreparer(new AppDevSshExecutor(
        $ssh,
        new class implements SshKeyProvider {
            public function privateKeyPath(): string
            {
                return '/home/orbit/.orbit/ssh/id_ed25519';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 AAAA';
            }
        },
        new class implements KnownHostsStore {
            public function path(): string
            {
                return '/home/orbit/.orbit/ssh/known_hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    ));

    $preparer->prepare(
        $node,
        new ManagedUserAccount('orbit', 'orbit', '/home/orbit'),
        new EffectiveStorageRoots(
            StoragePath::parse('/srv/orbit/instances'),
            StoragePath::parse('/srv/orbit/worktrees'),
        ),
    );

    expect($ssh->commands)
        ->toHaveCount(2)
        ->and($ssh->commands[0]->arguments)
        ->toBe([
            'sudo',
            'bash',
            '-seu',
            '--',
            '/srv/orbit/instances',
            'orbit',
            'orbit',
            '/home/orbit',
            '1',
        ])
        ->and($ssh->commands[0]->input)
        ->toContain('install -d -o "$managed_user" -g "$managed_group" -m 0755 -- "$current"')
        ->and($ssh->commands[1]->arguments)
        ->toBe([
            'sudo',
            'bash',
            '-seu',
            '--',
            '/srv/orbit/worktrees',
            'orbit',
            'orbit',
            '/home/orbit',
            '1',
        ]);
});
