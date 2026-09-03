<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\TopologyConverger;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyTarget;
use Illuminate\Container\Container;
use Illuminate\Contracts\Process\ProcessResult;
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
    $role = str_ends_with($name, '-gateway') ? 'gateway' : (str_ends_with($name, '-app-dev') ? 'app-dev' : 'app-prod');
    $network = 'oe-50fa1830b7de';
    $hash = substr(sha1("{$network}:{$role}"), 0, 6);
    $mac = '00:16:3e:'.implode(':', str_split($hash, 2));
    $ipv4 = ['gateway' => '10.232.2.10', 'app-dev' => '10.232.2.11', 'app-prod' => '10.232.2.12'][$role];

    return json_encode([[
        'name' => $name,
        'type' => 'virtual-machine',
        'status' => 'Stopped',
        'status_code' => 102,
        'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
        'devices' => [
            'root' => ['pool' => 'orbit-e2e'],
            'eth0' => ['network' => $network, 'ipv4.address' => $ipv4, 'hwaddr' => $mac],
        ],
    ]], JSON_THROW_ON_ERROR);
}

/** @param list<string> $command */
function failing_converger_is_global_ipv4_probe(array $command): bool
{
    return ($command[0] ?? null) === 'sh'
    && ($command[1] ?? null) === '-c'
    && str_contains((string) ($command[2] ?? ''), 'route show default');
}

function failing_converger_process_result(
    array $command,
    string $script,
    string $output,
    string $stderr,
    int $exitCode,
): ProcessResult {
    return match (true) {
        array_slice($command, -4) === ['network', 'list', 'lab:', '--format=json'] => Process::result(json_encode([[
            'name' => 'oe-50fa1830b7de',
            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e', 'ipv4.address' => '10.232.2.1/24'],
        ]], JSON_THROW_ON_ERROR)),
        ($command[count($command) - 2] ?? null) === 'lab:' && in_array('list', $command, true)
            => Process::result(json_encode(
            array_map(
                static fn (string $role): array => json_decode(
                    failing_converger_vm('orbit-e2e-tst-123-aaaaaaaa-'.$role),
                    true,
                    16,
                    JSON_THROW_ON_ERROR,
                )[0],
                ['gateway', 'app-dev', 'app-prod'],
            ),
            JSON_THROW_ON_ERROR,
        )),
        ($command[count($command) - 1] ?? null) === '--format=json' => Process::result(failing_converger_vm(
            str_contains($command[count($command) - 2], ':')
                ? substr($command[count($command) - 2], strpos($command[count($command) - 2], ':') + 1)
                : $command[count($command) - 2],
        )),
        failing_converger_is_global_ipv4_probe($command) => Process::result(
            "2: enp5s0    inet 192.0.2.10/24 scope global enp5s0\n",
        ),
        in_array('ssh-keygen', $command, true) && in_array('-y', $command, true) => Process::result(
            'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOJgN5jVtcfw7oASD2F6If4O5mQ/HZBqbrw4QC9PcHEO'."\n",
        ),
        in_array('uname', $command, true) => Process::result("x86_64\n"),
        in_array('/usr/local/bin/'.$script, $command, true) => Process::result($output, $stderr, $exitCode),
        default => Process::result(),
    };
}

function failing_converger_batch(
    PendingProcess $process,
    string $script,
    string $output,
    string $stderr,
    int $exitCode,
): ProcessResult {
    $payload = json_decode((string) $process->input, true, 512, JSON_THROW_ON_ERROR);
    $results = [];
    foreach ($payload['requests'] as $request) {
        $argv = $request['argv'];
        if (failing_converger_is_global_ipv4_probe($argv)) {
            $address = str_contains($request['instance'], 'gateway') ? '192.0.2.10' : '192.0.2.11';
            $results[] = [
                'label' => $request['label'],
                'stdout' => "2: enp5s0 inet {$address}/24 scope global enp5s0\n",
                'stderr' => '',
                'exit_code' => 0,
            ];
        } elseif (in_array('uname', $argv, true)) {
            $results[] = ['label' => $request['label'], 'stdout' => "x86_64\n", 'stderr' => '', 'exit_code' => 0];
        } elseif (in_array('/usr/local/bin/'.$script, $argv, true)) {
            $results[] = [
                'label' => $request['label'],
                'stdout' => $output,
                'stderr' => $stderr,
                'exit_code' => $exitCode,
            ];
        } else {
            $results[] = ['label' => $request['label'], 'stdout' => '', 'stderr' => '', 'exit_code' => 0];
        }
    }

    return Process::result(json_encode($results, JSON_THROW_ON_ERROR));
}

