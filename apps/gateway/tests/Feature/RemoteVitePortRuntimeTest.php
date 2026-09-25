<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessOperationException;
use App\Infrastructure\AppDev\RemoteVitePortRuntime;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Symfony\Component\Process\Process as LocalProcess;
use Tests\Support\LinuxNodeProgram;

function local_vite_port_runtime(): RemoteVitePortRuntime
{
    $ssh = Mockery::mock(SshExecutor::class);
    $ssh->shouldReceive('execute')->andReturnUsing(function (SshConnection $connection, RemoteCommand $command): CommandResult {
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
    LinuxNodeProgram::require('/proc/net/tcp');
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

it('reports finite exhaustion when the final candidate is excluded', function (): void {
    LinuxNodeProgram::require('/proc/net/tcp');
    expect(fn () => local_vite_port_runtime()->selectPort(new Node(['user' => 'orbit', 'wireguard_ip' => '192.0.2.1']), 65535, [65535]))->toThrow(ProcessOperationException::class, 'No available Vite port remains');
});
