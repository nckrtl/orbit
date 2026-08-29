<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\State\OperationJournal;
use App\E2E\State\SecretRedactor;
use App\E2E\State\StatePaths;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\OperationId;
use Illuminate\Container\Container;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

beforeEach(function () {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    /** @mago-expect analysis:possibly-invalid-argument The process facade only needs the container contract in unit tests. */
    Facade::setFacadeApplication($container);
});

function incusCommand(string ...$arguments): array
{
    return ['incus', '--project', 'orbit', ...$arguments];
}

function incusTarget(string $identity = ''): string
{
    return 'lab:'.$identity;
}

function isIncusGuestBatchHelper(PendingProcess $process): bool
{
    return (
        is_array($process->command)
        && count($process->command) === 2
        && ($process->command[0] ?? null) === 'python3'
        && is_string($process->command[1] ?? null)
        && str_ends_with($process->command[1], '/resources/host/exec-all.py')
    );
}

function isDirectIncusGuestCommand(PendingProcess $process): bool
{
    return (
        is_array($process->command)
        && ($process->command[0] ?? null) === 'incus'
        && in_array('exec', $process->command, true)
    );
}

/** @return array<string, array{instance:string, command:GuestCommand}> */
function incusGuestBatchCommands(): array
{
    return [
        'first' => [
            'instance' => 'orbit-e2e-nck-123-gateway',
            'command' => new GuestCommand(['probe-one']),
        ],
        'second' => [
            'instance' => 'orbit-e2e-nck-123-gateway',
            'command' => new GuestCommand(['probe-two']),
        ],
    ];
}

/**
 * @param callable(array{label:string,instance:string,argv:list<string>}): array{stdout:string,stderr:string,exit_code:int} $result
 */
function incusGuestHelperResult(PendingProcess $process, callable $result): ProcessResult
{
    $payload = json_decode((string) $process->input, true, 512, JSON_THROW_ON_ERROR);
    $results = [];
    foreach ($payload['requests'] as $request) {
        $results[] = ['label' => $request['label'], ...$result($request)];
    }

    return Process::result(json_encode($results, JSON_THROW_ON_ERROR));
}

/** @mago-expect lint:excessive-parameter-list VM fixture fields stay explicit for exact inventory cases. */
function vmJson(
    string $name = 'orbit-e2e-nck-123-gateway',
    string $owner = 'orbit-e2e',
    string $type = 'virtual-machine',
    string $pool = 'orbit-e2e',
    ?string $network = null,
    ?string $mac = null,
): string {
    $devices = ['root' => ['pool' => $pool]];
    if ($network !== null || $mac !== null) {
        $devices['eth0'] = array_filter(
            [
                'network' => $network,
                'hwaddr' => $mac,
            ],
            static fn (?string $value): bool => $value !== null,
        );
    }

    return json_encode([[
        'name' => $name,
        'type' => $type,
        'status' => 'Stopped',
        'status_code' => 102,
        'config' => ['user.orbit.e2e.owner' => $owner],
        'devices' => $devices,
    ]], JSON_THROW_ON_ERROR);
}

function snapshotJson(string $name, string $owner = 'orbit-e2e'): string
{
    return json_encode([[
        'name' => $name,
        'config' => ['user.orbit.e2e.owner' => $owner],
    ]], JSON_THROW_ON_ERROR);
}

function incusHost(
    ?SecretRedactor $redactor = null,
    ?OperationJournal $journal = null,
    ?OperationId $operationId = null,
): IncusHost {
    return new IncusHost(
        remote: 'lab',
        project: 'orbit',
        pool: 'orbit-e2e',
        redactor: $redactor ?? new SecretRedactor,
        journal: $journal,
        operationId: $operationId,
    );
}