describe('TopologyConverger failures', function () {
    it('reports typed app provisioning evidence without exposing command output', function () {
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            assert(is_array($command));
            if (($command[0] ?? null) === 'python3') {
                return failing_converger_batch(
                    $process,
                    'converge-app-dev.sh',
                    'private stdout',
                    "Node provisioning failed at step [base-packages] with error [node.package_install_failed].\n",
                    $exitCode ?? 1,
                );
            }

            return failing_converger_process_result(
                $command,
                'converge-app-dev.sh',
                'private stdout',
                "Node provisioning failed at step [base-packages] with error [node.package_install_failed].\n"
                .'Bearer private-token',
                1,
            );
        });

        try {
            new TopologyConverger(new IncusHost(remote: 'lab', project: 'orbit', pool: 'orbit-e2e'))->converge(
                featureTarget('TST-123'),
                new SourceState(str_repeat('a', 40), str_repeat('a', 40), false),
                new LaravelRelease('v13.10.1', str_repeat('b', 40)),
            );
            $this->fail('Expected app-dev convergence to fail.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())
                ->toBe(
                    'Guest convergence script converge-app-dev.sh failed on orbit-e2e-tst-123-aaaaaaaa-gateway '
                    .'with exit code 1 at step base-packages (node.package_install_failed).',
                )
                ->not->toContain('private stdout', 'private-token');
        }
    });
});

