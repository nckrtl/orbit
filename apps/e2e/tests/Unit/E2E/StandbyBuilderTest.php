<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\StandbyBuilder;
use App\E2E\StandbyManifestStore;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\TopologyConverger;
use App\E2E\TopologyVerifier;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\PreparedFingerprint;
use App\E2E\Value\StandbyIdentity;
use App\E2E\Value\TopologyTarget;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Container\Container;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

function standby_dnsmasq(): string
{
    return 'port=0';
}

function cold_cleanup_builder(IncusHost $host, AtomicJsonStore $state, StatePaths $paths): StandbyBuilder
{
    /** @mago-expect analysis:possibly-invalid-argument Test helpers resolve only known class names. */
    $uninitialized = fn (string $class): object => new ReflectionClass($class)->newInstanceWithoutConstructor();

    return new StandbyBuilder(
        $host,
        new IncusNetworkLifecycle($host),
        $uninitialized(WorktreeSynchronizer::class),
        $uninitialized(TopologyConverger::class),
        $uninitialized(TopologyVerifier::class),
        new StandbyManifestStore($state, $paths, $host),
        $state,
        __DIR__,
        StandbyIdentity::primary(),
    );
}

function standby_incus_command(string ...$arguments): array
{
    return ['incus', '--project', 'default', ...$arguments];
}

/** @param list<string> $command */
function standby_builder_is_global_ipv4_probe(array $command): bool
{
    return (
        ($command[3] ?? null) === 'exec'
        && in_array('sh', $command, true)
        && str_contains(implode(' ', $command), 'ip -4 route show default')
    );
}

/** @param list<string> $command */
function standby_firewall_result(array $command): ?ProcessResult
{
    if (
        ($command[0] ?? null) === 'python3'
        && str_ends_with((string) ($command[1] ?? ''), '/resources/host/reconcile-firewall.py')
    ) {
        return Process::result(json_encode(['changed' => true], JSON_THROW_ON_ERROR));
    }

    if (array_slice($command, 0, 5) !== ['sudo', '-n', 'iptables', '-w', '5']) {
        return null;
    }

    return in_array('-C', $command, true) ? Process::result('', '', 1) : Process::result();
}

/**
 * @mago-expect lint:cyclomatic-complexity Cold failure scenarios share one isolated process boundary.
 * @mago-expect lint:kan-defect Exact Incus command fixtures must remain fail closed.
 */
/** A cold build of the standby a named namespace owns. */
function namespaced_builder(
    StandbyIdentity $identity,
    IncusHost $host,
    AtomicJsonStore $state,
    StatePaths $paths,
): StandbyBuilder {
    /** @mago-expect analysis:possibly-invalid-argument Test helpers resolve only known class names. */
    $uninitialized = fn (string $class): object => new ReflectionClass($class)->newInstanceWithoutConstructor();

    return new StandbyBuilder(
        $host,
        new IncusNetworkLifecycle($host),
        $uninitialized(WorktreeSynchronizer::class),
        $uninitialized(TopologyConverger::class),
        $uninitialized(TopologyVerifier::class),
        new StandbyManifestStore($state, $paths, $host),
        $state,
        __DIR__,
        $identity,
    );
}

/**
 * The host of a cold build that fails at the first VM, so the test observes the
 * exact network and device identities the build asked Incus for.
 *
 * @param list<string> $recorded
 */
function coldSlotProcess(PendingProcess $process, array &$recorded, StandbyIdentity $identity): ProcessResult
{
    $command = $process->command;
    assert(is_array($command), 'Incus uses argument arrays.');
    if (($firewall = standby_firewall_result($command)) !== null) {
        return $firewall;
    }
    $recorded[] = implode(' ', $command);
    if (in_array('image', $command, true)) {
        return Process::result(json_encode([[
            'type' => 'virtual-machine',
            'fingerprint' => str_repeat('f', 64),
            'aliases' => [['name' => 'orbit-base']],
        ]], JSON_THROW_ON_ERROR));
    }
    if (in_array('network', $command, true) && in_array('list', $command, true)) {
        return Process::result('[]');
    }
    if (($command[3] ?? null) === 'list') {
        return Process::result('[]');
    }
    if (($command[3] ?? null) === 'init') {
        return Process::result('', 'controlled init failure', 1);
    }

    return Process::result();
}

