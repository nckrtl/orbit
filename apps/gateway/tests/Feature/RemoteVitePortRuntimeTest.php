<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\RemoteVitePortRuntime;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process as LocalProcess;
use Tests\Support\LinuxHost;

function local_vite_port_runtime(?Closure $execute = null): RemoteVitePortRuntime
{
    $ssh = Mockery::mock(SshExecutor::class);
    $ssh->shouldReceive('execute')->andReturnUsing($execute ?? function (SshConnection $connection, RemoteCommand $command): CommandResult {
        expect(array_slice($command->arguments, 0, 2))->toBe(['python3', '-c']);
        $process = new LocalProcess($command->arguments, input: $command->input, timeout: 10);
        $process->run();

        return new CommandResult($process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput(), 1, false);
    });
    app()->instance(SshExecutor::class, $ssh);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/test/id');
    app()->instance(SshKeyProvider::class, $keys);
    $hosts = Mockery::mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/test/known-hosts');
    app()->instance(KnownHostsStore::class, $hosts);

    return app(RemoteVitePortRuntime::class);
}

it('skips actual occupied TCP ports and explicit exclusions on Linux', function (string $address): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $listener = stream_socket_server($address, $code, $message);
    expect($listener)->not->toBeFalse();
    $name = stream_socket_get_name($listener, false);
    $port = (int) substr($name, strrpos($name, ':') + 1);
    try {
        $selected = local_vite_port_runtime()->selectPort(new Node(['user' => 'orbit', 'wireguard_ip' => '192.0.2.1']), $port, [$port + 1]);
        expect($selected)->toBeGreaterThan($port + 1);
    } finally {
        fclose($listener);
    }
})->with(['IPv4' => 'tcp://127.0.0.1:0', 'IPv6' => 'tcp://[::1]:0']);

it('prepares Vite using application-local dependencies and publishes its environment', function (string $root, bool $laravel, string $suffix, ?string $missing): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $sandbox = sys_get_temp_dir().'/orbit-vite-prepare-'.bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $checkout = $sandbox.'/checkout';
    $application = $checkout.$suffix;
    $filesystem->makeDirectory($application, 0755, true);
    $filesystem->makeDirectory($sandbox.'/bin', 0755, true);
    $filesystem->put($sandbox.'/bin/sudo', "#!/bin/sh\nexec \"\$@\"\n");
    $filesystem->put($sandbox.'/bin/vp', "#!/bin/sh\nexit 0\n");
    chmod($sandbox.'/bin/sudo', 0755);
    chmod($sandbox.'/bin/vp', 0755);
    if ($missing !== 'package.json') {
        $filesystem->put($application.'/package.json', '{}');
    }
    if ($missing !== 'node_modules') {
        $filesystem->makeDirectory($application.'/node_modules');
    }
    $node = Node::query()->create(['name' => 'vite-prepare', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '192.0.2.1']);
    $node->roles()->create(['role' => 'app-dev', 'status' => LifecycleStatus::Active]);
    $project = Project::query()->create(['name' => 'Vite prepare', 'slug' => 'vite-prepare', 'repository_url' => 'git@example.test:vite.git', 'root' => $root]);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'main',
        'checkout_path' => $checkout, 'source_is_laravel' => $laravel, 'vite_port' => 5210,
    ]);
    $runtime = local_vite_port_runtime(function (SshConnection $connection, RemoteCommand $command) use ($sandbox): CommandResult {
        // Redirect only host-owned runtime paths; execute the real dependency checks and publication script.
        $arguments = array_map(static fn (string $argument): string => str_replace(
            ['/usr/local/bin/vp', '/etc/orbit/vite', '/dev/shm/orbit/hibernation'],
            [$sandbox.'/bin/vp', $sandbox.'/environment', $sandbox.'/run'],
            $argument,
        ), $command->arguments);
        if ($arguments[0] === 'sudo') {
            $arguments[0] = $sandbox.'/bin/sudo';
        }
        $local = new LocalProcess($arguments, env: ['PATH' => $sandbox.'/bin:'.getenv('PATH')], input: $command->input, timeout: 10);
        $local->run();

        return new CommandResult($local->getExitCode() ?? 1, $local->getOutput(), $local->getErrorOutput(), 1, false);
    });

    try {
        if ($missing !== null) {
            expect(fn () => $runtime->prepare(new Process, $instance))
                ->toThrow(fn (RuntimeConvergenceException $exception): bool => $exception->errorCode === 'vite.environment_failed');
            expect(file_exists($sandbox.'/environment/app-instance-'.$instance->id.'-web.env'))->toBeFalse();
        } else {
            $runtime->prepare(new Process, $instance);
            $environment = $sandbox.'/environment/app-instance-'.$instance->id.'-web.env';
            expect(file_get_contents($environment))->toBe("# Orbit Instance {$instance->id}\n# Orbit App web\nORBIT_DEV_SERVER_PORT=5210\n")
                ->and(fileperms($environment) & 0o777)->toBe(0o600)
                ->and(file_exists($environment.'.pending'))->toBeFalse();
            $runtime->stageEnvironment($instance, 'web');
            $foreign = "# Orbit Instance {$instance->id}\n# Orbit App docs\nORBIT_DEV_SERVER_PORT=5333\n";
            file_put_contents($environment, $foreign);
            expect(fn () => $runtime->stageEnvironment($instance, 'web'))->toThrow(RuntimeConvergenceException::class)
                ->and(file_get_contents($environment))->toBe($foreign);
            expect(fn () => $runtime->prepare(new Process, $instance))->toThrow(RuntimeConvergenceException::class)
                ->and(file_get_contents($environment))->toBe($foreign);
        }
    } finally {
        $filesystem->deleteDirectory($sandbox);
    }
})->with([
    'nested Laravel dependencies only in the app' => ['server/web/public', true, '/server/web', null],
    'root public Laravel app' => ['public', true, '', null],
    'non Laravel uses the configured app directory' => ['server/web/public', false, '/server/web', null],
    'nested app missing manifest' => ['server/web/public', true, '/server/web', 'package.json'],
    'nested app missing dependencies' => ['server/web/public', true, '/server/web', 'node_modules'],
]);

it('reports finite exhaustion when the final candidate is excluded', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    expect(fn () => local_vite_port_runtime()->selectPort(new Node(['user' => 'orbit', 'wireguard_ip' => '192.0.2.1']), 65535, [65535]))->toThrow(ProcessOperationException::class, 'No available Vite port remains');
});