describe('IncusHost reads', function () {
    it('reads an exact instance set from one inventory request', function () {
        $reads = 0;
        /** @mago-expect lint:cyclomatic-complexity,kan-defect Inventory process responses stay in one exact boundary fixture. */
        Process::fake(function (PendingProcess $process) use (&$reads) {
            if (
                array_filter(
                    $process->command,
                    static fn (mixed $part): bool => is_string($part)
                    && str_ends_with($part, 'exec-all.py'),
                ) !== []
            ) {
                $payload = json_decode((string) $process->input, true, 512, JSON_THROW_ON_ERROR);

                return Process::result(json_encode(array_map(static fn (array $request): array => [
                    'label' => $request['label'],
                    'stdout' => "ready\n",
                    'stderr' => '',
                    'exit_code' => 0,
                ], $payload['requests']), JSON_THROW_ON_ERROR));
            }
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                $reads++;

                return Process::result(json_encode([
                    json_decode(vmJson('orbit-e2e-nck-123-gateway'), true, 16, JSON_THROW_ON_ERROR)[0],
                    json_decode(vmJson('orbit-e2e-nck-123-app-dev'), true, 16, JSON_THROW_ON_ERROR)[0],
                    json_decode(vmJson('orbit-e2e-other-gateway'), true, 16, JSON_THROW_ON_ERROR)[0],
                ], JSON_THROW_ON_ERROR));
            }

            return Process::result('', 'Unexpected command.', 1);
        });

        $instances = incusHost()->instances([
            'orbit-e2e-nck-123-gateway',
            'orbit-e2e-nck-123-app-dev',
        ]);

        expect(array_keys($instances))
            ->toBe(['orbit-e2e-nck-123-gateway', 'orbit-e2e-nck-123-app-dev'])
            ->and($reads)
            ->toBe(1);
    });

    it('reads all network identities and configurations in one inventory request', function () {
        Process::fake(function (PendingProcess $process) {
            if ($process->command !== incusCommand('network', 'list', incusTarget(), '--format=json')) {
                return Process::result('', 'Unexpected command.', 1);
            }

            return Process::result(json_encode([
                [
                    'name' => 'oe-b32d6c83af72',
                    'config' => ['ipv4.address' => '10.20.30.1/24'],
                ],
                [
                    'name' => 'oe-9c9ad027b058',
                    'config' => ['ipv4.address' => '10.20.31.1/24'],
                ],
            ], JSON_THROW_ON_ERROR));
        });

        $networks = incusHost()->networks();

        expect(array_keys($networks))
            ->toBe(['oe-b32d6c83af72', 'oe-9c9ad027b058'])
            ->and($networks['oe-b32d6c83af72']->config['ipv4.address'])
            ->toBe('10.20.30.1/24');
    });

    it('reads guest IPv4 addresses in one owned inventory and one parallel probe phase', function () {
        $inventoryReads = 0;
        Process::fake(function (PendingProcess $process) use (&$inventoryReads) {
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                $inventoryReads++;

                return Process::result(json_encode([
                    json_decode(vmJson('orbit-e2e-nck-123-gateway'), true, 16, JSON_THROW_ON_ERROR)[0],
                    json_decode(vmJson('orbit-e2e-nck-123-app-dev'), true, 16, JSON_THROW_ON_ERROR)[0],
                    json_decode(vmJson('orbit-e2e-nck-123-app-prod'), true, 16, JSON_THROW_ON_ERROR)[0],
                ], JSON_THROW_ON_ERROR));
            }

            return incusGuestHelperResult($process, static function (array $request): array {
                $address = match (true) {
                    str_contains($request['instance'], 'gateway') => '192.0.2.10',
                    str_contains($request['instance'], 'app-dev') => '192.0.2.11',
                    default => '192.0.2.12',
                };

                return [
                    'stdout' => "2: eth0 inet {$address}/24 scope global eth0\n",
                    'stderr' => '',
                    'exit_code' => 0,
                ];
            });
        });

        $addresses = incusHost()->globalIpv4All([
            'gateway' => 'orbit-e2e-nck-123-gateway',
            'app-dev' => 'orbit-e2e-nck-123-app-dev',
            'app-prod' => 'orbit-e2e-nck-123-app-prod',
        ]);

        expect($addresses)
            ->toBe([
                'gateway' => '192.0.2.10',
                'app-dev' => '192.0.2.11',
                'app-prod' => '192.0.2.12',
            ])
            ->and($inventoryReads)
            ->toBe(1);
        Process::assertRanTimes(isIncusGuestBatchHelper(...), 1);
        Process::assertNotRan(isDirectIncusGuestCommand(...));
    });

    it('ignores addresses outside the asserted eth0 topology NIC', function () {
        Process::fake(function (PendingProcess $process) {
            if (in_array('list', $process->command, true)) {
                return Process::result(vmJson());
            }

            return incusGuestHelperResult($process, static fn (): array => [
                'stdout' => "3: wg0    inet 10.0.0.1/24 scope global wg0\n4: docker0    inet 172.17.0.1/16 scope global docker0\n5: br-abcd    inet 172.18.0.1/16 scope global br-abcd\n2: eth0    inet 192.0.2.44/24 scope global eth0\n",
                'stderr' => '',
                'exit_code' => 0,
            ]);
        });

        expect(incusHost()->globalIpv4('orbit-e2e-nck-123-gateway'))->toBe('192.0.2.44');
    });

    it('fails when the IPv4 probe fails', function () {
        Process::fake(function (PendingProcess $process) {
            if (in_array('list', $process->command, true)) {
                return Process::result(vmJson());
            }

            return incusGuestHelperResult($process, static fn (): array => [
                'stdout' => '',
                'stderr' => 'probe failed',
                'exit_code' => 1,
            ]);
        });
        expect(fn () => incusHost()->globalIpv4('orbit-e2e-nck-123-gateway'))
            ->toThrow(RuntimeException::class, 'Failed to read global IPv4 address');
    });

    it('fails when no usable IPv4 address exists', function () {
        Process::fake(function (PendingProcess $process) {
            if (in_array('list', $process->command, true)) {
                return Process::result(vmJson());
            }

            return incusGuestHelperResult($process, static fn (): array => [
                'stdout' => "3: wg0    inet 10.0.0.1/24 scope global wg0\n",
                'stderr' => '',
                'exit_code' => 0,
            ]);
        });
        expect(fn () => incusHost()->globalIpv4('orbit-e2e-nck-123-gateway'))
            ->toThrow(RuntimeException::class, 'has no usable global IPv4 address');
    });

    it('fails closed when eth0 has more than one IPv4 address', function () {
        Process::fake(function (PendingProcess $process) {
            if (in_array('list', $process->command, true)) {
                return Process::result(vmJson());
            }

            return incusGuestHelperResult($process, static fn (): array => [
                'stdout' =>
                    "2: eth0    inet 192.0.2.44/24 scope global eth0\n"
                        ."2: eth0    inet 198.51.100.44/24 scope global eth0\n",
                'stderr' => '',
                'exit_code' => 0,
            ]);
        });

        expect(fn () => incusHost()->globalIpv4('orbit-e2e-nck-123-gateway'))
            ->toThrow(RuntimeException::class, 'does not have exactly one usable global IPv4 address');
    });

    it('only excludes loopback and current WireGuard interfaces when checking global IPv4', function () {
        $host = incusHost();
        $method = new ReflectionMethod($host, 'hasUsableGlobalIpv4');

        $virtualAddresses = implode("\n", [
            '1: lo    inet 127.0.0.1/8',
            '2: wg-orbit    inet 10.0.0.1/24',
            '3: wg0    inet 10.0.0.2/24',
            '4: docker0    inet 10.0.0.3/24',
            '5: docker_gwbridge    inet 10.0.0.4/24',
            '6: br-c9536c1    inet 10.0.0.5/24',
            '7: veth5521    inet 10.0.0.6/24',
        ]);

        expect($method->invoke($host, $virtualAddresses))->toBeFalse();
    });

    it('assigns the legacy deterministic Incus MAC address', function () {
        Process::fake(function (PendingProcess $process) {
            return match ($process->command) {
                incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json') => Process::result(
                    vmJson(),
                ),
                incusCommand(
                    'config',
                    'device',
                    'override',
                    incusTarget('orbit-e2e-nck-123-gateway'),
                    'eth0',
                    'hwaddr=00:16:3e:5e:4b:52',
                )
                    => Process::result(''),
                default => Process::result('', 'Unexpected command.', 1),
            };
        });

        incusHost()->setDeterministicMac('orbit-e2e-nck-123-gateway', 'run', 'gateway');

        Process::assertRan(incusCommand(
            'config',
            'device',
            'override',
            incusTarget('orbit-e2e-nck-123-gateway'),
            'eth0',
            'hwaddr=00:16:3e:5e:4b:52',
        ));
    });

    it('configures cloned networks and deterministic MACs in one validated parallel phase', function () {
        $instanceReads = 0;
        $networkReads = 0;
        Process::fake(function (PendingProcess $process) use (&$instanceReads, &$networkReads) {
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                $instanceReads++;

                return Process::result(json_encode(array_map(
                    static fn (string $role): array => json_decode(
                        vmJson("orbit-e2e-nck-123-{$role}"),
                        true,
                        16,
                        JSON_THROW_ON_ERROR,
                    )[0],
                    ['gateway', 'app-dev', 'app-prod'],
                ), JSON_THROW_ON_ERROR));
            }
            if (array_slice($process->command, 3, 2) === ['network', 'list']) {
                $networkReads++;

                return Process::result(json_encode([[
                    'name' => 'oe-b32d6c83af72',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR));
            }

            return Process::result('2: eth0 inet 192.0.2.1/24 scope global eth0');
        });

        incusHost()->configureCloneNetworks([
            'gateway' => 'orbit-e2e-nck-123-gateway',
            'app-dev' => 'orbit-e2e-nck-123-app-dev',
            'app-prod' => 'orbit-e2e-nck-123-app-prod',
        ], 'oe-b32d6c83af72');

        expect($instanceReads)
            ->toBe(1)
            ->and($networkReads)
            ->toBe(1);
        foreach (['gateway', 'app-dev', 'app-prod'] as $role) {
            $hash = substr(sha1("oe-b32d6c83af72:{$role}"), 0, 6);
            $mac = '00:16:3e:'.implode(':', str_split($hash, 2));
            Process::assertRan(incusCommand(
                'config',
                'device',
                'override',
                incusTarget('orbit-e2e-nck-123-'.$role),
                'eth0',
                'network=oe-b32d6c83af72',
                "hwaddr={$mac}",
            ));
        }
    });

    it('validates owned topology network and MAC identity from one instance inventory', function () {
        $network = 'oe-b32d6c83af72';
        $resources = [];
        foreach (['gateway', 'app-dev', 'app-prod'] as $role) {
            $hash = substr(sha1("{$network}:{$role}"), 0, 6);
            $mac = '00:16:3e:'.implode(':', str_split($hash, 2));
            $resources[] = json_decode(
                vmJson(
                    "orbit-e2e-nck-123-{$role}",
                    network: $network,
                    mac: $mac,
                ),
                true,
                16,
                JSON_THROW_ON_ERROR,
            )[0];
        }
        $reads = 0;
        Process::fake(function (PendingProcess $process) use ($resources, &$reads) {
            if ($process->command === incusCommand('network', 'list', incusTarget(), '--format=json')) {
                return Process::result(json_encode([[
                    'name' => 'oe-b32d6c83af72',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR));
            }

            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                $reads++;

                return Process::result(json_encode($resources, JSON_THROW_ON_ERROR));
            }

            return Process::result('', 'Unexpected command.', 1);
        });

        incusHost()->assertTopologyNetworkIdentity([
            'gateway' => 'orbit-e2e-nck-123-gateway',
            'app-dev' => 'orbit-e2e-nck-123-app-dev',
            'app-prod' => 'orbit-e2e-nck-123-app-prod',
        ], $network);

        expect($reads)->toBe(1);
    });

    it('rejects a topology instance whose deterministic MAC does not match', function () {
        Process::fake(function (PendingProcess $process) {
            if ($process->command === incusCommand('network', 'list', incusTarget(), '--format=json')) {
                return Process::result(json_encode([[
                    'name' => 'oe-b32d6c83af72',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR));
            }

            if ($process->command !== incusCommand('list', incusTarget(), '--format=json')) {
                return Process::result('', 'Unexpected command.', 1);
            }

            return Process::result(vmJson(
                'orbit-e2e-nck-123-gateway',
                network: 'oe-b32d6c83af72',
                mac: '00:16:3e:00:00:00',
            ));
        });

        expect(fn () => incusHost()->assertTopologyNetworkIdentity([
            'gateway' => 'orbit-e2e-nck-123-gateway',
        ], 'oe-b32d6c83af72'))
            ->toThrow(RuntimeException::class, 'MAC identity does not match topology');
    });

    it('rejects topology identity on a network that Orbit does not own', function () {
        $network = 'oe-b32d6c83af72';
        $hash = substr(sha1("{$network}:gateway"), 0, 6);
        $mac = '00:16:3e:'.implode(':', str_split($hash, 2));
        Process::fake(function (PendingProcess $process) use ($network, $mac) {
            return match ($process->command) {
                incusCommand('list', incusTarget(), '--format=json') => Process::result(vmJson(
                    'orbit-e2e-nck-123-gateway',
                    network: $network,
                    mac: $mac,
                )),
                incusCommand('network', 'list', incusTarget(), '--format=json') => Process::result(json_encode([[
                    'name' => $network,
                    'config' => ['user.orbit.e2e.owner' => 'someone-else'],
                ]], JSON_THROW_ON_ERROR)),
                default => Process::result('', 'Unexpected command.', 1),
            };
        });

        expect(fn () => incusHost()->assertTopologyNetworkIdentity([
            'gateway' => 'orbit-e2e-nck-123-gateway',
        ], $network))
            ->toThrow(RuntimeException::class, 'network oe-b32d6c83af72 ownership metadata does not match');
    });

    it('attaches a network only with the deterministic topology MAC', function () {
        Process::fake(function (PendingProcess $process) {
            return match ($process->command) {
                incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json') => Process::result(
                    vmJson(),
                ),
                incusCommand('network', 'list', incusTarget(), '--format=json') => Process::result(json_encode([[
                    'name' => 'oe-b32d6c83af72',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR)),
                default => Process::result(),
            };
        });

        incusHost()->setNetwork(
            'orbit-e2e-nck-123-gateway',
            'oe-b32d6c83af72',
            'gateway',
        );

        Process::assertRan(incusCommand(
            'config',
            'device',
            'override',
            incusTarget('orbit-e2e-nck-123-gateway'),
            'eth0',
            'network=oe-b32d6c83af72',
            'hwaddr=00:16:3e:a1:e4:eb',
        ));
    });

    it('reads exact VM, network, and image identities as JSON', function () {
        $fingerprint = str_repeat('a', 64);
        Process::fake(function (PendingProcess $process) use ($fingerprint) {
            return match ($process->command) {
                incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json') => Process::result(
                    vmJson(),
                ),
                incusCommand('list', incusTarget('orbit-e2e-standby-gateway'), '--format=json') => Process::result(
                    vmJson('orbit-e2e-standby-gateway'),
                ),
                incusCommand('network', 'list', incusTarget(), '--format=json') => Process::result(json_encode([
                    ['name' => 'orbit-e2e-nck-123', 'config' => ['user.orbit.e2e.owner' => 'orbit-e2e']],
                ], JSON_THROW_ON_ERROR)),
                incusCommand('image', 'list', 'images:', 'ubuntu/24.04', '--format=json')
                    => Process::result(json_encode([[
                    'fingerprint' => $fingerprint,
                    'type' => 'virtual-machine',
                    'aliases' => [['name' => 'ubuntu/24.04']],
                ]], JSON_THROW_ON_ERROR)),
                default => Process::result('', 'Unexpected command.', 1),
            };
        });

        $host = incusHost();
        $instance = $host->instance('orbit-e2e-nck-123-gateway');

        expect($instance)
            ->toBeInstanceOf(IncusInstance::class)
            ->and($instance?->pool)
            ->toBe('orbit-e2e')
            ->and($instance?->isStopped())
            ->toBeTrue()
            ->and($host->network('orbit-e2e-nck-123')?->name)
            ->toBe('orbit-e2e-nck-123')
            ->and($host->imageFingerprint('images:ubuntu/24.04'))
            ->toBe($fingerprint);
    });

    it('returns null when an exact identity is absent', function () {
        Process::fake([
            '*' => Process::result(json_encode([
                ['name' => 'orbit-e2e-nck-123-app-dev', 'type' => 'virtual-machine'],
            ], JSON_THROW_ON_ERROR)),
        ]);

        expect(incusHost()->instance('orbit-e2e-nck-123-gateway'))->toBeNull();
    });

    it('rejects malformed network configuration', function (array $configuration) {
        Process::fake([
            '*' => Process::result(json_encode([[
                'name' => 'orbit-e2e-nck-123',
                'config' => $configuration,
            ]], JSON_THROW_ON_ERROR)),
        ]);

        expect(fn () => incusHost()->network('orbit-e2e-nck-123'))
            ->toThrow(RuntimeException::class, 'Incus returned invalid resource configuration.');
    })->with([
        'numeric key' => [['ipv4.nat']],
        'non-string value' => [['ipv4.nat' => true]],
    ]);

    it('resolves an exact local VM image alias on the configured remote', function () {
        $fingerprint = str_repeat('b', 64);
        Process::fake(function (PendingProcess $process) use ($fingerprint) {
            expect($process->command)->toBe(incusCommand(
                'image',
                'list',
                incusTarget(),
                'orbit-base',
                '--format=json',
            ));

            return Process::result(json_encode([[
                'fingerprint' => $fingerprint,
                'type' => 'virtual-machine',
                'aliases' => [['name' => 'orbit-base']],
            ]], JSON_THROW_ON_ERROR));
        });

        expect(incusHost()->imageFingerprint('orbit-base'))->toBe($fingerprint);
    });

    it('passes an external image alias as the exact init image operand', function () {
        Process::fake(function (PendingProcess $process) {
            expect($process->command)->toBe(incusCommand(
                'init',
                'images:ubuntu/26.04',
                incusTarget('orbit-e2e-nck-123-gateway'),
                '--vm',
                '--storage',
                'orbit-e2e',
                '--config',
                'limits.cpu=1',
                '--config',
                'limits.memory=2GiB',
                '--device',
                'root,pool=orbit-e2e,size=16GiB',
                '--network',
                'orbit-e2e-nck-123',
                '--config',
                'user.orbit.e2e.owner=orbit-e2e',
            ));

            return Process::result();
        });

        incusHost()->initVm('images:ubuntu/26.04', 'orbit-e2e-nck-123-gateway', 'orbit-e2e-nck-123');
    });

    it('accepts validated creation metadata without allowing ownership override', function () {
        Process::fake(function (PendingProcess $process) {
            expect($process->command)
                ->toContain('--config', 'user.orbit.e2e.operation=op-1', '--config', 'user.orbit.e2e.evidence=ev-1');

            return Process::result();
        });

        incusHost()->initVm('images:ubuntu/26.04', 'orbit-e2e-nck-123-gateway', 'orbit-e2e-nck-123', [
            'user.orbit.e2e.operation' => 'op-1',
            'user.orbit.e2e.evidence' => 'ev-1',
        ]);
    });

    it('rejects creation metadata that overrides ownership', function () {
        expect(fn () => incusHost()->initVm('orbit-base', 'orbit-e2e-nck-123-gateway', 'orbit-e2e-nck-123', [
            'user.orbit.e2e.owner' => 'attacker',
        ]))->toThrow(RuntimeException::class, 'cannot override ownership metadata');
    });

    it('rejects missing or contradictory VM power status', function () {
        Process::fake(['*' => Process::result(str_replace('"status_code":102', '"status_code":103', vmJson()))]);

        expect(fn () => incusHost()->instance('orbit-e2e-nck-123-gateway'))
            ->toThrow(InvalidArgumentException::class, 'power status');

        Process::fake(['*' => Process::result(str_replace(
            ['"status":"Stopped",', '"status_code":102,'],
            '',
            vmJson(),
        ))]);

        expect(fn () => incusHost()->instance('orbit-e2e-nck-123-gateway'))
            ->toThrow(RuntimeException::class, 'no valid power status');
    });

    it('rejects malformed JSON and wrong instance types', function () {
        Process::fake(['*' => Process::result('{')]);
        expect(fn () => incusHost()->instance('orbit-e2e-nck-123-gateway'))
            ->toThrow(RuntimeException::class, 'malformed JSON');

        Process::fake(['*' => Process::result(vmJson(type: 'container'))]);
        expect(fn () => incusHost()->instance('orbit-e2e-nck-123-gateway'))
            ->toThrow(RuntimeException::class, 'not a virtual machine');
    });

    it('rejects an instance from a different storage pool', function () {
        Process::fake(['*' => Process::result(vmJson(pool: 'unrelated'))]);

        expect(fn () => incusHost()->instance('orbit-e2e-nck-123-gateway'))
            ->toThrow(RuntimeException::class, 'storage pool identity does not match');
    });
});

describe('IncusHost network creation', function () {
    it('rejects names longer than the Linux bridge interface limit before invoking Incus', function () {
        $name = 'orbit-e2e-network-too-long';

        expect(fn () => incusHost()->createNetwork($name, []))
            ->toThrow(RuntimeException::class, 'Incus network names must be 15 ASCII characters or fewer.');

        Process::assertNothingRan();
    });
});

/** @mago-expect lint:cyclomatic-complexity,kan-defect Mutation cases share one explicit process contract. */
describe('IncusHost mutations', function () {
    it('rejects duplicate VM targets before starting any process', function () {
        Process::fake();
        $vm = [
            'image' => 'orbit-base',
            'name' => 'orbit-e2e-duplicate',
            'network' => 'oe-standby',
            'role' => 'gateway',
            'topology' => 'oe-standby',
            'metadata' => [],
        ];
        expect(fn () => incusHost()->initVms(['one' => $vm, 'two' => [...$vm, 'role' => 'app-dev']]))
            ->toThrow(RuntimeException::class, 'targets must be unique');
        Process::assertNothingRan();
    });

    it('does not reuse cached ownership for cloned host reset', function () {
        $reads = 0;
        Process::fake(function (PendingProcess $process) use (&$reads) {
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                $reads++;

                return Process::result(vmJson(owner: $reads === 1 ? 'orbit-e2e' : 'other'));
            }

            return Process::result();
        });
        $host = incusHost(
            journal: new OperationJournal(new StatePaths(sys_get_temp_dir().'/orbit-test')),
            operationId: new OperationId(str_repeat('a', 32)),
        );
        $host->instances(['orbit-e2e-nck-123-gateway']);
        expect(fn () => $host->resetClonedHostState('orbit-e2e-nck-123-gateway'))
            ->toThrow(RuntimeException::class, 'ownership metadata');
        expect($reads)->toBe(2);
    });

    it('force-stops every running target when graceful pool throws', function () {
        Process::fake(function (PendingProcess $process) {
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                return Process::result(json_encode([
                    json_decode(
                        str_replace(
                            ['"status":"Stopped"', '"status_code":102'],
                            ['"status":"Running"', '"status_code":103'],
                            vmJson('orbit-e2e-nck-123-gateway'),
                        ),
                        true,
                    )[0],
                    json_decode(
                        str_replace(
                            ['"status":"Stopped"', '"status_code":102'],
                            ['"status":"Running"', '"status_code":103'],
                            vmJson('orbit-e2e-nck-123-app-dev'),
                        ),
                        true,
                    )[0],
                ], JSON_THROW_ON_ERROR));
            }
            if (in_array('stop', $process->command, true) && ! in_array('--force', $process->command, true)) {
                throw new RuntimeException('graceful pool timeout');
            }

            return Process::result();
        });
        incusHost()->stopAll(['orbit-e2e-nck-123-gateway', 'orbit-e2e-nck-123-app-dev']);
        Process::assertRan(incusCommand('stop', incusTarget('orbit-e2e-nck-123-gateway'), '--force'));
        Process::assertRan(incusCommand('stop', incusTarget('orbit-e2e-nck-123-app-dev'), '--force'));
    });

    it('initializes standby VMs in parallel with deterministic topology MAC addresses', function () {
        Process::fake(['*' => Process::result()]);
        $host = incusHost();

        $instances = $host->initVms(array_combine(
            ['gateway', 'app-dev', 'app-prod'],
            array_map(static fn (string $role): array => [
                'image' => 'orbit-base',
                'name' => "orbit-e2e-standby-{$role}",
                'network' => 'oe-standby',
                'role' => $role,
                'topology' => 'oe-standby',
                'metadata' => ['user.orbit.e2e.operation' => str_repeat('a', 32)],
            ], ['gateway', 'app-dev', 'app-prod']),
        ));

        expect(array_keys($instances))->toBe(['gateway', 'app-dev', 'app-prod']);
        foreach (['gateway', 'app-dev', 'app-prod'] as $role) {
            $hash = substr(sha1("oe-standby:{$role}"), 0, 6);
            $mac = '00:16:3e:'.implode(':', str_split($hash, 2));
            Process::assertRan(incusCommand(
                'init',
                incusTarget('orbit-base'),
                incusTarget("orbit-e2e-standby-{$role}"),
                '--vm',
                '--storage',
                'orbit-e2e',
                '--config',
                'limits.cpu=1',
                '--config',
                'limits.memory=2GiB',
                '--device',
                'root,pool=orbit-e2e,size=16GiB',
                '--device',
                "eth0,network=oe-standby,hwaddr={$mac}",
                '--config',
                'user.orbit.e2e.owner=orbit-e2e',
                '--config',
                'user.orbit.e2e.operation='.str_repeat('a', 32),
            ));
        }
    });

    it('regenerates cloned guest network identity with a fixed script', function () {
        Process::fake(function (PendingProcess $process) {
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                return Process::result(vmJson());
            }

            expect($process->command)->toBe(incusCommand(
                'exec',
                incusTarget('orbit-e2e-nck-123-gateway'),
                '--',
                'sh',
                '-c',
                'rm -f /etc/machine-id /var/lib/dbus/machine-id && systemd-machine-id-setup && systemctl restart systemd-journald && for directory in /run/systemd/netif/leases /var/lib/systemd/network; do if [ -e "$directory" ]; then [ -d "$directory" ] && [ ! -L "$directory" ] || exit 1; find "$directory" -mindepth 1 -maxdepth 1 -type f -delete || exit 1; fi; done && ip -4 addr flush dev eth0 scope global && (systemctl restart systemd-networkd || systemctl restart NetworkManager)',
            ));

            return Process::result();
        });

        incusHost()->resetClonedHostState('orbit-e2e-nck-123-gateway');

        Process::assertRan(incusCommand(
            'exec',
            incusTarget('orbit-e2e-nck-123-gateway'),
            '--',
            'sh',
            '-c',
            'rm -f /etc/machine-id /var/lib/dbus/machine-id && systemd-machine-id-setup && systemctl restart systemd-journald && for directory in /run/systemd/netif/leases /var/lib/systemd/network; do if [ -e "$directory" ]; then [ -d "$directory" ] && [ ! -L "$directory" ] || exit 1; find "$directory" -mindepth 1 -maxdepth 1 -type f -delete || exit 1; fi; done && ip -4 addr flush dev eth0 scope global && (systemctl restart systemd-networkd || systemctl restart NetworkManager)',
        ));
    });

    it('fails when guest network identity regeneration fails', function () {
        Process::fake(function (PendingProcess $process) {
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                return Process::result(vmJson());
            }

            return Process::result('', 'restart failed', 1);
        });

        expect(fn () => incusHost()->resetClonedHostState('orbit-e2e-nck-123-gateway'))
            ->toThrow(RuntimeException::class, 'Failed to reset cloned host state');
    });

    it('rejects a non-positive guest readiness timeout', function () {
        expect(fn () => new IncusHost(guestReadinessTimeoutSeconds: 0))
            ->toThrow(InvalidArgumentException::class, 'Guest readiness timeout must be positive.');
    });

    it('does not start an already running owned VM', function () {
        Process::fake(function (PendingProcess $process) {
            return match ($process->command) {
                incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json') => Process::result(
                    str_replace(
                        ['"status":"Stopped"', '"status_code":102'],
                        ['"status":"Running"', '"status_code":103'],
                        vmJson(),
                    ),
                ),
                default => Process::result('[]'),
            };
        });

        incusHost()->start('orbit-e2e-nck-123-gateway');

        Process::assertNotRan(incusCommand('start', incusTarget('orbit-e2e-nck-123-gateway')));
    });

    it('force-stops the exact owned VM when graceful stop fails', function () {
        Process::fake(function (PendingProcess $process) {
            return match ($process->command) {
                incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json') => Process::result(
                    vmJson(),
                ),
                incusCommand('stop', incusTarget('orbit-e2e-nck-123-gateway')) => Process::result(
                    '',
                    'graceful stop failed',
                    1,
                ),
                incusCommand('stop', incusTarget('orbit-e2e-nck-123-gateway'), '--force') => Process::result(),
                default => Process::result('', 'Unexpected command.', 1),
            };
        });

        incusHost()->stop('orbit-e2e-nck-123-gateway');

        Process::assertRanInOrder([
            incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json'),
            incusCommand('stop', incusTarget('orbit-e2e-nck-123-gateway')),
            incusCommand('stop', incusTarget('orbit-e2e-nck-123-gateway'), '--force'),
        ]);
    });

    it('exposes the forced stop failure when graceful stop also fails', function () {
        Process::fake(function (PendingProcess $process) {
            return match ($process->command) {
                incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json') => Process::result(
                    vmJson(),
                ),
                incusCommand('stop', incusTarget('orbit-e2e-nck-123-gateway')) => Process::result(
                    '',
                    'graceful stop failed',
                    1,
                ),
                incusCommand('stop', incusTarget('orbit-e2e-nck-123-gateway'), '--force') => Process::result(
                    '',
                    'forced stop failed',
                    1,
                ),
                default => Process::result('', 'Unexpected command.', 1),
            };
        });

        expect(fn () => incusHost()->stop('orbit-e2e-nck-123-gateway'))
            ->toThrow(RuntimeException::class, 'forced stop failed');
    });

    it('force-stops only guests whose parallel graceful stop failed', function () {
        $inventoryReads = 0;
        Process::fake(function (PendingProcess $process) use (&$inventoryReads) {
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                $inventoryReads++;

                return Process::result(json_encode(array_map(
                    static fn (string $name): array => json_decode(
                        str_replace(
                            ['"status":"Stopped"', '"status_code":102'],
                            $inventoryReads === 1 || $name === 'orbit-e2e-nck-123-app-dev'
                                ? ['"status":"Running"', '"status_code":103']
                                : ['"status":"Stopped"', '"status_code":102'],
                            vmJson($name),
                        ),
                        true,
                        16,
                        JSON_THROW_ON_ERROR,
                    )[0],
                    ['orbit-e2e-nck-123-gateway', 'orbit-e2e-nck-123-app-dev'],
                ), JSON_THROW_ON_ERROR));
            }

            return match ($process->command) {
                incusCommand('stop', incusTarget('orbit-e2e-nck-123-gateway')) => Process::result(),
                incusCommand('stop', incusTarget('orbit-e2e-nck-123-app-dev')) => Process::result(
                    '',
                    'graceful stop failed',
                    1,
                ),
                incusCommand('stop', incusTarget('orbit-e2e-nck-123-app-dev'), '--force') => Process::result(),
                default => Process::result('', 'Unexpected command.', 1),
            };
        });

        incusHost()->stopAll([
            'orbit-e2e-nck-123-gateway',
            'orbit-e2e-nck-123-app-dev',
        ]);

        expect($inventoryReads)->toBe(2);
        Process::assertRan(incusCommand(
            'stop',
            incusTarget('orbit-e2e-nck-123-app-dev'),
            '--force',
        ));
        Process::assertNotRan(incusCommand(
            'stop',
            incusTarget('orbit-e2e-nck-123-gateway'),
            '--force',
        ));
    });

    it('retries guest readiness after starting until the agent succeeds', function () {
        $probes = 0;
        Process::fake(function (PendingProcess $process) use (&$probes) {
            return match ($process->command) {
                incusCommand('list', incusTarget(), '--format=json') => Process::result(
                    vmJson(),
                ),
                incusCommand('exec', incusTarget('orbit-e2e-nck-123-gateway'), '--', '/bin/true') => ++$probes === 1
                    ? Process::result('', 'agent not ready', 1)
                    : Process::result(),
                default => Process::result('[]'),
            };
        });

        incusHost()->waitForAgents(['orbit-e2e-nck-123-gateway']);

        expect($probes)->toBe(2);
        Process::assertRan(incusCommand(
            'exec',
            incusTarget('orbit-e2e-nck-123-gateway'),
            '--',
            '/bin/true',
        ));
    });

    it('retries guest readiness after a transient probe exception', function () {
        $probes = 0;
        Process::fake(function (PendingProcess $process) use (&$probes) {
            if (
                $process->command === incusCommand(
                    'list',
                    incusTarget(),
                    '--format=json',
                )
            ) {
                return Process::result(vmJson());
            }

            if (++$probes === 1) {
                throw new RuntimeException('The guest agent probe timed out.');
            }

            return Process::result();
        });

        incusHost()->waitForAgents(['orbit-e2e-nck-123-gateway']);

        expect($probes)->toBe(2);
    });

    it('fails at the readiness deadline when the guest readiness process cannot run', function () {
        $probes = 0;
        Process::fake(function (PendingProcess $process) use (&$probes) {
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                return Process::result(vmJson());
            }

            $probes++;
            throw new RuntimeException('incus unavailable');
        });

        expect(fn () => new IncusHost(
            remote: 'lab',
            project: 'orbit',
            pool: 'orbit-e2e',
            guestReadinessTimeoutSeconds: 1,
        )
            ->waitForAgents(['orbit-e2e-nck-123-gateway']))
            ->toThrow(RuntimeException::class, 'Timed out waiting for Incus guest agents')
            ->and($probes)
            ->toBe(1);
    });

    it('rejects empty and duplicate readiness instance lists before probing', function (array $instances) {
        expect(fn () => incusHost()->waitForAgents($instances))
            ->toThrow(RuntimeException::class, 'non-empty and unique');
        Process::assertNothingRan();
    })->with([
        'empty' => [[]],
        'duplicate' => [['orbit-e2e-nck-123-gateway', 'orbit-e2e-nck-123-gateway']],
    ]);

    it('advances each cloned guest through readiness reset and IPv4 without a global role barrier', function () {
        $gateway = 'orbit-e2e-nck-123-gateway';
        $appDev = 'orbit-e2e-nck-123-app-dev';
        $appDevAgentProbes = 0;
        $events = [];
        Process::fake(function (PendingProcess $process) use ($gateway, $appDev, &$appDevAgentProbes, &$events) {
            $command = $process->command;
            if ($command === incusCommand('list', incusTarget(), '--format=json')) {
                $events[] = 'inventory';
                $gatewayVm = json_decode(vmJson($gateway), true, 16, JSON_THROW_ON_ERROR)[0];
                $appDevVm = json_decode(vmJson($appDev), true, 16, JSON_THROW_ON_ERROR)[0];

                return Process::result(json_encode([$gatewayVm, $appDevVm], JSON_THROW_ON_ERROR));
            }

            $instance = str_replace('lab:', '', (string) ($command[4] ?? ''));
            $guest = array_slice($command, 6);
            if ($guest === ['/bin/true']) {
                $events[] = 'agent:'.$instance;
                if ($instance === $appDev && ++$appDevAgentProbes === 1) {
                    return Process::result('', 'not ready', 1);
                }

                return Process::result();
            }
            if (($guest[0] ?? null) === 'sh' && str_contains((string) ($guest[2] ?? ''), 'systemd-machine-id-setup')) {
                $events[] = 'reset:'.$instance;

                return Process::result();
            }
            if (($guest[0] ?? null) === 'ip') {
                $events[] = 'ipv4:'.$instance;

                return Process::result("2: eth0 inet 10.44.0.10/24 scope global eth0\n");
            }

            return Process::result('', 'unexpected command', 1);
        });

        incusHost()->prepareClonedHostStates([$gateway, $appDev]);

        expect($events)->toBe([
            'inventory',
            'agent:'.$gateway,
            'agent:'.$appDev,
            'reset:'.$gateway,
            'agent:'.$appDev,
            'ipv4:'.$gateway,
            'reset:'.$appDev,
            'ipv4:'.$appDev,
        ]);
    });

    it('retries an idempotent clone identity reset after a transient pool failure', function () {
        $instance = 'orbit-e2e-nck-123-gateway';
        $resetAttempts = 0;
        Process::fake(function (PendingProcess $process) use ($instance, &$resetAttempts) {
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                return Process::result(vmJson($instance));
            }

            $guest = array_slice($process->command, 6);
            if ($guest === ['/bin/true']) {
                return Process::result();
            }
            if (($guest[0] ?? null) === 'sh') {
                if (++$resetAttempts === 1) {
                    throw new RuntimeException('transient pool failure');
                }

                return Process::result();
            }
            if (($guest[0] ?? null) === 'ip') {
                return Process::result("2: eth0 inet 10.232.2.10/24 scope global eth0\n");
            }

            return Process::result('', 'unexpected command', 1);
        });

        incusHost()->prepareClonedHostStates([$instance]);

        expect($resetAttempts)->toBe(2);
    });

    it('passes guest stdin through process input without adding it to argv', function () {
        $observedInput = null;
        $secret = 'token='.bin2hex(random_bytes(8))."\n";
        Process::fake(function (PendingProcess $process) use (&$observedInput) {
            if (
                $process->command === incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json')
            ) {
                return Process::result(vmJson());
            }
            $observedInput = $process->input;

            return Process::result();
        });

        incusHost()->exec(
            'orbit-e2e-nck-123-gateway',
            new GuestCommand(['install', '/dev/stdin', '/tmp/config'], 60, $secret),
        );

        expect($observedInput)->toBe($secret);
        Process::assertRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && in_array('install', $process->command, true)
                && ! in_array($secret, $process->command, true)
            ),
        );
    });

    it('uses argument arrays with global scope, storage, and exact identities', function () {
        Process::fake(function (PendingProcess $process) {
            return match ($process->command) {
                incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json') => Process::result(
                    vmJson(),
                ),
                incusCommand('list', incusTarget('orbit-e2e-standby-gateway'), '--format=json') => Process::result(
                    vmJson('orbit-e2e-standby-gateway'),
                ),
                incusCommand('image', 'list', incusTarget(), 'orbit-base', '--format=json') => Process::result(
                    json_encode([[
                        'fingerprint' => str_repeat('a', 64),
                        'type' => 'virtual-machine',
                        'aliases' => [['name' => 'orbit-base']],
                    ]], JSON_THROW_ON_ERROR),
                ),
                incusCommand('network', 'list', incusTarget(), '--format=json') => Process::result(json_encode([
                    ['name' => 'oe-b32d6c83af72', 'config' => ['user.orbit.e2e.owner' => 'orbit-e2e']],
                ], JSON_THROW_ON_ERROR)),
                incusCommand('snapshot', 'list', incusTarget('orbit-e2e-standby-gateway'), '--format=json')
                    => Process::result(
                    snapshotJson('main-g1'),
                ),
                default => Process::result(),
            };
        });
        $host = incusHost();

        $network = $host->createNetwork('oe-b32d6c83af72', ['ipv4.address' => '10.20.30.1/24']);
        $instance = $host->initVm('orbit-base', 'orbit-e2e-nck-123-gateway', 'oe-b32d6c83af72');
        $copy = $host->copySnapshot('orbit-e2e-standby-gateway', 'main-g1', 'orbit-e2e-nck-123-gateway', [
            'user.orbit.e2e.issue' => 'NCK-123',
            'user.orbit.e2e.generation' => 'generation-1',
            'user.orbit.e2e.operation' => 'operation-1',
        ]);
        $host->setNetwork(
            'orbit-e2e-nck-123-gateway',
            'oe-b32d6c83af72',
            'gateway',
        );
        $host->setMetadata('orbit-e2e-nck-123-gateway', ['user.orbit.e2e.issue' => 'NCK-123']);

        expect($network->name)
            ->toBe('oe-b32d6c83af72')
            ->and($network->metadata['user.orbit.e2e.owner'])
            ->toBe('orbit-e2e')
            ->and($instance->pool)
            ->toBe('orbit-e2e')
            ->and($copy->name)
            ->toBe('orbit-e2e-nck-123-gateway');

        foreach ([
            incusCommand(
                'network',
                'create',
                incusTarget('oe-b32d6c83af72'),
                'ipv4.address=10.20.30.1/24',
                'user.orbit.e2e.owner=orbit-e2e',
            ),
            incusCommand(
                'init',
                incusTarget('orbit-base'),
                incusTarget('orbit-e2e-nck-123-gateway'),
                '--vm',
                '--storage',
                'orbit-e2e',
                '--config',
                'limits.cpu=1',
                '--config',
                'limits.memory=2GiB',
                '--device',
                'root,pool=orbit-e2e,size=16GiB',
                '--network',
                'oe-b32d6c83af72',
                '--config',
                'user.orbit.e2e.owner=orbit-e2e',
            ),
            incusCommand(
                'copy',
                incusTarget('orbit-e2e-standby-gateway/main-g1'),
                incusTarget('orbit-e2e-nck-123-gateway'),
                '--storage',
                'orbit-e2e',
                '--config',
                'limits.cpu=1',
                '--config',
                'limits.memory=2GiB',
                '--device',
                'root,pool=orbit-e2e,size=16GiB',
                '--config',
                'user.orbit.e2e.owner=orbit-e2e',
                '--config',
                'user.orbit.e2e.issue=NCK-123',
                '--config',
                'user.orbit.e2e.generation=generation-1',
                '--config',
                'user.orbit.e2e.operation=operation-1',
            ),
            incusCommand(
                'config',
                'device',
                'override',
                incusTarget('orbit-e2e-nck-123-gateway'),
                'eth0',
                'network=oe-b32d6c83af72',
                'hwaddr=00:16:3e:a1:e4:eb',
            ),
            incusCommand(
                'config',
                'set',
                incusTarget('orbit-e2e-nck-123-gateway'),
                'user.orbit.e2e.issue=NCK-123',
            ),
        ] as $command) {
            Process::assertRan($command);
        }
    });

    it('rejects acquisition metadata that attempts to override ownership', function () {
        expect(fn () => incusHost()->copySnapshot(
            'orbit-e2e-standby-gateway',
            'main-g1',
            'orbit-e2e-nck-123-gateway',
            ['user.orbit.e2e.owner' => 'attacker'],
        ))
            ->toThrow(RuntimeException::class, 'cannot override ownership metadata');
    });

    it('sets metadata only on exact owned instances and networks', function (string $resource) {
        Process::fake(function (PendingProcess $process) use ($resource) {
            return match ($process->command) {
                incusCommand('list', incusTarget($resource), '--format=json') => Process::result(
                    $resource === 'orbit-e2e-nck-123-gateway' ? vmJson() : '[]',
                ),
                incusCommand('network', 'list', incusTarget(), '--format=json') => Process::result(json_encode([
                    ['name' => 'orbit-e2e-nck-123', 'config' => ['user.orbit.e2e.owner' => 'orbit-e2e']],
                ], JSON_THROW_ON_ERROR)),
                default => Process::result(),
            };
        });

        incusHost()->setMetadata($resource, ['user.orbit.e2e.issue' => 'NCK-123']);

        $command = $resource === 'orbit-e2e-nck-123-gateway'
            ? incusCommand('config', 'set', incusTarget($resource), 'user.orbit.e2e.issue=NCK-123')
            : incusCommand('network', 'set', incusTarget($resource), 'user.orbit.e2e.issue=NCK-123');
        Process::assertRan($command);
    })->with(['orbit-e2e-nck-123-gateway', 'orbit-e2e-nck-123']);

    it('does not set metadata when exact resource ownership does not match', function () {
        Process::fake(function (PendingProcess $process) {
            return match ($process->command) {
                incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json') => Process::result(
                    vmJson(owner: 'someone-else'),
                ),
                default => Process::result(),
            };
        });

        expect(fn () => incusHost()->setMetadata(
            'orbit-e2e-nck-123-gateway',
            ['user.orbit.e2e.issue' => 'NCK-123'],
        ))
            ->toThrow(RuntimeException::class, 'ownership metadata does not match');

        Process::assertDidntRun(incusCommand(
            'config',
            'set',
            incusTarget('orbit-e2e-nck-123-gateway'),
            'user.orbit.e2e.issue=NCK-123',
        ));
    });

    it('validates the exact owned network before attaching it', function () {
        Process::fake(function (PendingProcess $process) {
            return match ($process->command) {
                incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json') => Process::result(
                    vmJson(),
                ),
                incusCommand('network', 'list', incusTarget(), '--format=json') => Process::result(json_encode([
                    ['name' => 'orbit-e2e-nck-123', 'config' => ['user.orbit.e2e.owner' => 'someone-else']],
                ], JSON_THROW_ON_ERROR)),
                default => Process::result(),
            };
        });

        expect(
            fn () => incusHost()->setNetwork(
                'orbit-e2e-nck-123-gateway',
                'orbit-e2e-nck-123',
                'gateway',
            ),
        )
            ->toThrow(RuntimeException::class, 'network orbit-e2e-nck-123 ownership metadata does not match');

        Process::assertNotRan(
            fn (PendingProcess $process): bool => ($process->command[3] ?? null) === 'config',
        );
    });

    it('re-reads and verifies ownership before destructive instance calls', function () {
        Process::fake([
            '*' => Process::sequence()
                ->push(vmJson())
                ->push(Process::result()),
        ]);

        incusHost()->deleteInstance('orbit-e2e-nck-123-gateway');

        Process::assertRanInOrder([
            incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json'),
            incusCommand('delete', incusTarget('orbit-e2e-nck-123-gateway')),
        ]);
    });

    it('deletes owned instances in parallel after one inventory read and verifies absence', function () {
        $inventoryReads = 0;
        Process::fake(function (PendingProcess $process) use (&$inventoryReads) {
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                $inventoryReads++;
                if ($inventoryReads > 1) {
                    return Process::result('[]');
                }

                return Process::result(json_encode([
                    json_decode(vmJson('orbit-e2e-nck-123-gateway'), true, 16, JSON_THROW_ON_ERROR)[0],
                    json_decode(vmJson('orbit-e2e-nck-123-app-dev'), true, 16, JSON_THROW_ON_ERROR)[0],
                ], JSON_THROW_ON_ERROR));
            }

            return Process::result();
        });

        incusHost()->deleteInstances([
            'orbit-e2e-nck-123-gateway',
            'orbit-e2e-nck-123-app-dev',
        ]);

        expect($inventoryReads)->toBe(2);
        foreach (['orbit-e2e-nck-123-gateway', 'orbit-e2e-nck-123-app-dev'] as $instance) {
            Process::assertRan(incusCommand('delete', incusTarget($instance)));
        }
    });

    it('rejects an instance deletion batch before mutation when any owner does not match', function () {
        Process::fake(function (PendingProcess $process) {
            if ($process->command !== incusCommand('list', incusTarget(), '--format=json')) {
                return Process::result('', 'Unexpected command.', 1);
            }

            return Process::result(json_encode([
                json_decode(vmJson('orbit-e2e-nck-123-gateway'), true, 16, JSON_THROW_ON_ERROR)[0],
                json_decode(
                    vmJson('orbit-e2e-nck-123-app-dev', owner: 'someone-else'),
                    true,
                    16,
                    JSON_THROW_ON_ERROR,
                )[0],
            ], JSON_THROW_ON_ERROR));
        });

        expect(fn () => incusHost()->deleteInstances([
            'orbit-e2e-nck-123-gateway',
            'orbit-e2e-nck-123-app-dev',
        ]))
            ->toThrow(RuntimeException::class, 'ownership metadata does not match');

        Process::assertNotRan(
            fn (PendingProcess $process): bool => ($process->command[3] ?? null) === 'delete',
        );
    });

    it('fails when a parallel instance deletion leaves an instance present', function () {
        Process::fake(function (PendingProcess $process) {
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                return Process::result(vmJson());
            }

            return Process::result();
        });

        expect(fn () => incusHost()->deleteInstances(['orbit-e2e-nck-123-gateway']))
            ->toThrow(RuntimeException::class, 'still exist after deletion: orbit-e2e-nck-123-gateway');
    });

    it('validates ownership once before deleting a network', function () {
        $listCalls = 0;
        Process::fake(function (PendingProcess $process) use (&$listCalls) {
            if ($process->command === incusCommand('network', 'list', incusTarget(), '--format=json')) {
                $listCalls++;

                return Process::result(json_encode([[
                    'name' => 'orbit-e2e-nck-123',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR));
            }
            if ($process->command === incusCommand('network', 'delete', incusTarget('orbit-e2e-nck-123'))) {
                return Process::result();
            }

            return Process::result('', 'Unexpected command.', 1);
        });

        incusHost()->deleteNetwork('orbit-e2e-nck-123');

        Process::assertRanInOrder([
            incusCommand('network', 'list', incusTarget(), '--format=json'),
            incusCommand('network', 'delete', incusTarget('orbit-e2e-nck-123')),
        ]);
        expect($listCalls)->toBe(1);
    });

    it('fails when Incus rejects network deletion', function () {
        Process::fake(function (PendingProcess $process) {
            return match ($process->command) {
                incusCommand('network', 'list', incusTarget(), '--format=json') => Process::result(json_encode([
                    ['name' => 'orbit-e2e-nck-123', 'config' => ['user.orbit.e2e.owner' => 'orbit-e2e']],
                ], JSON_THROW_ON_ERROR)),
                incusCommand('network', 'delete', incusTarget('orbit-e2e-nck-123')) => Process::result(
                    '',
                    'network is in use',
                    1,
                ),
                default => Process::result('', 'Unexpected command.', 1),
            };
        });

        expect(fn () => incusHost()->deleteNetwork('orbit-e2e-nck-123'))
            ->toThrow(RuntimeException::class, 'Incus command failed');
    });

    it('re-reads exact snapshots before restore and deletion', function (string $operation) {
        Process::fake([
            '*' => Process::sequence()
                ->push(vmJson())
                ->push(json_encode([[
                    'name' => 'main-g1',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR))
                ->push(Process::result()),
        ]);

        incusHost()->{$operation}('orbit-e2e-nck-123-gateway', 'main-g1');

        Process::assertRanInOrder([
            incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json'),
            incusCommand(
                'snapshot',
                'list',
                incusTarget('orbit-e2e-nck-123-gateway'),
                '--format=json',
            ),
            incusCommand(
                'snapshot',
                $operation === 'restore' ? 'restore' : 'delete',
                incusTarget('orbit-e2e-nck-123-gateway'),
                'main-g1',
            ),
        ]);
    })->with(['restore', 'deleteSnapshot']);

    it('validates one source snapshot and copies role clones in parallel with exact identity', function () {
        $sourceReads = 0;
        $snapshotReads = [];
        Process::fake(function (PendingProcess $process) use (&$sourceReads, &$snapshotReads) {
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                $sourceReads++;

                return Process::result(json_encode(array_map(
                    static fn (string $role): array => json_decode(
                        vmJson("orbit-e2e-standby-{$role}"),
                        true,
                        16,
                        JSON_THROW_ON_ERROR,
                    )[0],
                    ['gateway', 'app-dev', 'app-prod'],
                ), JSON_THROW_ON_ERROR));
            }
            if (array_slice($process->command, 3, 2) === ['snapshot', 'list']) {
                $source = preg_replace('/\A[^:]+:/', '', (string) $process->command[5]);
                $snapshotReads[$source] = ($snapshotReads[$source] ?? 0) + 1;

                return Process::result(snapshotJson('main-g1'));
            }

            return Process::result();
        });

        $instances = incusHost()->copySnapshots([
            'gateway' => [
                'source' => 'orbit-e2e-standby-gateway',
                'snapshot' => 'main-g1',
                'target' => 'orbit-e2e-nck-123-gateway',
                'metadata' => ['user.orbit.e2e.operation' => 'op-1'],
                'network' => 'oe-b32d6c83af72',
                'role' => 'gateway',
                'topology' => 'oe-b32d6c83af72',
            ],
            'app-dev' => [
                'source' => 'orbit-e2e-standby-app-dev',
                'snapshot' => 'main-g1',
                'target' => 'orbit-e2e-nck-123-app-dev',
                'metadata' => ['user.orbit.e2e.evidence' => 'ev-1'],
                'network' => 'oe-b32d6c83af72',
                'role' => 'app-dev',
                'topology' => 'oe-b32d6c83af72',
            ],
            'app-prod' => [
                'source' => 'orbit-e2e-standby-app-prod',
                'snapshot' => 'main-g1',
                'target' => 'orbit-e2e-nck-123-app-prod',
                'metadata' => ['user.orbit.e2e.evidence' => 'ev-2'],
                'network' => 'oe-b32d6c83af72',
                'role' => 'app-prod',
                'topology' => 'oe-b32d6c83af72',
            ],
        ]);

        expect(array_keys($instances))
            ->toBe(['gateway', 'app-dev', 'app-prod'])
            ->and($sourceReads)
            ->toBe(1)
            ->and($snapshotReads)
            ->toBe([
                'orbit-e2e-standby-gateway' => 1,
                'orbit-e2e-standby-app-dev' => 1,
                'orbit-e2e-standby-app-prod' => 1,
            ]);
        Process::assertRan(incusCommand(
            'copy',
            incusTarget('orbit-e2e-standby-gateway').'/main-g1',
            incusTarget('orbit-e2e-nck-123-gateway'),
            '--storage',
            'orbit-e2e',
            '--config',
            'limits.cpu=1',
            '--config',
            'limits.memory=2GiB',
            '--device',
            'root,pool=orbit-e2e,size=16GiB',
            '--config',
            'user.orbit.e2e.owner=orbit-e2e',
            '--config',
            'user.orbit.e2e.operation=op-1',
            '--device',
            'eth0,network=oe-b32d6c83af72,hwaddr=00:16:3e:a1:e4:eb',
        ));
        Process::assertRan(incusCommand(
            'copy',
            incusTarget('orbit-e2e-standby-app-dev').'/main-g1',
            incusTarget('orbit-e2e-nck-123-app-dev'),
            '--storage',
            'orbit-e2e',
            '--config',
            'limits.cpu=1',
            '--config',
            'limits.memory=2GiB',
            '--device',
            'root,pool=orbit-e2e,size=16GiB',
            '--config',
            'user.orbit.e2e.owner=orbit-e2e',
            '--config',
            'user.orbit.e2e.evidence=ev-1',
            '--device',
            'eth0,network=oe-b32d6c83af72,hwaddr=00:16:3e:43:00:72',
        ));
        Process::assertRan(incusCommand(
            'copy',
            incusTarget('orbit-e2e-standby-app-prod').'/main-g1',
            incusTarget('orbit-e2e-nck-123-app-prod'),
            '--storage',
            'orbit-e2e',
            '--config',
            'limits.cpu=1',
            '--config',
            'limits.memory=2GiB',
            '--device',
            'root,pool=orbit-e2e,size=16GiB',
            '--config',
            'user.orbit.e2e.owner=orbit-e2e',
            '--config',
            'user.orbit.e2e.evidence=ev-2',
            '--device',
            'eth0,network=oe-b32d6c83af72,hwaddr=00:16:3e:39:69:0b',
        ));
    });

    it('reads identity from expanded devices when local devices are empty', function () {
        Process::fake(function (PendingProcess $process) {
            expect($process->command)
                ->toBe(incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json'));

            return Process::result(json_encode([[
                'name' => 'orbit-e2e-nck-123-gateway',
                'type' => 'virtual-machine',
                'status' => 'Stopped',
                'status_code' => 102,
                'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                'devices' => [],
                'expanded_devices' => [
                    'root' => ['type' => 'disk', 'pool' => 'orbit-e2e'],
                    'eth0' => ['type' => 'nic', 'network' => 'oe-b32d6c83af72', 'hwaddr' => '00:16:3e:5e:4b:52'],
                ],
            ]], JSON_THROW_ON_ERROR));
        });

        $instance = incusHost()->instance('orbit-e2e-nck-123-gateway');

        expect($instance?->pool)
            ->toBe('orbit-e2e')
            ->and($instance?->network)
            ->toBe('oe-b32d6c83af72')
            ->and($instance?->mac)
            ->toBe('00:16:3e:5e:4b:52');
    });

    it('treats an already absent snapshot as a completed idempotent deletion', function () {
        Process::fake([
            '*' => Process::sequence()
                ->push(vmJson())
                ->push('[]'),
        ]);

        incusHost()->deleteSnapshotIfExists('orbit-e2e-nck-123-gateway', 'main-g1');

        Process::assertDidntRun(incusCommand(
            'snapshot',
            'delete',
            incusTarget('orbit-e2e-nck-123-gateway'),
            'main-g1',
        ));
    });

    it('creates snapshots in parallel after one owned instance inventory', function () {
        $inventoryReads = 0;
        Process::fake(function (PendingProcess $process) use (&$inventoryReads) {
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                $inventoryReads++;

                return Process::result(json_encode([
                    json_decode(vmJson('orbit-e2e-standby-gateway'), true, 16, JSON_THROW_ON_ERROR)[0],
                    json_decode(vmJson('orbit-e2e-standby-app-dev'), true, 16, JSON_THROW_ON_ERROR)[0],
                ], JSON_THROW_ON_ERROR));
            }

            return Process::result();
        });

        incusHost()->snapshotAll([
            'orbit-e2e-standby-gateway' => 'main-g1',
            'orbit-e2e-standby-app-dev' => 'main-g1',
        ]);

        expect($inventoryReads)->toBe(1);
        foreach (['orbit-e2e-standby-gateway', 'orbit-e2e-standby-app-dev'] as $instance) {
            Process::assertRan(incusCommand('snapshot', 'create', incusTarget($instance), 'main-g1'));
        }
    });

    it('restores snapshots in parallel after batched ownership validation', function () {
        $inventoryReads = 0;
        $snapshotReads = [];
        Process::fake(function (PendingProcess $process) use (&$inventoryReads, &$snapshotReads) {
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                $inventoryReads++;

                return Process::result(json_encode([
                    json_decode(vmJson('orbit-e2e-standby-gateway'), true, 16, JSON_THROW_ON_ERROR)[0],
                    json_decode(vmJson('orbit-e2e-standby-app-dev'), true, 16, JSON_THROW_ON_ERROR)[0],
                ], JSON_THROW_ON_ERROR));
            }
            if (array_slice($process->command, 3, 2) === ['snapshot', 'list']) {
                $instance = preg_replace('/\Alab:/', '', (string) ($process->command[5] ?? ''));
                $snapshotReads[] = $instance;

                return Process::result(snapshotJson('main-g1'));
            }

            return Process::result();
        });

        incusHost()->restoreAll([
            'orbit-e2e-standby-gateway' => 'main-g1',
            'orbit-e2e-standby-app-dev' => 'main-g1',
        ]);

        sort($snapshotReads);
        expect($inventoryReads)
            ->toBe(1)
            ->and($snapshotReads)
            ->toBe(['orbit-e2e-standby-app-dev', 'orbit-e2e-standby-gateway']);
        foreach (['orbit-e2e-standby-gateway', 'orbit-e2e-standby-app-dev'] as $instance) {
            Process::assertRan(incusCommand('snapshot', 'restore', incusTarget($instance), 'main-g1'));
        }
    });

    it('deletes existing snapshots in parallel after complete prevalidation and verifies absence', function () {
        $snapshotReads = [];
        Process::fake(function (PendingProcess $process) use (&$snapshotReads) {
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                return Process::result(json_encode([
                    json_decode(vmJson('orbit-e2e-nck-123-gateway'), true, 16, JSON_THROW_ON_ERROR)[0],
                    json_decode(vmJson('orbit-e2e-nck-123-app-dev'), true, 16, JSON_THROW_ON_ERROR)[0],
                ], JSON_THROW_ON_ERROR));
            }
            if (array_slice($process->command, 3, 2) === ['snapshot', 'list']) {
                $instance = preg_replace('/\Alab:/', '', (string) ($process->command[5] ?? ''));
                $snapshotReads[$instance] = ($snapshotReads[$instance] ?? 0) + 1;

                return Process::result(
                    $snapshotReads[$instance] === 1 ? snapshotJson('main-g1') : '[]',
                );
            }

            return Process::result();
        });

        incusHost()->deleteSnapshotsIfExist([
            'orbit-e2e-nck-123-gateway' => 'main-g1',
            'orbit-e2e-nck-123-app-dev' => 'main-g1',
        ]);

        expect($snapshotReads)->toBe([
            'orbit-e2e-nck-123-gateway' => 2,
            'orbit-e2e-nck-123-app-dev' => 2,
        ]);
        foreach (['orbit-e2e-nck-123-gateway', 'orbit-e2e-nck-123-app-dev'] as $instance) {
            Process::assertRan(incusCommand('snapshot', 'delete', incusTarget($instance), 'main-g1'));
        }
    });

    it('accepts a fully qualified snapshot identity for the requested instance', function () {
        Process::fake([
            '*' => Process::sequence()
                ->push(vmJson())
                ->push(json_encode([[
                    'name' => 'orbit-e2e-nck-123-gateway/main-g1',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR))
                ->push(Process::result()),
        ]);

        incusHost()->restore('orbit-e2e-nck-123-gateway', 'main-g1');
        Process::assertRan(incusCommand(
            'snapshot',
            'restore',
            incusTarget('orbit-e2e-nck-123-gateway'),
            'main-g1',
        ));
    });

    it('rejects a fully qualified snapshot identity for another instance', function () {
        Process::fake([
            '*' => Process::sequence()
                ->push(vmJson())
                ->push(json_encode([[
                    'name' => 'other-instance/main-g1',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR)),
        ]);

        expect(fn () => incusHost()->restore('orbit-e2e-nck-123-gateway', 'main-g1'))
            ->toThrow(RuntimeException::class, 'snapshot identity changed');
        Process::assertDidntRun(incusCommand(
            'snapshot',
            'restore',
            incusTarget('orbit-e2e-nck-123-gateway'),
            'main-g1',
        ));
    });

    it('rejects ownership mismatch without destructive mutation', function () {
        Process::fake(['*' => Process::result(vmJson(owner: 'someone-else'))]);

        expect(fn () => incusHost()->deleteInstance('orbit-e2e-nck-123-gateway'))
            ->toThrow(RuntimeException::class, 'ownership metadata does not match');

        Process::assertDidntRun(incusCommand('delete', incusTarget('orbit-e2e-nck-123-gateway')));
    });

    it('runs guest commands with their timeout and returns nonzero results', function () {
        Process::fake(function (PendingProcess $process) {
            return $process->command === incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json')
                ? Process::result(vmJson())
                : Process::result('stdout', 'stderr', 17);
        });

        $result = incusHost()->exec('orbit-e2e-nck-123-gateway', new GuestCommand(['sh', '-lc', 'exit 17'], 42));

        expect($result->stdout)
            ->toBe("stdout\n")
            ->and($result->stderr)
            ->toBe("stderr\n")
            ->and($result->exitCode)
            ->toBe(17)
            ->and($result->successful())
            ->toBeFalse();
        Process::assertRan(
            fn (PendingProcess $process): bool => (
                $process->command === incusCommand(
                    'exec',
                    incusTarget('orbit-e2e-nck-123-gateway'),
                    '--',
                    'sh',
                    '-lc',
                    'exit 17',
                )
                && $process->timeout === 42
            ),
        );
    });

    it('preserves labeled guest results and JSON requests through one helper process', function () {
        $instanceReads = 0;
        $helperPayload = null;
        Process::fake(function (PendingProcess $process) use (&$instanceReads, &$helperPayload) {
            if (isIncusGuestBatchHelper($process)) {
                $payload = json_decode((string) $process->input, true, 512, JSON_THROW_ON_ERROR);
                $helperPayload = $payload;

                return Process::result(json_encode([
                    [
                        'label' => 'first',
                        'stdout' => "one\n",
                        'stderr' => "warning\n",
                        'exit_code' => 0,
                    ],
                    [
                        'label' => 'second',
                        'stdout' => "two\n",
                        'stderr' => "failed\n",
                        'exit_code' => 17,
                    ],
                    [
                        'label' => 'timeout',
                        'stdout' => "partial\n",
                        'stderr' => "timed out\n",
                        'exit_code' => 124,
                        'timed_out' => true,
                    ],
                ], JSON_THROW_ON_ERROR));
            }
            if (($process->command[3] ?? null) === 'list') {
                $instanceReads++;

                return Process::result(vmJson());
            }

            return Process::result('', 'unexpected process', 99);
        });

        $results = incusHost()->execAll([
            'first' => [
                'instance' => 'orbit-e2e-nck-123-gateway',
                'command' => new GuestCommand(['probe-one', 'literal;$(not-shell)'], 11, "payload\n"),
            ],
            'second' => [
                'instance' => 'orbit-e2e-nck-123-gateway',
                'command' => new GuestCommand(['probe-two'], 17),
            ],
            'timeout' => [
                'instance' => 'orbit-e2e-nck-123-gateway',
                'command' => new GuestCommand(['probe-timeout'], 5),
            ],
        ]);

        expect($instanceReads)
            ->toBe(1)
            ->and($helperPayload)
            ->toBe([
                'requests' => [
                    [
                        'label' => 'first',
                        'project' => 'orbit',
                        'instance' => 'lab:orbit-e2e-nck-123-gateway',
                        'argv' => ['probe-one', 'literal;$(not-shell)'],
                        'timeout' => 11,
                        'stdin' => "payload\n",
                    ],
                    [
                        'label' => 'second',
                        'project' => 'orbit',
                        'instance' => 'lab:orbit-e2e-nck-123-gateway',
                        'argv' => ['probe-two'],
                        'timeout' => 17,
                        'stdin' => null,
                    ],
                    [
                        'label' => 'timeout',
                        'project' => 'orbit',
                        'instance' => 'lab:orbit-e2e-nck-123-gateway',
                        'argv' => ['probe-timeout'],
                        'timeout' => 5,
                        'stdin' => null,
                    ],
                ],
            ])
            ->and($results['first']->stdout)
            ->toBe("one\n")
            ->and($results['first']->stderr)
            ->toBe("warning\n")
            ->and($results['first']->successful())
            ->toBeTrue()
            ->and($results['second']->stdout)
            ->toBe("two\n")
            ->and($results['second']->stderr)
            ->toBe("failed\n")
            ->and($results['second']->exitCode)
            ->toBe(17)
            ->and($results['timeout']->stdout)
            ->toBe("partial\n")
            ->and($results['timeout']->stderr)
            ->toBe("timed out\n")
            ->and($results['timeout']->exitCode)
            ->toBe(124);
        Process::assertRanTimes(isIncusGuestBatchHelper(...), 1);
        Process::assertNotRan(isDirectIncusGuestCommand(...));
        Process::assertRan(
            fn (PendingProcess $process): bool => isIncusGuestBatchHelper($process) && $process->timeout === 27,
        );
    });

    it('fails closed on invalid guest helper label and result contracts', function (string $output) {
        Process::fake(function (PendingProcess $process) use ($output) {
            return isIncusGuestBatchHelper($process)
                ? Process::result($output)
                : Process::result(vmJson());
        });

        expect(fn () => incusHost()->execAll(incusGuestBatchCommands()))
            ->toThrow(RuntimeException::class, 'Incus guest command batch failed');
        Process::assertRanTimes(isIncusGuestBatchHelper(...), 1);
        Process::assertNotRan(isDirectIncusGuestCommand(...));
    })->with([
        'malformed JSON' => ['{'],
        'non-list JSON' => [json_encode(['label' => 'first'], JSON_THROW_ON_ERROR)],
        'missing label' => [json_encode([[
            'label' => 'first',
            'stdout' => '',
            'stderr' => '',
            'exit_code' => 0,
        ]], JSON_THROW_ON_ERROR)],
        'duplicate label' => [json_encode([
            ['label' => 'first', 'stdout' => '', 'stderr' => '', 'exit_code' => 0],
            ['label' => 'first', 'stdout' => '', 'stderr' => '', 'exit_code' => 0],
        ], JSON_THROW_ON_ERROR)],
        'unknown label' => [json_encode([
            ['label' => 'first', 'stdout' => '', 'stderr' => '', 'exit_code' => 0],
            ['label' => 'unknown', 'stdout' => '', 'stderr' => '', 'exit_code' => 0],
        ], JSON_THROW_ON_ERROR)],
        'missing result field' => [json_encode([
            ['label' => 'first', 'stderr' => '', 'exit_code' => 0],
            ['label' => 'second', 'stdout' => '', 'stderr' => '', 'exit_code' => 0],
        ], JSON_THROW_ON_ERROR)],
    ]);

    it('reports a failed guest helper without starting serial guest processes', function () {
        Process::fake(function (PendingProcess $process) {
            return isIncusGuestBatchHelper($process)
                ? Process::result('', 'helper unavailable', 9)
                : Process::result(vmJson());
        });

        expect(fn () => incusHost()->execAll(incusGuestBatchCommands()))
            ->toThrow(RuntimeException::class, 'Batch helper failed: helper unavailable.');
        Process::assertRanTimes(isIncusGuestBatchHelper(...), 1);
        Process::assertNotRan(isDirectIncusGuestCommand(...));
    });

    it('starts all helper guest processes before the process barrier can pass', function () {
        $directory = sys_get_temp_dir().'/orbit-exec-all-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $incus = $directory.'/incus';
        $barrier = $directory.'/barrier';
        symlink(realpath(__DIR__.'/../../Fixtures/Host/concurrent-incus.py'), $incus);
        $requests = [];
        foreach (range(1, 22) as $number) {
            $label = sprintf('probe-%02d', $number);
            $requests[] = [
                'label' => $label,
                'project' => 'orbit',
                'instance' => 'lab:orbit-e2e-nck-123-gateway',
                'argv' => ['barrier', $label],
                'timeout' => 3,
                'stdin' => null,
            ];
        }
        $requests[] = [
            'label' => 'failure',
            'project' => 'orbit',
            'instance' => 'lab:orbit-e2e-nck-123-gateway',
            'argv' => ['failure'],
            'timeout' => 3,
            'stdin' => null,
        ];
        $requests[] = [
            'label' => 'timeout',
            'project' => 'orbit',
            'instance' => 'lab:orbit-e2e-nck-123-gateway',
            'argv' => ['timeout'],
            'timeout' => 1,
            'stdin' => null,
        ];

        try {
            $process = new SymfonyProcess(
                ['python3', realpath(__DIR__.'/../../../resources/host/exec-all.py')],
                env: [
                    'PATH' => $directory.':'.getenv('PATH'),
                    'ORBIT_E2E_EXEC_ALL_BARRIER' => $barrier,
                    'ORBIT_E2E_EXEC_ALL_EXPECTED' => '22',
                ],
                input: json_encode(['requests' => $requests], JSON_THROW_ON_ERROR),
                timeout: 10,
            );

            $process->run();
            $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $results = array_column($decoded, null, 'label');
            $barrierLabels = file($barrier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            expect($process->isSuccessful())
                ->toBeTrue()
                ->and($process->getErrorOutput())
                ->toBe('')
                ->and($decoded)
                ->toHaveCount(24)
                ->and($barrierLabels)
                ->toHaveCount(22)
                ->and(array_unique(array_column(array_slice($decoded, 0, 22), 'exit_code')))
                ->toBe([0])
                ->and($results['failure'])
                ->toBe([
                    'label' => 'failure',
                    'stdout' => "failure stdout\n",
                    'stderr' => "failure stderr\n",
                    'exit_code' => 17,
                ])
                ->and($results['timeout'])
                ->toBe([
                    'label' => 'timeout',
                    'stdout' => '',
                    'stderr' => '',
                    'exit_code' => 124,
                    'timed_out' => true,
                ]);
        } finally {
            if (is_link($incus)) {
                unlink($incus);
            }
            if (is_file($barrier)) {
                unlink($barrier);
            }
            rmdir($directory);
        }
    });

    it('reuses one operation ownership inventory across guest batches', function () {
        $reads = 0;
        Process::fake(function (PendingProcess $process) use (&$reads) {
            if (
                array_filter(
                    $process->command,
                    static fn (mixed $part): bool => is_string($part)
                    && str_ends_with($part, 'exec-all.py'),
                ) !== []
            ) {
                $payload = json_decode((string) $process->input, true, 512, JSON_THROW_ON_ERROR);

                return Process::result(json_encode(array_map(static fn (array $request): array => [
                    'label' => $request['label'],
                    'stdout' => "ready\n",
                    'stderr' => '',
                    'exit_code' => 0,
                ], $payload['requests']), JSON_THROW_ON_ERROR));
            }
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                $reads++;

                return Process::result(json_encode([
                    json_decode(vmJson('orbit-e2e-nck-123-gateway'), true, 16, JSON_THROW_ON_ERROR)[0],
                    json_decode(vmJson('orbit-e2e-nck-123-app-dev'), true, 16, JSON_THROW_ON_ERROR)[0],
                ], JSON_THROW_ON_ERROR));
            }

            return Process::result('ready');
        });
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-incus-cache-'.bin2hex(random_bytes(6)));
        $operation = new OperationId(str_repeat('a', 32));
        $host = incusHost(journal: new OperationJournal($paths), operationId: $operation);
        $commands = [
            'gateway' => [
                'instance' => 'orbit-e2e-nck-123-gateway',
                'command' => new GuestCommand(['true']),
            ],
            'app-dev' => [
                'instance' => 'orbit-e2e-nck-123-app-dev',
                'command' => new GuestCommand(['true']),
            ],
        ];

        $host->execAll($commands);
        $host->execAll($commands);

        expect($reads)->toBe(1);
    });

    it('reuses one ownership proof across repeated guest commands', function () {
        $reads = 0;
        Process::fake(function (PendingProcess $process) use (&$reads) {
            if ($process->command === incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json')) {
                $reads++;

                return Process::result(vmJson());
            }

            return Process::result('ready');
        });
        $host = incusHost();

        $host->exec('orbit-e2e-nck-123-gateway', new GuestCommand(['true']));
        $host->exec('orbit-e2e-nck-123-gateway', new GuestCommand(['true']));

        expect($reads)->toBe(1);
    });

    it('inventories every owned snapshot name for exact recovery cleanup', function () {
        $gateway = 'orbit-e2e-nck-123-gateway';
        $appDev = 'orbit-e2e-nck-123-app-dev';
        Process::fake(function (PendingProcess $process) use ($gateway, $appDev) {
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                return Process::result(json_encode([
                    json_decode(vmJson($gateway), true, 16, JSON_THROW_ON_ERROR)[0],
                    json_decode(vmJson($appDev), true, 16, JSON_THROW_ON_ERROR)[0],
                ], JSON_THROW_ON_ERROR));
            }
            if (($process->command[3] ?? null) === 'snapshot' && ($process->command[4] ?? null) === 'list') {
                $instance = preg_replace('/\A[^:]+:/', '', (string) ($process->command[5] ?? ''));

                return Process::result(json_encode([
                    [
                        'name' => "{$instance}/main-promoted",
                        'created_at' => '2026-01-01T00:00:00Z',
                        'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                    ],
                    [
                        'name' => 'main-orphan',
                        'created_at' => '2026-01-02T00:00:00Z',
                        'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                    ],
                ], JSON_THROW_ON_ERROR));
            }

            return Process::result('', 'unexpected command', 1);
        });

        $snapshots = incusHost()->ownedSnapshotNames([$gateway, $appDev]);

        expect($snapshots)->toBe([
            $gateway => [
                ['name' => 'main-orphan', 'created_at' => '2026-01-02T00:00:00Z'],
                ['name' => 'main-promoted', 'created_at' => '2026-01-01T00:00:00Z'],
            ],
            $appDev => [
                ['name' => 'main-orphan', 'created_at' => '2026-01-02T00:00:00Z'],
                ['name' => 'main-promoted', 'created_at' => '2026-01-01T00:00:00Z'],
            ],
        ]);
    });

    it('pushes labeled guest files in one validated parallel batch', function () {
        $instanceReads = 0;
        Process::fake(function (PendingProcess $process) use (&$instanceReads) {
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                $instanceReads++;

                return Process::result(json_encode([
                    json_decode(vmJson('orbit-e2e-nck-123-gateway'), true, 16, JSON_THROW_ON_ERROR)[0],
                    json_decode(vmJson('orbit-e2e-nck-123-app-dev'), true, 16, JSON_THROW_ON_ERROR)[0],
                ], JSON_THROW_ON_ERROR));
            }

            return Process::result();
        });

        incusHost()->pushFiles([
            'gateway-bundle' => [
                'instance' => 'orbit-e2e-nck-123-gateway',
                'source' => '/tmp/source.bundle',
                'destination' => '/var/lib/orbit-e2e/source/source.bundle',
            ],
            'gateway-overlay' => [
                'instance' => 'orbit-e2e-nck-123-gateway',
                'source' => '/tmp/overlay.tar',
                'destination' => '/var/lib/orbit-e2e/source/overlay.tar',
            ],
            'app-dev-bundle' => [
                'instance' => 'orbit-e2e-nck-123-app-dev',
                'source' => '/tmp/source.bundle',
                'destination' => '/var/lib/orbit-e2e/source/source.bundle',
            ],
        ]);

        expect($instanceReads)->toBe(1);
        foreach ([
            incusCommand(
                'file',
                'push',
                '/tmp/source.bundle',
                incusTarget('orbit-e2e-nck-123-gateway').'/var/lib/orbit-e2e/source/source.bundle',
            ),
            incusCommand(
                'file',
                'push',
                '/tmp/overlay.tar',
                incusTarget('orbit-e2e-nck-123-gateway').'/var/lib/orbit-e2e/source/overlay.tar',
            ),
            incusCommand(
                'file',
                'push',
                '/tmp/source.bundle',
                incusTarget('orbit-e2e-nck-123-app-dev').'/var/lib/orbit-e2e/source/source.bundle',
            ),
        ] as $command) {
            Process::assertRan($command);
        }
    });

    it('rejects invalid guest file batches before invoking Incus', function (array $files) {
        Process::fake();

        expect(fn () => incusHost()->pushFiles($files))
            ->toThrow(RuntimeException::class);

        Process::assertNothingRan();
    })->with([
        'empty' => [[]],
        'missing destination' => [[
            'source' => [
                'instance' => 'orbit-e2e-nck-123-gateway',
                'source' => '/tmp/source.bundle',
            ],
        ]],
        'relative destination' => [[
            'source' => [
                'instance' => 'orbit-e2e-nck-123-gateway',
                'source' => '/tmp/source.bundle',
                'destination' => 'relative/source.bundle',
            ],
        ]],
    ]);

    it('reports each failed guest file push by label', function () {
        Process::fake(function (PendingProcess $process) {
            if (($process->command[3] ?? null) === 'list') {
                return Process::result(vmJson());
            }

            return in_array('/tmp/overlay.tar', $process->command, true)
                ? Process::result('', 'push failed', 9)
                : Process::result();
        });

        expect(fn () => incusHost()->pushFiles([
            'bundle' => [
                'instance' => 'orbit-e2e-nck-123-gateway',
                'source' => '/tmp/source.bundle',
                'destination' => '/var/lib/orbit-e2e/source/source.bundle',
            ],
            'overlay' => [
                'instance' => 'orbit-e2e-nck-123-gateway',
                'source' => '/tmp/overlay.tar',
                'destination' => '/var/lib/orbit-e2e/source/overlay.tar',
            ],
        ]))
            ->toThrow(RuntimeException::class, 'overlay: push failed');
    });

    it('validates every guest owner before starting a file push batch', function () {
        Process::fake(function (PendingProcess $process) {
            if ($process->command !== incusCommand('list', incusTarget(), '--format=json')) {
                return Process::result();
            }

            return Process::result(json_encode([
                json_decode(vmJson('orbit-e2e-nck-123-gateway'), true, 16, JSON_THROW_ON_ERROR)[0],
                json_decode(
                    vmJson('orbit-e2e-nck-123-app-dev', owner: 'someone-else'),
                    true,
                    16,
                    JSON_THROW_ON_ERROR,
                )[0],
            ], JSON_THROW_ON_ERROR));
        });

        expect(fn () => incusHost()->pushFiles([
            'gateway' => [
                'instance' => 'orbit-e2e-nck-123-gateway',
                'source' => '/tmp/source.bundle',
                'destination' => '/var/lib/orbit-e2e/source/source.bundle',
            ],
            'app-dev' => [
                'instance' => 'orbit-e2e-nck-123-app-dev',
                'source' => '/tmp/source.bundle',
                'destination' => '/var/lib/orbit-e2e/source/source.bundle',
            ],
        ]))
            ->toThrow(RuntimeException::class, 'ownership metadata does not match');

        Process::assertNotRan(
            fn (PendingProcess $process): bool => ($process->command[3] ?? null) === 'file',
        );
    });
});

describe('IncusHost failures', function () {
    it('reports nonzero exits without exposing secrets', function () {
        Process::fake(['*' => Process::result('', 'Bearer token-value', 9)]);

        try {
            incusHost(new SecretRedactor(['token-value']))->imageFingerprint('ubuntu');
            $this->fail('Expected the Incus command to fail.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())
                ->toContain('[REDACTED]')
                ->and(str_contains($exception->getMessage(), 'token-value'))
                ->toBeFalse();
        }
    });

    it('wraps timeouts and redacts their output', function () {
        Process::fake(function (): never {
            throw new RuntimeException('Timed out with password-value');
        });

        expect(fn () => incusHost(new SecretRedactor(['password-value']))->imageFingerprint('ubuntu'))
            ->toThrow(RuntimeException::class, '[REDACTED]');
    });

    it('journals failures under the caller operation identity', function () {
        $state = new StatePaths(sys_get_temp_dir().'/orbit-incus-host-'.bin2hex(random_bytes(6)));
        $redactor = new SecretRedactor(['token-value']);
        $journal = new OperationJournal($state, $redactor);
        $operationId = new OperationId(str_repeat('a', 32));
        Process::fake(['*' => Process::result('', 'Bearer token-value', 9)]);

        expect(fn () => incusHost($redactor, $journal, $operationId)->imageFingerprint('ubuntu'))
            ->toThrow(RuntimeException::class);

        expect($journal->entries($operationId))
            ->toHaveCount(1)
            ->and($journal->entries($operationId)[0]['error'])
            ->toContain('[REDACTED]')
            ->and(str_contains((string) $journal->entries($operationId)[0]['error'], 'token-value'))
            ->toBeFalse();
    });

    it('rejects unsafe public identities before running Incus', function () {
        Process::fake();

        expect(fn () => incusHost()->deleteInstance('orbit-e2e-*'))
            ->toThrow(RuntimeException::class, 'Invalid')
            ->and(fn () => incusHost()->setMetadata('safe', ['user.other.owner' => 'orbit-e2e']))
            ->toThrow(RuntimeException::class, 'ownership metadata');
        Process::assertNothingRan();
    });
});