describe('StandbyBuilder', function () {
    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        /** @mago-expect analysis:possibly-invalid-argument Process fakes only require the facade container contract. */
        Facade::setFacadeApplication($container);
    });

    it('uses only the canonical fixed standby resource identities', function () {
        $target = TopologyTarget::standby();

        expect($target->isStandby())
            ->toBeTrue()
            ->and($target->network())
            ->toBe('oe-standby')
            ->and($target->instance('gateway'))
            ->toBe('orbit-e2e-standby-gateway')
            ->and($target->instance('app-dev'))
            ->toBe('orbit-e2e-standby-app-dev')
            ->and($target->instance('app-prod'))
            ->toBe('orbit-e2e-standby-app-prod');
    });

    it('requires explicit cold-build permission before touching Incus', function () {
        /** @mago-expect analysis:possibly-invalid-argument Test helpers resolve only known class names. */
        $uninitialized = fn (string $class): object => new ReflectionClass($class)->newInstanceWithoutConstructor();
        $paths = new StatePaths(temporaryPath('orbit-builder-', 4));
        $state = new AtomicJsonStore($paths);
        $host = $uninitialized(IncusHost::class);
        $manifests = new StandbyManifestStore($state, $paths, $host);
        $builder = new StandbyBuilder(
            $host,
            $uninitialized(IncusNetworkLifecycle::class),
            $uninitialized(WorktreeSynchronizer::class),
            $uninitialized(TopologyConverger::class),
            $uninitialized(TopologyVerifier::class),
            $manifests,
            $state,
            __DIR__,
            StandbyIdentity::primary(),
        );

        expect(fn () => $builder->build(
            str_repeat('a', 40),
            new PreparedFingerprint(str_repeat('b', 64)),
            str_repeat('d', 64),
            new LaravelRelease('v13.0.0', str_repeat('c', 40)),
            false,
            new OperationId(str_repeat('e', 32)),
        ))
            ->toThrow(RuntimeException::class, 'explicit permission');
    });

    /** @mago-expect lint:cyclomatic-complexity The cold-build fixture keeps one complete lifecycle assertion. */
    it('starts every newly initialized VM before source synchronization', function () {
        $paths = new StatePaths(temporaryPath('orbit-builder-', 4));
        $state = new AtomicJsonStore($paths);
        $started = [];
        $initialized = [];
        $events = [];
        $ipv4Probes = [];
        /** @mago-expect lint:cyclomatic-complexity The process fake maps each Incus cold-build command explicitly. */
        Process::fake(function (PendingProcess $process) use (&$started, &$initialized, &$events, &$ipv4Probes) {
            $command = $process->command;
            assert(is_array($command), 'Incus uses argument arrays.');
            if (($firewall = standby_firewall_result($command)) !== null) {
                return $firewall;
            }
            if (in_array('image', $command, true)) {
                return Process::result(json_encode([[
                    'type' => 'virtual-machine',
                    'fingerprint' => str_repeat('f', 64),
                    'aliases' => [['name' => 'orbit-base']],
                ]], JSON_THROW_ON_ERROR));
            }
            if (in_array('create', $command, true)) {
                return Process::result();
            }
            if (in_array('init', $command, true)) {
                $initialized[] = preg_replace(
                    '/\A[^:]+:/',
                    '',
                    array_values(array_filter(
                        $command,
                        fn (mixed $value): bool => is_string($value) && str_contains($value, 'standby-'),
                    ))[0],
                );

                return Process::result();
            }
            if (in_array('list', $command, true)) {
                if ($command === standby_incus_command('list', 'local:', '--format=json')) {
                    return Process::result(json_encode(array_map(
                        static fn (string $name): array => [
                            'name' => $name,
                            'type' => 'virtual-machine',
                            'status' => 'Stopped',
                            'status_code' => 102,
                            'config' => [
                                'user.orbit.e2e.owner' => 'orbit-e2e',
                                'user.orbit.e2e.operation' => str_repeat('a', 32),
                            ],
                            'devices' => ['root' => ['pool' => 'orbit-e2e'], 'eth0' => ['network' => 'oe-standby']],
                        ],
                        $initialized,
                    ), JSON_THROW_ON_ERROR));
                }
                $name = preg_replace('/\A[^:]+:/', '', $command[4]);

                return Process::result(
                    in_array($name, $initialized, true)
                        ? json_encode([[
                            'name' => $name,
                            'type' => 'virtual-machine',
                            'status' => 'Stopped',
                            'status_code' => 102,
                            'config' => [
                                'user.orbit.e2e.owner' => 'orbit-e2e',
                                'user.orbit.e2e.operation' => str_repeat('a', 32),
                            ],
                            'devices' => ['root' => ['pool' => 'orbit-e2e'], 'eth0' => ['network' => 'oe-standby']],
                        ]], JSON_THROW_ON_ERROR) : '[]',
                );
            }
            if (in_array('start', $command, true)) {
                $instance = array_values(array_filter(
                    $command,
                    fn (mixed $value): bool => is_string($value) && str_contains($value, 'standby-'),
                ))[0];
                $events[] = 'start:'.$instance;
                $started[] = $instance;

                return Process::result();
            }
            if (str_contains(implode(' ', $command), "printf '%s\\n'")) {
                $events[] = 'reset:'.($command[4] ?? '');

                return Process::result();
            }
            if (standby_builder_is_global_ipv4_probe($command)) {
                $instance = (string) ($command[4] ?? '');
                $ipv4Probes[$instance] = ($ipv4Probes[$instance] ?? 0) + 1;
                $phase = $ipv4Probes[$instance] === 1 ? 'pre-reset-ipv4' : 'post-reset-ipv4';
                $events[] = "{$phase}:{$instance}";

                return Process::result("2: enp5s0    inet 10.232.1.10/24 scope global enp5s0\n");
            }
            if (in_array('/bin/true', $command, true)) {
                $events[] = 'wait:'.($command[4] ?? '');

                return Process::result();
            }

            return Process::result('[]');
        });
        $builder = cold_cleanup_builder(new IncusHost(pool: 'orbit-e2e'), $state, $paths);

        expect(fn () => $builder->build(
            str_repeat('a', 40),
            new PreparedFingerprint(str_repeat('b', 64), ['base_image_alias' => 'orbit-base']),
            str_repeat('f', 64),
            new LaravelRelease('v13.0.0', str_repeat('c', 40)),
            true,
            new OperationId(str_repeat('d', 32)),
        ))
            ->toThrow(RuntimeException::class, 'cleanup failed');

        expect($started)
            ->toBe([
                'local:orbit-e2e-standby-gateway',
                'local:orbit-e2e-standby-app-dev',
                'local:orbit-e2e-standby-app-prod',
            ])
            ->and($events)
            ->toBe([
                'start:local:orbit-e2e-standby-gateway',
                'start:local:orbit-e2e-standby-app-dev',
                'start:local:orbit-e2e-standby-app-prod',
                'wait:local:orbit-e2e-standby-gateway',
                'wait:local:orbit-e2e-standby-app-dev',
                'wait:local:orbit-e2e-standby-app-prod',
                'pre-reset-ipv4:local:orbit-e2e-standby-gateway',
                'pre-reset-ipv4:local:orbit-e2e-standby-app-dev',
                'pre-reset-ipv4:local:orbit-e2e-standby-app-prod',
                'reset:local:orbit-e2e-standby-gateway',
                'reset:local:orbit-e2e-standby-app-dev',
                'reset:local:orbit-e2e-standby-app-prod',
                'post-reset-ipv4:local:orbit-e2e-standby-gateway',
                'post-reset-ipv4:local:orbit-e2e-standby-app-dev',
                'post-reset-ipv4:local:orbit-e2e-standby-app-prod',
            ]);
    });

    it('creates the standby network and addresses on the slot its identity owns', function () {
        $identity = StandbyIdentity::live();
        $paths = new StatePaths(temporaryPath('orbit-builder-', 4));
        $state = new AtomicJsonStore($paths);
        $recorded = [];
        Process::fake(static function (PendingProcess $process) use (&$recorded, $identity): ProcessResult {
            return coldSlotProcess($process, $recorded, $identity);
        });

        expect(fn () => namespaced_builder($identity, new IncusHost(pool: 'orbit-e2e'), $state, $paths)->build(
            str_repeat('a', 40),
            new PreparedFingerprint(str_repeat('b', 64), ['base_image_alias' => 'orbit-base']),
            str_repeat('f', 64),
            new LaravelRelease('v13.0.0', str_repeat('c', 40)),
            true,
            new OperationId(str_repeat('d', 32)),
        ))
            ->toThrow(RuntimeException::class);

        $created = array_values(array_filter(
            $recorded,
            static fn (string $line): bool => str_contains($line, 'network create'),
        ));
        $initialized = array_values(array_filter(
            $recorded,
            static fn (string $line): bool => str_contains($line, ' init '),
        ));

        expect($created)
            ->toHaveCount(1)
            ->and($created[0])
            ->toContain('local:oe-live-standby')
            ->toContain('ipv4.address=10.232.200.1/24')
            ->toContain('ipv4.dhcp.ranges=10.232.200.10-10.232.200.12')
            ->and($initialized[0] ?? '')
            ->toContain('local:orbit-e2e-live-standby-gateway')
            ->toContain('eth0,network=oe-live-standby')
            ->toContain('eth0,ipv4.address=10.232.200.10');
    });

    it('adopts and deletes a planned VM when init reports failure after remote creation', function () {
        $paths = new StatePaths(temporaryPath('orbit-builder-', 4));
        $state = new AtomicJsonStore($paths);
        $existing = [];
        $instances = [
            'orbit-e2e-standby-gateway',
            'orbit-e2e-standby-app-dev',
            'orbit-e2e-standby-app-prod',
        ];
        $networkExists = false;
        $deleted = [];
        Process::fake(function (PendingProcess $process) use (&$existing, &$networkExists, &$deleted, $instances) {
            $command = $process->command;
            assert(is_array($command), 'Incus uses argument arrays.');
            if (($firewall = standby_firewall_result($command)) !== null) {
                return $firewall;
            }
            if ($command === standby_incus_command('image', 'list', 'local:', 'orbit-base', '--format=json')) {
                return Process::result(json_encode([[
                    'type' => 'virtual-machine',
                    'fingerprint' => str_repeat('f', 64),
                    'aliases' => [['name' => 'orbit-base']],
                ]], JSON_THROW_ON_ERROR));
            }
            if ($command === standby_incus_command('network', 'list', 'local:', '--format=json')) {
                return Process::result(json_encode(
                    $networkExists
                        ? [[
                            'name' => 'oe-standby',
                            'config' => [
                                'user.orbit.e2e.owner' => 'orbit-e2e',
                                'user.orbit.e2e.operation' => str_repeat('d', 32),
                            ],
                        ]] : [],
                    JSON_THROW_ON_ERROR,
                ));
            }
            if (
                $command === standby_incus_command(
                    'network',
                    'create',
                    'local:oe-standby',
                    'ipv4.address=10.232.1.1/24',
                    'ipv4.nat=true',
                    'ipv4.dhcp.ranges=10.232.1.10-10.232.1.12',
                    'ipv6.address=none',
                    'raw.dnsmasq='.standby_dnsmasq(),
                    'user.orbit.e2e.operation=dddddddddddddddddddddddddddddddd',
                    'user.orbit.e2e.owner=orbit-e2e',
                )
            ) {
                $networkExists = true;

                return Process::result();
            }
            if ($command === standby_incus_command('list', 'local:', '--format=json')) {
                return Process::result(json_encode(array_map(
                    static fn (string $name): array => [
                        'name' => $name,
                        'type' => 'virtual-machine',
                        'status' => 'Stopped',
                        'status_code' => 102,
                        'config' => [
                            'user.orbit.e2e.owner' => 'orbit-e2e',
                            'user.orbit.e2e.operation' => str_repeat('d', 32),
                        ],
                        'devices' => ['root' => ['pool' => 'orbit-e2e'], 'eth0' => ['network' => 'oe-standby']],
                    ],
                    $existing,
                ), JSON_THROW_ON_ERROR));
            }
            $instanceListCommands = array_fill_keys(array_map(
                fn (string $name): string => json_encode(
                    standby_incus_command('list', "local:{$name}", '--format=json'),
                    JSON_THROW_ON_ERROR,
                ),
                $instances,
            ), true);
            if (isset($instanceListCommands[json_encode($command, JSON_THROW_ON_ERROR)])) {
                $name = preg_replace('/\A[^:]+:/', '', $command[4]);

                return Process::result(json_encode(
                    in_array($name, $existing, true)
                        ? [[
                            'name' => $name,
                            'type' => 'virtual-machine',
                            'status' => 'Stopped',
                            'status_code' => 102,
                            'config' => [
                                'user.orbit.e2e.owner' => 'orbit-e2e',
                                'user.orbit.e2e.operation' => str_repeat('d', 32),
                            ],
                            'devices' => [
                                'root' => ['pool' => 'orbit-e2e'],
                                'eth0' => ['network' => 'oe-standby'],
                            ],
                        ]] : [],
                    JSON_THROW_ON_ERROR,
                ));
            }
            if (
                $command === standby_incus_command(
                    'init',
                    'local:orbit-base',
                    'local:orbit-e2e-standby-gateway',
                    '--vm',
                    '--storage',
                    'orbit-e2e',
                    '--config',
                    'limits.cpu=1',
                    '--config',
                    'limits.memory=2GiB',
                    '--device',
                    'root,pool=orbit-e2e',
                    '--device',
                    'root,size=16GiB',
                    '--device',
                    'eth0,network=oe-standby',
                    '--device',
                    'eth0,ipv4.address=10.232.1.10',
                    '--device',
                    'eth0,hwaddr=00:16:3e:77:ee:5a',
                    '--config',
                    'user.orbit.e2e.owner=orbit-e2e',
                    '--config',
                    'user.orbit.e2e.operation=dddddddddddddddddddddddddddddddd',
                )
            ) {
                $existing[] = 'orbit-e2e-standby-gateway';

                return Process::result('', 'creation response lost', 1);
            }
            if ($command === standby_incus_command('network', 'delete', 'local:oe-standby')) {
                $networkExists = false;
                $deleted[] = 'oe-standby';

                return Process::result();
            } else {
                foreach ($instances as $instance) {
                    if ($command === standby_incus_command('delete', "local:{$instance}")) {
                        $deleted[] = $instance;
                        $existing = array_values(array_diff($existing, [$instance]));

                        return Process::result();
                    }
                }
            }

            throw new RuntimeException(json_encode($command, JSON_THROW_ON_ERROR));
        });
        $builder = cold_cleanup_builder(new IncusHost(pool: 'orbit-e2e'), $state, $paths);

        expect(fn () => $builder->build(
            str_repeat('a', 40),
            new PreparedFingerprint(str_repeat('b', 64), ['base_image_alias' => 'orbit-base']),
            str_repeat('f', 64),
            new LaravelRelease('v13.0.0', str_repeat('c', 40)),
            true,
            new OperationId(str_repeat('d', 32)),
        ))
            ->toThrow(RuntimeException::class, 'Incus VM initialization batch failed');

        expect($deleted)
            ->toBe(['orbit-e2e-standby-gateway', 'oe-standby'])
            ->and($state->read('standby/corrupt.json'))
            ->toBeNull();
    });

    it('preflights the complete topology before any create when a later VM already exists', function () {
        $paths = new StatePaths(temporaryPath('orbit-builder-', 4));
        $state = new AtomicJsonStore($paths);
        $evidence = str_repeat('9', 32);
        $observed = [];
        $instances = [
            'orbit-e2e-standby-gateway',
            'orbit-e2e-standby-app-dev',
            'orbit-e2e-standby-app-prod',
        ];
        Process::fake(function (PendingProcess $process) use (&$observed, $instances) {
            $command = $process->command;
            assert(is_array($command), 'Incus uses argument arrays.');
            if (($firewall = standby_firewall_result($command)) !== null) {
                return $firewall;
            }
            if ($command === standby_incus_command('image', 'list', 'local:', 'orbit-base', '--format=json')) {
                return Process::result(json_encode([[
                    'type' => 'virtual-machine',
                    'fingerprint' => str_repeat('f', 64),
                    'aliases' => [['name' => 'orbit-base']],
                ]], JSON_THROW_ON_ERROR));
            }
            if ($command === standby_incus_command('network', 'list', 'local:', '--format=json')) {
                $observed[] = 'network';

                return Process::result('[]');
            }
            if ($command === standby_incus_command('list', 'local:', '--format=json')) {
                $observed[] = 'inventory';

                return Process::result(json_encode([[
                    'name' => 'orbit-e2e-standby-app-prod',
                    'type' => 'virtual-machine',
                    'status' => 'Stopped',
                    'status_code' => 102,
                    'config' => [
                        'user.orbit.e2e.owner' => 'orbit-e2e',
                        'user.orbit.e2e.operation' => str_repeat('e', 32),
                    ],
                    'devices' => ['root' => ['pool' => 'orbit-e2e'], 'eth0' => ['network' => 'oe-standby']],
                ]], JSON_THROW_ON_ERROR));
            }
            $instanceListCommands = array_fill_keys(array_map(
                fn (string $name): string => json_encode(
                    standby_incus_command('list', "local:{$name}", '--format=json'),
                    JSON_THROW_ON_ERROR,
                ),
                $instances,
            ), true);
            if (isset($instanceListCommands[json_encode($command, JSON_THROW_ON_ERROR)])) {
                $name = preg_replace('/\A[^:]+:/', '', $command[4]);
                $observed[] = $name;
                if ($name !== 'orbit-e2e-standby-app-prod') {
                    return Process::result('[]');
                }

                return Process::result(json_encode([[
                    'name' => $name,
                    'type' => 'virtual-machine',
                    'status' => 'Stopped',
                    'status_code' => 102,
                    'config' => [
                        'user.orbit.e2e.owner' => 'orbit-e2e',
                        'user.orbit.e2e.operation' => str_repeat('e', 32),
                    ],
                    'devices' => ['root' => ['pool' => 'orbit-e2e'], 'eth0' => ['network' => 'oe-standby']],
                ]], JSON_THROW_ON_ERROR));
            }

            throw new RuntimeException(json_encode($command, JSON_THROW_ON_ERROR));
        });
        $builder = cold_cleanup_builder(new IncusHost(pool: 'orbit-e2e'), $state, $paths);

        expect(fn () => $builder->build(
            str_repeat('a', 40),
            new PreparedFingerprint(str_repeat('b', 64), ['base_image_alias' => 'orbit-base']),
            str_repeat('f', 64),
            new LaravelRelease('v13.0.0', str_repeat('c', 40)),
            true,
            new OperationId($evidence),
        ))
            ->toThrow(RuntimeException::class, 'already exists');

        expect($observed)
            ->toBe([
                'network',
                'inventory',
            ]);
        Process::assertDidntRun(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && in_array(
                    $process->command,
                    [
                        standby_incus_command(
                            'network',
                            'create',
                            'local:oe-standby',
                            'ipv4.address=10.232.1.1/24',
                            'ipv4.nat=true',
                            'ipv4.dhcp.ranges=10.232.1.10-10.232.1.12',
                            'ipv6.address=none',
                            'raw.dnsmasq='.standby_dnsmasq(),
                            'user.orbit.e2e.owner=orbit-e2e',
                        ),
                        standby_incus_command(
                            'init',
                            'local:orbit-base',
                            'local:orbit-e2e-standby-gateway',
                            '--vm',
                            '--storage',
                            'orbit-e2e',
                            '--network',
                            'oe-standby',
                            '--config',
                            'user.orbit.e2e.owner=orbit-e2e',
                        ),
                        standby_incus_command(
                            'init',
                            'local:orbit-base',
                            'local:orbit-e2e-standby-app-dev',
                            '--vm',
                            '--storage',
                            'orbit-e2e',
                            '--network',
                            'oe-standby',
                            '--config',
                            'user.orbit.e2e.owner=orbit-e2e',
                        ),
                        standby_incus_command(
                            'init',
                            'local:orbit-base',
                            'local:orbit-e2e-standby-app-prod',
                            '--vm',
                            '--storage',
                            'orbit-e2e',
                            '--network',
                            'oe-standby',
                            '--config',
                            'user.orbit.e2e.owner=orbit-e2e',
                        ),
                    ],
                    true,
                )
            ),
        );
    });
});
