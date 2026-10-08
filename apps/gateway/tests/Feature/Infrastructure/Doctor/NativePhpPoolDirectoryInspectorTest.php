<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\PhpPoolDirectoryObservation;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DevelopmentPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Infrastructure\Doctor\NativePhpPoolDirectoryInspector;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Tests\Support\AppDevFakeSshExecutor;

it('reports the live pools whose working directory is missing on an app-dev Node', function (): void {
    $node = php_pool_inspector_node(RoleName::AppDev);
    $configuration = "[orbit-app-instance-342]\nchdir = /fast/apps/orbit-website/task-1172\n";
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "8.5\t".base64_encode($configuration)."\nmissing-directory\t/fast/apps/orbit-website/task-1172\n", '', 1, false),
    ]);

    $observations = php_pool_inspector($ssh)->inspect($node);

    expect($observations)
        ->toEqual([new PhpPoolDirectoryObservation('orbit-app-instance-342', '8.5', '/fast/apps/orbit-website/task-1172', installed: true)])
        ->and($ssh->connections[0]->commandTimeout)
        ->toBe(NativePhpPoolDirectoryInspector::ReadTimeoutSeconds)
        ->and($ssh->commands)
        ->toHaveCount(1);
});

it('reads nothing on a Node without an active application role and maps a failed read to unverifiable', function (): void {
    $metrics = php_pool_inspector_node(RoleName::Metrics);
    $unread = new AppDevFakeSshExecutor;
    $failing = new AppDevFakeSshExecutor([new CommandResult(1, '', 'connection refused', 1, false)]);

    expect(php_pool_inspector($unread)->inspect($metrics))
        ->toBe([])
        ->and($unread->commands)
        ->toBe([])
        ->and(fn () => php_pool_inspector($failing)->inspect(php_pool_inspector_node(RoleName::AppDev, 'failing')))
        ->toThrow(DoctorInspectionException::class);
});

function php_pool_inspector_node(RoleName $role, string $name = 'pool-node'): Node
{
    static $number = 40;
    $number++;
    $node = Node::query()->create([
        'name' => "{$name}-{$role->value}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "192.0.2.{$number}",
        'wireguard_ip' => "10.44.0.{$number}",
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => $role, 'status' => LifecycleStatus::Active]);

    return $node;
}

function php_pool_inspector(AppDevFakeSshExecutor $ssh): NativePhpPoolDirectoryInspector
{
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/home/orbit/.orbit/ssh/id_ed25519';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAA';
        }
    };
    $knownHosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/home/orbit/.orbit/ssh/known_hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
    $accounts = new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    };

    return new NativePhpPoolDirectoryInspector(new RemoteAppDevPhpFpmManager(
        sites: new DevelopmentSiteRepository,
        renderer: new DevelopmentPhpFpmConfigRenderer,
        ssh: new DevelopmentSshExecutor($ssh, $keys, $knownHosts),
        accounts: $accounts,
        packages: new RemotePhpPackageManager,
    ));
}
