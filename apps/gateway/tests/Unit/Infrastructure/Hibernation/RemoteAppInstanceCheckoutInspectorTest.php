<?php

declare(strict_types=1);

use App\Domain\Hibernation\LocalRuntimeDependencies;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Infrastructure\Hibernation\RemoteAppInstanceCheckoutInspector;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\AppInstance;
use App\Models\Node;
use Tests\Support\AppDevFakeSshExecutor;

it('classifies reconstructable vendor and node_modules from the remote checkout facts', function (): void {
    $stdout = implode("\n", [
        'composer_json=1',
        'composer_lock=1',
        'vendor_present=1',
        'vendor_symlink=0',
        'package_json=1',
        'node_modules_present=1',
        'node_modules_symlink=0',
        'javascript_lock=package-lock.json',
        'source_mtime=100',
        '',
    ]);
    $ssh = new AppDevFakeSshExecutor([new CommandResult(0, $stdout, '', 1, false)]);
    $inspector = checkout_inspector($ssh);
    $instance = checkout_instance();

    $state = $inspector->inspect($instance);

    expect($state->prunableVendor())
        ->toBeTrue()
        ->and($state->prunableNodeModules())
        ->toBeTrue()
        ->and($state->sourceTreeLastActivityUnix)
        ->toBe(100)
        ->and($ssh->commands[0]->arguments)
        ->toBe(['sudo', 'bash', '-seu', '--', '/home/orbit/apps/docs']);
});

it('prunes only reconstructable dependency directories and leaves lockfiles', function (): void {
    $ssh = new AppDevFakeSshExecutor([new CommandResult(0, '', '', 1, false)]);
    $inspector = checkout_inspector($ssh);
    $state = LocalRuntimeDependencies::inspect(
        composerJsonFile: true,
        composerLockFile: true,
        vendorPresent: true,
        vendorSymlink: false,
        packageJsonFile: true,
        javascriptLockFiles: ['package-lock.json'],
        nodeModulesPresent: true,
        nodeModulesSymlink: false,
    );

    $inspector->prune(checkout_instance(), $state);

    expect($ssh->commands[0]->arguments)
        ->toBe(['sudo', 'bash', '-seu', '--', '/home/orbit/apps/docs', 'vendor', 'node_modules']);
});

it('restores missing vendor with Composer and missing node_modules with frozen vp', function (): void {
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
    ]);
    $inspector = checkout_inspector($ssh, 1_800);
    $state = LocalRuntimeDependencies::inspect(
        composerJsonFile: true,
        composerLockFile: true,
        vendorPresent: false,
        vendorSymlink: false,
        packageJsonFile: true,
        javascriptLockFiles: ['package-lock.json'],
        nodeModulesPresent: false,
        nodeModulesSymlink: false,
    );

    $inspector->restore(checkout_instance(), $state);

    expect($ssh->commands[0]->arguments)
        ->toBe([
            'sudo',
            '-u',
            'orbit',
            '-H',
            'env',
            'COMPOSER_HOME=/opt/orbit/composer',
            '/usr/bin/composer',
            'install',
            '--no-interaction',
            '--prefer-dist',
            '--working-dir=/home/orbit/apps/docs',
        ])
        ->and($ssh->commands[1]->arguments)
        ->toBe([
            'sudo',
            '-u',
            'orbit',
            '-H',
            'bash',
            '-seu',
            '--',
            '/home/orbit/apps/docs',
        ])
        ->and($ssh->commands[1]->input)
        ->toContain('vp install --frozen-lockfile')
        ->and($ssh->connections[0]->commandTimeout)
        ->toBe(1_800.0);
});

function checkout_inspector(AppDevFakeSshExecutor $ssh, int $timeout = 1_800): RemoteAppInstanceCheckoutInspector
{
    return new RemoteAppInstanceCheckoutInspector(
        ssh: $ssh,
        keys: new CheckoutInspectorKeyProvider,
        knownHosts: new CheckoutInspectorKnownHostsStore,
        accounts: new CheckoutInspectorAccounts,
        restoreTimeoutSeconds: $timeout,
    );
}

function checkout_instance(): AppInstance
{
    $node = new Node([
        'name' => 'app-dev',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $instance = new AppInstance([
        'name' => 'main',
        'environment' => 'development',
        'checkout_path' => '/home/orbit/apps/docs',
    ]);
    $instance->setRelation('node', $node);

    return $instance;
}

final class CheckoutInspectorKeyProvider implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/orbit/ssh/id_ed25519';
    }

    public function publicKey(): string
    {
        return 'ssh-ed25519 test';
    }
}

final class CheckoutInspectorKnownHostsStore implements KnownHostsStore
{
    public function path(): string
    {
        return '/orbit/ssh/known_hosts';
    }

    public function put(string $host, int $port, HostKey $key): void {}
}

final class CheckoutInspectorAccounts implements ManagedUserAccountResolver
{
    public function resolve(Node $node): ManagedUserAccount
    {
        return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
    }
}
