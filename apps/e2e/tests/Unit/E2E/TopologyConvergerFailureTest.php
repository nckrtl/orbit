<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\TopologyConverger;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyTarget;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    /** @mago-expect analysis:possibly-invalid-argument The process facade only needs the container contract in unit tests. */
    Facade::setFacadeApplication($container);
});

function failing_converger_vm(string $name): string
{
    return json_encode([[
        'name' => $name,
        'type' => 'virtual-machine',
        'status' => 'Stopped',
        'status_code' => 102,
        'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
        'devices' => ['root' => ['pool' => 'orbit-e2e']],
    ]], JSON_THROW_ON_ERROR);
}

describe('TopologyConverger failures', function () {
    it('reports safe guest failure evidence without exposing command output', function (
        int $exitCode,
        string $stderr,
        string $expected,
    ) {
        Process::fake(function (PendingProcess $process) use ($exitCode, $stderr) {
            $command = $process->command;
            assert(is_array($command));

            if (array_slice($command, -4) === ['network', 'list', 'lab:', '--format=json']) {
                return Process::result(json_encode([[
                    'name' => 'oe-b32d6c83af72',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR));
            }

            if (($command[count($command) - 1] ?? null) === '--format=json') {
                $target = $command[count($command) - 2];
                $name = str_contains($target, ':') ? substr($target, strpos($target, ':') + 1) : $target;

                return Process::result(failing_converger_vm($name));
            }

            if (in_array('/usr/local/bin/converge-gateway.sh', $command, true)) {
                return Process::result('private stdout', $stderr, $exitCode);
            }

            return Process::result();
        });

        try {
            new TopologyConverger(new IncusHost(remote: 'lab', project: 'orbit', pool: 'orbit-e2e'))->converge(
                new TopologyTarget('NCK-123'),
                new SourceState(str_repeat('a', 40), str_repeat('a', 40), false),
                new LaravelRelease('v13.10.1', str_repeat('b', 40)),
            );
            $this->fail('Expected gateway convergence to fail.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())
                ->toBe($expected)
                ->not->toContain('private stdout', 'private-token');
        }
    })->with([
        'migration exit' => [
            70,
            'Bearer private-token',
            'Guest convergence script converge-gateway.sh failed on orbit-e2e-nck-123-gateway with exit code 70.',
        ],
        'gateway domain failure' => [
            71,
            "orbit-e2e-failure step=wireguard-server-install error=vpn.server_install_failed\n".'Bearer private-token',
            'Guest convergence script converge-gateway.sh failed on '
                .'orbit-e2e-nck-123-gateway with exit code 71 at step '
                .'wireguard-server-install (vpn.server_install_failed).',
        ],
    ]);
});