describe('TopologyConverger guest failures', function () {
    it('reports safe guest failure evidence without exposing command output', function (
        int $exitCode,
        string $stderr,
        string $expected,
    ) {
        Process::fake(function (PendingProcess $process) use ($exitCode, $stderr) {
            $command = $process->command;
            assert(is_array($command));
            if (($command[0] ?? null) === 'python3') {
                return failing_converger_batch($process, 'converge-gateway.sh', 'private stdout', $stderr, $exitCode);
            }

            if (array_slice($command, -4) === ['network', 'list', 'lab:', '--format=json']) {
                return Process::result(json_encode([[
                    'name' => 'oe-50fa1830b7de',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e', 'ipv4.address' => '10.232.2.1/24'],
                ]], JSON_THROW_ON_ERROR));
            }

            if (
                ($command[count($command) - 1] ?? null) === '--format=json'
                && ($command[count($command) - 2] ?? null) === 'lab:'
            ) {
                return Process::result(json_encode(
                    array_map(
                        static fn (string $role): array => json_decode(
                            failing_converger_vm('orbit-e2e-tst-123-aaaaaaaa-'.$role),
                            true,
                            16,
                            JSON_THROW_ON_ERROR,
                        )[0],
                        ['gateway', 'app-dev', 'app-prod'],
                    ),
                    JSON_THROW_ON_ERROR,
                ));
            }
            if (($command[count($command) - 1] ?? null) === '--format=json') {
                $target = $command[count($command) - 2];
                $name = str_contains($target, ':') ? substr($target, strpos($target, ':') + 1) : $target;

                return Process::result(failing_converger_vm($name));
            }

            if (failing_converger_is_global_ipv4_probe($command)) {
                return Process::result("2: enp5s0    inet 192.0.2.10/24 scope global enp5s0\n");
            }

            if (in_array('/usr/local/bin/converge-gateway.sh', $command, true)) {
                return Process::result('private stdout', $stderr, $exitCode);
            }

            return Process::result();
        });

        try {
            new TopologyConverger(new IncusHost(remote: 'lab', project: 'orbit', pool: 'orbit-e2e'))->converge(
                featureTarget('TST-123'),
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
            'Guest convergence script converge-gateway.sh failed on orbit-e2e-tst-123-aaaaaaaa-gateway with exit code 70.',
        ],
        'gateway domain failure' => [
            71,
            "Gateway bootstrap failed at step [wireguard-server-install] with error [vpn.server_config_install_failed].\n"
                .'Bearer private-token',
            'Guest convergence script converge-gateway.sh failed on '
                .'orbit-e2e-tst-123-aaaaaaaa-gateway with exit code 71 at step '
                .'wireguard-server-install (vpn.server_config_install_failed).',
        ],
    ]);
});

describe('TopologyConverger diagnostic parsing', function () {
    /** @mago-expect lint:cyclomatic-complexity Diagnostic cases preserve one complete parser contract. */
    it('ignores arbitrary output and malformed provisioning diagnostics', function (
        string $script,
        int $exitCode,
        string $output,
    ): void {
        /** @mago-expect lint:cyclomatic-complexity Failure parsing remains one explicit process fixture. */
        Process::fake(function (PendingProcess $process) use ($script, $exitCode, $output) {
            $command = $process->command;
            assert(is_array($command));
            if (($command[0] ?? null) === 'python3') {
                return failing_converger_batch($process, $script, $output, '', $exitCode);
            }

            if (array_slice($command, -4) === ['network', 'list', 'lab:', '--format=json']) {
                return Process::result(json_encode([[
                    'name' => 'oe-50fa1830b7de',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e', 'ipv4.address' => '10.232.2.1/24'],
                ]], JSON_THROW_ON_ERROR));
            }

            if (
                ($command[count($command) - 1] ?? null) === '--format=json'
                && ($command[count($command) - 2] ?? null) === 'lab:'
            ) {
                return Process::result(json_encode(
                    array_map(
                        static fn (string $role): array => json_decode(
                            failing_converger_vm('orbit-e2e-tst-123-aaaaaaaa-'.$role),
                            true,
                            16,
                            JSON_THROW_ON_ERROR,
                        )[0],
                        ['gateway', 'app-dev', 'app-prod'],
                    ),
                    JSON_THROW_ON_ERROR,
                ));
            }

            if (($command[count($command) - 1] ?? null) === '--format=json') {
                $target = $command[count($command) - 2];
                $name = str_contains($target, ':') ? substr($target, strpos($target, ':') + 1) : $target;

                return Process::result(failing_converger_vm($name));
            }

            if (failing_converger_is_global_ipv4_probe($command)) {
                return Process::result("2: enp5s0    inet 192.0.2.10/24 scope global enp5s0\n");
            }

            if (in_array('ssh-keygen', $command, true) && in_array('-y', $command, true)) {
                return Process::result(
                    'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOJgN5jVtcfw7oASD2F6If4O5mQ/HZBqbrw4QC9PcHEO'."\n",
                );
            }

            if (in_array('uname', $command, true)) {
                return Process::result("x86_64\n");
            }

            if (in_array('/usr/local/bin/'.$script, $command, true)) {
                return Process::result($output, '', $exitCode);
            }

            return Process::result();
        });

        try {
            new TopologyConverger(new IncusHost(
                remote: 'lab',
                project: 'orbit',
                pool: 'orbit-e2e',
            ))->converge(
                featureTarget('TST-123'),
                new SourceState(str_repeat('a', 40), str_repeat('a', 40), false),
                new LaravelRelease('v13.10.1', str_repeat('b', 40)),
            );
            $this->fail('Expected gateway convergence to fail.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())
                ->toBe(
                    "Guest convergence script {$script} failed on orbit-e2e-tst-123-aaaaaaaa-gateway "
                    ."with exit code {$exitCode}.",
                )
                ->not->toContain('sensitive', 'wireguard-server-install', 'base-packages');
        }
    })->with([
        'stale gateway marker' => ['converge-gateway.sh', 71, "orbit-e2e-failure step=old error=old\n".'sensitive'],
        'malformed gateway line' => [
            'converge-gateway.sh',
            71,
            "Gateway bootstrap failed at step [wireguard-server-install] with error [vpn.server_config_install_failed]\n"
                .'sensitive',
        ],
        'malformed app line' => [
            'converge-app-dev.sh',
            1,
            "Node provisioning failed at step [base-packages] with error [node.package_install_failed]\n".'sensitive',
        ],
    ]);
});
