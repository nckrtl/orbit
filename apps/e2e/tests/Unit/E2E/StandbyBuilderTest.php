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
    return implode("\n", [
        'port=0',
        'dhcp-host=00:16:3e:77:ee:5a,10.232.1.10',
        'dhcp-host=00:16:3e:71:18:e5,10.232.1.11',
        'dhcp-host=00:16:3e:a3:2d:6c,10.232.1.12',
    ]);
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
        new StandbyManifestStore($state, $paths),
        $state,
        $paths,
        __DIR__,
    );
}

function standby_incus_command(string ...$arguments): array
{
    return ['incus', '--project', 'default', ...$arguments];
}

/** @return array{schema:int,operation_id:string,remote:string,project:string,pool:string,network:array{name:string,state:string,absent_preflight:bool},base_image_fingerprint:string,instances:list<mixed>,status:string} */
function legacy_cleaned_standby_attempt(string $evidence): array
{
    return [
        'schema' => 2,
        'operation_id' => $evidence,
        'remote' => 'local',
        'project' => 'default',
        'pool' => 'orbit-e2e',
        'network' => [
            'name' => 'oe-standby',
            'state' => 'cleaned',
            'absent_preflight' => true,
        ],
        'base_image_fingerprint' => str_repeat('f', 64),
        'instances' => [],
        'status' => 'cleaned',
    ];
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
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-builder-'.bin2hex(random_bytes(4)));
        $state = new AtomicJsonStore($paths);
        $manifests = new StandbyManifestStore($state, $paths);
        $builder = new StandbyBuilder(
            $uninitialized(IncusHost::class),
            $uninitialized(IncusNetworkLifecycle::class),
            $uninitialized(WorktreeSynchronizer::class),
            $uninitialized(TopologyConverger::class),
            $uninitialized(TopologyVerifier::class),
            $manifests,
            $state,
            $paths,
            __DIR__,
        );

        expect(fn () => $builder->build(
            str_repeat('a', 40),
            new PreparedFingerprint(str_repeat('b', 64)),
            str_repeat('d', 64),
            new LaravelRelease('v13.0.0', str_repeat('c', 40)),
            false,
            new OperationId(str_repeat('e', 32)),
            str_repeat('e', 32),
        ))
            ->toThrow(RuntimeException::class, 'explicit permission');
    });

    it('starts every newly initialized VM before source synchronization', function () {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-builder-'.bin2hex(random_bytes(4)));
        $state = new AtomicJsonStore($paths);
        $started = [];
        $initialized = [];
        $events = [];
        Process::fake(function (PendingProcess $process) use (&$started, &$initialized, &$events) {
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
                                'user.orbit.e2e.evidence' => str_repeat('a', 32),
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
                                'user.orbit.e2e.evidence' => str_repeat('a', 32),
                            ],
                            'devices' => ['root' => ['pool' => 'orbit-e2e'], 'eth0' => ['network' => 'oe-standby']],
                        ]], JSON_THROW_ON_ERROR) : '[]',
                );
            }
            if (in_array('start', $command, true)) {
                $events[] = 'start';
                $started[] = array_values(array_filter(
                    $command,
                    fn (mixed $value): bool => is_string($value) && str_contains($value, 'standby-'),
                ))[0];

                return Process::result();
            }
            if (str_contains(implode(' ', $command), "printf '%s\\n'")) {
                $events[] = 'reset';

                return Process::result();
            }
            if (in_array('ip', $command, true)) {
                $events[] = 'ipv4';

                return Process::result("2: eth0    inet 10.232.1.10/24 scope global eth0\n");
            }
            if (in_array('/bin/true', $command, true)) {
                $events[] = 'wait';

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
            str_repeat('d', 32),
        ))
            ->toThrow(RuntimeException::class, 'cleanup failed');

        expect($started)
            ->toBe([
                'local:orbit-e2e-standby-gateway',
                'local:orbit-e2e-standby-app-dev',
                'local:orbit-e2e-standby-app-prod',
            ])
            ->and($events)
            ->toHaveCount(12)
            ->and(array_slice($events, 0, 3))
            ->each->toBe('start')->and(array_slice($events, 3, 3))
            ->each->toBe('wait')->and(array_slice($events, 6, 3))
            ->each->toBe('reset')->and(array_slice($events, 9))
            ->each->toBe('ipv4');
    });

    it('accepts a fully cleaned attempt recorded with the former standby network identity', function () {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-builder-'.bin2hex(random_bytes(4)));
        $state = new AtomicJsonStore($paths);
        $previousEvidence = str_repeat('a', 32);
        $state->write("standby/cold-attempts/{$previousEvidence}.json", [
            'schema' => 3,
            'operation_id' => $previousEvidence,
            'evidence_id' => $previousEvidence,
            'remote' => 'local',
            'project' => 'default',
            'pool' => 'orbit-e2e',
            'network' => [
                'name' => 'orbit-e2e-standby',
                'state' => 'cleaned',
                'absent_preflight' => true,
            ],
            'base_image_fingerprint' => str_repeat('f', 64),
            'instances' => [],
            'status' => 'cleaned',
        ]);
        $builder = cold_cleanup_builder(new IncusHost(pool: 'orbit-e2e'), $state, $paths);

        expect(fn () => $builder->build(
            str_repeat('a', 40),
            new PreparedFingerprint(str_repeat('b', 64)),
            str_repeat('d', 64),
            new LaravelRelease('v13.0.0', str_repeat('c', 40)),
            true,
            new OperationId(str_repeat('e', 32)),
            str_repeat('e', 32),
        ))
            ->toThrow(RuntimeException::class, 'no base image alias');
    });

    it('accepts fully cleaned attempt evidence from schema two', function () {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-builder-'.bin2hex(random_bytes(4)));
        $state = new AtomicJsonStore($paths);
        $previousEvidence = str_repeat('a', 32);
        $state->write(
            "standby/cold-attempts/{$previousEvidence}.json",
            legacy_cleaned_standby_attempt($previousEvidence),
        );
        $builder = cold_cleanup_builder(new IncusHost(pool: 'orbit-e2e'), $state, $paths);

        expect(fn () => $builder->build(
            str_repeat('a', 40),
            new PreparedFingerprint(str_repeat('b', 64)),
            str_repeat('d', 64),
            new LaravelRelease('v13.0.0', str_repeat('c', 40)),
            true,
            new OperationId(str_repeat('e', 32)),
            str_repeat('e', 32),
        ))
            ->toThrow(RuntimeException::class, 'no base image alias');
    });

    it('rejects active attempt evidence from schema two', function () {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-builder-'.bin2hex(random_bytes(4)));
        $state = new AtomicJsonStore($paths);
        $previousEvidence = str_repeat('a', 32);
        $attempt = legacy_cleaned_standby_attempt($previousEvidence);
        $attempt['network']['state'] = 'created';
        $attempt['status'] = 'creating';
        $state->write("standby/cold-attempts/{$previousEvidence}.json", $attempt);
        $builder = cold_cleanup_builder(new IncusHost(pool: 'orbit-e2e'), $state, $paths);

        expect(fn () => $builder->build(
            str_repeat('a', 40),
            new PreparedFingerprint(str_repeat('b', 64)),
            str_repeat('d', 64),
            new LaravelRelease('v13.0.0', str_repeat('c', 40)),
            true,
            new OperationId(str_repeat('e', 32)),
            str_repeat('e', 32),
        ))
            ->toThrow(RuntimeException::class, 'attempt evidence is invalid');
    });

    it('rejects an active attempt recorded with the former standby network identity', function () {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-builder-'.bin2hex(random_bytes(4)));
        $state = new AtomicJsonStore($paths);
        $previousEvidence = str_repeat('a', 32);
        $state->write("standby/cold-attempts/{$previousEvidence}.json", [
            'schema' => 3,
            'operation_id' => $previousEvidence,
            'evidence_id' => $previousEvidence,
            'remote' => 'local',
            'project' => 'default',
            'pool' => 'orbit-e2e',
            'network' => [
                'name' => 'orbit-e2e-standby',
                'state' => 'created',
                'absent_preflight' => true,
            ],
            'base_image_fingerprint' => str_repeat('f', 64),
            'instances' => [],
            'status' => 'creating',
        ]);
        $builder = cold_cleanup_builder(new IncusHost(pool: 'orbit-e2e'), $state, $paths);

        expect(fn () => $builder->build(
            str_repeat('a', 40),
            new PreparedFingerprint(str_repeat('b', 64)),
            str_repeat('d', 64),
            new LaravelRelease('v13.0.0', str_repeat('c', 40)),
            true,
            new OperationId(str_repeat('e', 32)),
            str_repeat('e', 32),
        ))
            ->toThrow(RuntimeException::class, 'attempt evidence is invalid');
    });

    it('rejects a cold attempt with an unknown lifecycle status', function () {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-builder-'.bin2hex(random_bytes(4)));
        $state = new AtomicJsonStore($paths);
        $evidence = str_repeat('a', 32);
        $state->write("standby/cold-attempts/{$evidence}.json", [
            'schema' => 3,
            'operation_id' => $evidence,
            'evidence_id' => $evidence,
            'remote' => 'local',
            'project' => 'default',
            'pool' => 'orbit-e2e',
            'network' => ['name' => 'oe-standby', 'state' => 'planned', 'absent_preflight' => true],
            'base_image_fingerprint' => str_repeat('f', 64),
            'instances' => [],
            'status' => 'unexpected',
        ]);
        $builder = cold_cleanup_builder(new IncusHost(pool: 'orbit-e2e'), $state, $paths);

        expect(fn () => $builder->build(
            str_repeat('a', 40),
            new PreparedFingerprint(str_repeat('b', 64)),
            str_repeat('d', 64),
            new LaravelRelease('v13.0.0', str_repeat('c', 40)),
            true,
            new OperationId(str_repeat('e', 32)),
            str_repeat('e', 32),
        ))
            ->toThrow(RuntimeException::class, 'attempt evidence is invalid');
    });

    it('deletes only resources recorded by a failed cold attempt in reverse order', function (int $count) {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-builder-'.bin2hex(random_bytes(4)));
        $state = new AtomicJsonStore($paths);
        $roles = array_slice(['gateway', 'app-dev', 'app-prod'], 0, $count);
        $instances = array_map(fn (string $role): string => "orbit-e2e-standby-{$role}", $roles);
        $state->write('standby/cold-attempts/'.str_repeat('a', 32).'.json', [
            'schema' => 3,
            'operation_id' => str_repeat('a', 32),
            'evidence_id' => str_repeat('a', 32),
            'remote' => 'local',
            'project' => 'default',
            'pool' => 'orbit-e2e',
            'network' => ['name' => 'oe-standby', 'state' => 'created', 'absent_preflight' => true],
            'base_image_fingerprint' => str_repeat('f', 64),
            'instances' => array_map(
                fn (string $role, string $name): array => [
                    'role' => $role,
                    'name' => $name,
                    'network' => 'oe-standby',
                    'state' => 'created',
                    'absent_preflight' => true,
                ],
                $roles,
                $instances,
            ),
            'status' => 'creating',
        ]);
        $deleted = [];
        $networkExists = true;
        Process::fake(function (PendingProcess $process) use (&$deleted, &$networkExists, $instances) {
            $command = $process->command;
            assert(is_array($command), 'Incus uses argument arrays.');
            if (($firewall = standby_firewall_result($command)) !== null) {
                return $firewall;
            }
            if ($command === standby_incus_command('network', 'list', 'local:', '--format=json')) {
                return Process::result(json_encode(
                    $networkExists
                        ? [[
                            'name' => 'oe-standby',
                            'config' => [
                                'user.orbit.e2e.owner' => 'orbit-e2e',
                                'user.orbit.e2e.operation' => str_repeat('a', 32),
                                'user.orbit.e2e.evidence' => str_repeat('a', 32),
                            ],
                        ]] : [],
                    JSON_THROW_ON_ERROR,
                ));
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
                            'user.orbit.e2e.operation' => str_repeat('a', 32),
                            'user.orbit.e2e.evidence' => str_repeat('a', 32),
                        ],
                        'devices' => ['root' => ['pool' => 'orbit-e2e'], 'eth0' => ['network' => 'oe-standby']],
                    ],
                    array_values(array_diff($instances, $deleted)),
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
                $exists = in_array($name, $instances, true) && ! in_array($name, $deleted, true);

                return Process::result(json_encode(
                    $exists
                        ? [[
                            'name' => $name,
                            'type' => 'virtual-machine',
                            'status' => 'Stopped',
                            'status_code' => 102,
                            'config' => [
                                'user.orbit.e2e.owner' => 'orbit-e2e',
                                'user.orbit.e2e.operation' => str_repeat('a', 32),
                                'user.orbit.e2e.evidence' => str_repeat('a', 32),
                            ],
                            'devices' => [
                                'root' => ['pool' => 'orbit-e2e'],
                                'eth0' => ['network' => 'oe-standby'],
                            ],
                        ]] : [],
                    JSON_THROW_ON_ERROR,
                ));
            }
            if ($command === standby_incus_command('network', 'delete', 'local:oe-standby')) {
                $networkExists = false;

                return Process::result();
            } else {
                foreach ($instances as $instance) {
                    if ($command === standby_incus_command('delete', "local:{$instance}")) {
                        $deleted[] = $instance;

                        return Process::result();
                    }
                }
            }

            throw new RuntimeException(json_encode($command, JSON_THROW_ON_ERROR));
        });

        $builder = cold_cleanup_builder(new IncusHost(pool: 'orbit-e2e'), $state, $paths);
        $cleaned = $builder->cleanupCold(str_repeat('a', 32), new OperationId(str_repeat('a', 32)));

        expect($cleaned)
            ->toBeTrue()
            ->and($deleted)
            ->toBe(array_reverse($instances))
            ->and($networkExists)
            ->toBeFalse()
            ->and($state->read('standby/recovery/'.str_repeat('a', 32).'.json')['recovered'])
            ->toBeTrue()
            ->and($builder->cleanupCold(str_repeat('a', 32), new OperationId(str_repeat('a', 32))))
            ->toBeTrue();
    })->with([
        'after one VM' => 1,
        'after two VMs' => 2,
        'after three VMs' => 3,
        'after source sync' => 3,
    ]);

    it('adopts and deletes a planned VM when init reports failure after remote creation', function () {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-builder-'.bin2hex(random_bytes(4)));
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
                                'user.orbit.e2e.evidence' => str_repeat('d', 32),
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
                    'user.orbit.e2e.evidence=dddddddddddddddddddddddddddddddd',
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
                            'user.orbit.e2e.evidence' => str_repeat('d', 32),
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
                                'user.orbit.e2e.evidence' => str_repeat('d', 32),
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
                    'root,pool=orbit-e2e,size=16GiB',
                    '--device',
                    'eth0,network=oe-standby,hwaddr=00:16:3e:77:ee:5a',
                    '--config',
                    'user.orbit.e2e.owner=orbit-e2e',
                    '--config',
                    'user.orbit.e2e.operation=dddddddddddddddddddddddddddddddd',
                    '--config',
                    'user.orbit.e2e.evidence=dddddddddddddddddddddddddddddddd',
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
            str_repeat('d', 32),
        ))
            ->toThrow(RuntimeException::class, 'Incus VM initialization batch failed');

        expect($deleted)
            ->toBe(['orbit-e2e-standby-gateway', 'oe-standby'])
            ->and($state->read('standby/recovery/'.str_repeat('d', 32).'.json')['recovered'])
            ->toBeTrue();
    });

    it('preflights the complete topology before any create when a later VM already exists', function () {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-builder-'.bin2hex(random_bytes(4)));
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
                        'user.orbit.e2e.evidence' => str_repeat('e', 32),
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
                        'user.orbit.e2e.evidence' => str_repeat('e', 32),
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
            $evidence,
        ))
            ->toThrow(RuntimeException::class, 'already exists');

        expect($observed)
            ->toBe([
                'network',
                'inventory',
            ])
            ->and($state->read("standby/cold-attempts/{$evidence}.json"))
            ->toBeNull();
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

    it('refuses all cleanup mutations when any recorded resource has mismatched ownership', function () {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-builder-'.bin2hex(random_bytes(4)));
        $state = new AtomicJsonStore($paths);
        $evidence = str_repeat('b', 32);
        $state->write("standby/cold-attempts/{$evidence}.json", [
            'schema' => 3,
            'operation_id' => $evidence,
            'evidence_id' => $evidence,
            'remote' => 'local',
            'project' => 'default',
            'pool' => 'orbit-e2e',
            'network' => ['name' => 'oe-standby', 'state' => 'created', 'absent_preflight' => true],
            'base_image_fingerprint' => str_repeat('f', 64),
            'instances' => [[
                'role' => 'gateway',
                'name' => 'orbit-e2e-standby-gateway',
                'network' => 'oe-standby',
                'state' => 'planned',
                'absent_preflight' => true,
            ]],
            'status' => 'creating',
        ]);
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            assert(is_array($command), 'Incus uses argument arrays.');
            if (($firewall = standby_firewall_result($command)) !== null) {
                return $firewall;
            }
            if ($command === standby_incus_command('list', 'local:orbit-e2e-standby-gateway', '--format=json')) {
                $name = 'orbit-e2e-standby-gateway';

                return Process::result(json_encode([[
                    'name' => $name,
                    'type' => 'virtual-machine',
                    'status' => 'Stopped',
                    'status_code' => 102,
                    'config' => ['user.orbit.e2e.owner' => 'unrelated'],
                    'devices' => ['root' => ['pool' => 'orbit-e2e'], 'eth0' => ['network' => 'oe-standby']],
                ]], JSON_THROW_ON_ERROR));
            }

            throw new RuntimeException(json_encode($command, JSON_THROW_ON_ERROR));
        });

        $builder = cold_cleanup_builder(new IncusHost(pool: 'orbit-e2e'), $state, $paths);
        $cleaned = $builder->cleanupCold($evidence, new OperationId($evidence));

        expect($cleaned)->toBeFalse()->and($state->read('standby/corrupt.json')['evidence_id'])->toBe($evidence);
        Process::assertDidntRun(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && in_array(
                    $process->command,
                    [
                        standby_incus_command('delete', 'local:orbit-e2e-standby-gateway'),
                        standby_incus_command('network', 'delete', 'local:oe-standby'),
                    ],
                    true,
                )
            ),
        );
        expect(fn () => $builder->build(
            str_repeat('a', 40),
            new PreparedFingerprint(str_repeat('b', 64)),
            str_repeat('c', 64),
            new LaravelRelease('v13.0.0', str_repeat('d', 40)),
            true,
            new OperationId(str_repeat('e', 32)),
            str_repeat('e', 32),
        ))
            ->toThrow(RuntimeException::class, 'blocked until explicit recovery');
    });

    it('fails closed when a recorded resource persists after deletion', function () {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-builder-'.bin2hex(random_bytes(4)));
        $state = new AtomicJsonStore($paths);
        $evidence = str_repeat('c', 32);
        $state->write("standby/cold-attempts/{$evidence}.json", [
            'schema' => 3,
            'operation_id' => $evidence,
            'evidence_id' => $evidence,
            'remote' => 'local',
            'project' => 'default',
            'pool' => 'orbit-e2e',
            'network' => ['name' => 'oe-standby', 'state' => 'created', 'absent_preflight' => true],
            'base_image_fingerprint' => str_repeat('f', 64),
            'instances' => [],
            'status' => 'creating',
        ]);
        $networkExists = true;
        Process::fake(function (PendingProcess $process) use (&$networkExists) {
            $command = $process->command;
            assert(is_array($command), 'Incus uses argument arrays.');
            if (($firewall = standby_firewall_result($command)) !== null) {
                return $firewall;
            }
            if ($command === standby_incus_command('network', 'list', 'local:', '--format=json')) {
                return Process::result(json_encode(
                    $networkExists
                        ? [[
                            'name' => 'oe-standby',
                            'config' => [
                                'user.orbit.e2e.owner' => 'orbit-e2e',
                                'user.orbit.e2e.operation' => str_repeat('a', 32),
                                'user.orbit.e2e.evidence' => str_repeat('a', 32),
                            ],
                        ]]
                        : [],
                    JSON_THROW_ON_ERROR,
                ));
            }
            if ($command === standby_incus_command('network', 'delete', 'local:oe-standby'))
                return Process::result();
            throw new RuntimeException(json_encode($command, JSON_THROW_ON_ERROR));
        });
        $builder = cold_cleanup_builder(new IncusHost(pool: 'orbit-e2e'), $state, $paths);
        expect($builder->cleanupCold($evidence, new OperationId($evidence)))
            ->toBeFalse()
            ->and($state->read("standby/recovery/{$evidence}.json")['recovered'])
            ->toBeFalse();
    });

    it('clears only a corrupt marker with matching evidence after exact recovery', function () {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-builder-'.bin2hex(random_bytes(4)));
        $state = new AtomicJsonStore($paths);
        $evidence = str_repeat('d', 32);
        $state->write("standby/cold-attempts/{$evidence}.json", [
            'schema' => 3,
            'operation_id' => $evidence,
            'evidence_id' => $evidence,
            'remote' => 'local',
            'project' => 'default',
            'pool' => 'orbit-e2e',
            'network' => ['name' => 'oe-standby', 'state' => 'cleaned', 'absent_preflight' => true],
            'base_image_fingerprint' => str_repeat('f', 64),
            'instances' => [],
            'status' => 'cleaned',
        ]);
        $state->write('standby/corrupt.json', ['schema' => 1, 'evidence_id' => $evidence, 'message' => 'x']);
        Process::fake(function (PendingProcess $process): ProcessResult {
            if ($process->command === standby_incus_command('network', 'list', 'local:', '--format=json')) {
                return Process::result('[]');
            }

            throw new RuntimeException(json_encode($process->command, JSON_THROW_ON_ERROR));
        });
        $builder = cold_cleanup_builder(new IncusHost(pool: 'orbit-e2e'), $state, $paths);
        expect($builder->cleanupCold($evidence, new OperationId($evidence)))
            ->toBeTrue()
            ->and($state->read('standby/corrupt.json'))
            ->toBeNull();
    });

    it('retains a corrupt marker for different evidence after exact recovery', function () {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-builder-'.bin2hex(random_bytes(4)));
        $state = new AtomicJsonStore($paths);
        $evidence = str_repeat('e', 32);
        $state->write("standby/cold-attempts/{$evidence}.json", [
            'schema' => 3,
            'operation_id' => $evidence,
            'evidence_id' => $evidence,
            'remote' => 'local',
            'project' => 'default',
            'pool' => 'orbit-e2e',
            'network' => ['name' => 'oe-standby', 'state' => 'cleaned', 'absent_preflight' => true],
            'base_image_fingerprint' => str_repeat('f', 64),
            'instances' => [],
            'status' => 'cleaned',
        ]);
        $state->write('standby/corrupt.json', ['schema' => 1, 'evidence_id' => str_repeat('f', 32), 'message' => 'x']);
        Process::fake(function (PendingProcess $process): ProcessResult {
            if ($process->command === standby_incus_command('network', 'list', 'local:', '--format=json')) {
                return Process::result('[]');
            }

            throw new RuntimeException(json_encode($process->command, JSON_THROW_ON_ERROR));
        });
        $builder = cold_cleanup_builder(new IncusHost(pool: 'orbit-e2e'), $state, $paths);
        expect($builder->cleanupCold($evidence, new OperationId($evidence)))
            ->toBeTrue()
            ->and($state->read('standby/corrupt.json')['evidence_id'])
            ->toBe(str_repeat('f', 32));
    });
});
