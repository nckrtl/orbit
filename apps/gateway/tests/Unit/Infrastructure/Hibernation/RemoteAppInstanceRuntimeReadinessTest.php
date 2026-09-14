<?php

declare(strict_types=1);

use App\Domain\Hibernation\HibernationException;
use App\Infrastructure\Hibernation\RemoteAppInstanceRuntimeReadiness;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\ProcessesApiFakeRuntimeManager;

it('waits for observed running status and the Vite port', function (): void {
    $runtime = new ProcessesApiFakeRuntimeManager;
    $runtime->statusOverride = 'running';
    $ssh = new AppDevFakeSshExecutor([new CommandResult(0, '', '', 1, false)]);
    $readiness = new RemoteAppInstanceRuntimeReadiness(
        runtime: $runtime,
        ssh: $ssh,
        keys: new HibernationReadinessKeyProvider,
        knownHosts: new HibernationReadinessKnownHostsStore,
        timeoutSeconds: 5,
    );
    $instance = readiness_instance();
    $process = readiness_process($instance, 'vite');

    $readiness->waitUntilReady($instance, [$process]);

    expect($ssh->commands)->toHaveCount(1)
        ->and($ssh->commands[0]->arguments[0])
        ->toBe('bash')
        ->and($ssh->commands[0]->arguments[2])
        ->toContain('127.0.0.1/5173');
});

it('does not probe the Vite port for a queue Process', function (): void {
    $runtime = new ProcessesApiFakeRuntimeManager;
    $runtime->statusOverride = 'active';
    $ssh = new AppDevFakeSshExecutor;
    $readiness = new RemoteAppInstanceRuntimeReadiness(
        runtime: $runtime,
        ssh: $ssh,
        keys: new HibernationReadinessKeyProvider,
        knownHosts: new HibernationReadinessKnownHostsStore,
        timeoutSeconds: 5,
    );
    $instance = readiness_instance();
    $process = readiness_process($instance, 'queue');

    $readiness->waitUntilReady($instance, [$process]);

    expect($ssh->commands)->toBe([]);
});

it('fails when the development server never accepts connections', function (): void {
    $runtime = new ProcessesApiFakeRuntimeManager;
    $runtime->statusOverride = 'running';
    $ssh = new AppDevFakeSshExecutor([new CommandResult(1, '', 'timeout', 1, false)]);
    $readiness = new RemoteAppInstanceRuntimeReadiness(
        runtime: $runtime,
        ssh: $ssh,
        keys: new HibernationReadinessKeyProvider,
        knownHosts: new HibernationReadinessKnownHostsStore,
        timeoutSeconds: 5,
    );
    $instance = readiness_instance();
    $process = readiness_process($instance, 'vite');

    expect(fn () => $readiness->waitUntilReady($instance, [$process]))
        ->toThrow(HibernationException::class);
});

function readiness_instance(): AppInstance
{
    $node = new Node([
        'name' => 'app-dev',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $instance = new AppInstance([
        'name' => 'main',
        'environment' => 'development',
    ]);
    $instance->setRelation('node', $node);

    return $instance;
}

function readiness_process(AppInstance $instance, string $name): Process
{
    $process = new Process([
        'name' => $name,
        'runtime' => 'systemd',
        'working_directory' => '/home/orbit/apps/docs',
        'runtime_config' => ['command' => ['/usr/bin/true']],
        'restart_policy' => 'on-failure',
        'desired_state' => 'running',
        'status' => 'active',
    ]);
    $process->id = 9;
    $process->setRelation('owner', $instance);

    return $process;
}

final class HibernationReadinessKeyProvider implements SshKeyProvider
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

final class HibernationReadinessKnownHostsStore implements KnownHostsStore
{
    public function path(): string
    {
        return '/orbit/ssh/known_hosts';
    }

    public function put(string $host, int $port, HostKey $key): void {}
}
