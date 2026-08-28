<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\StandbyBuilder;
use App\E2E\StandbyManifestStore;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\TopologyConverger;
use App\E2E\TopologyVerifier;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\PreparedFingerprint;
use App\E2E\Value\TopologyTarget;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

function cold_cleanup_builder(IncusHost $host, AtomicJsonStore $state, StatePaths $paths): StandbyBuilder
{
    /** @mago-expect analysis:possibly-invalid-argument Test helpers resolve only known class names. */
    $uninitialized = fn (string $class): object => new ReflectionClass($class)->newInstanceWithoutConstructor();

    return new StandbyBuilder(
        $host,
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
            str_repeat('e', 32),
        ))
            ->toThrow(RuntimeException::class, 'explicit permission');
    });

    it('accepts a fully cleaned attempt recorded with the former standby network identity', function () {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-builder-'.bin2hex(random_bytes(4)));
        $state = new AtomicJsonStore($paths);
        $previousEvidence = str_repeat('a', 32);
        $state->write("standby/cold-attempts/{$previousEvidence}.json", [
            'schema' => 2,
            'operation_id' => $previousEvidence,
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
            str_repeat('e', 32),
        ))
            ->toThrow(RuntimeException::class, 'no base image alias');
    });

    it('rejects an active attempt recorded with the former standby network identity', function () {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-builder-'.bin2hex(random_bytes(4)));
        $state = new AtomicJsonStore($paths);
        $previousEvidence = str_repeat('a', 32);
        $state->write("standby/cold-attempts/{$previousEvidence}.json", [
            'schema' => 2,
            'operation_id' => $previousEvidence,
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
            'schema' => 2,
            'operation_id' => str_repeat('a', 32),
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
            if ($command === standby_incus_command('network', 'list', 'local:', '--format=json')) {
                return Process::result(json_encode(
                    $networkExists
                        ? [[
                            'name' => 'oe-standby',
                            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                        ]] : [],
                    JSON_THROW_ON_ERROR,
                ));
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
                            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
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
        $cleaned = $builder->cleanupCold(str_repeat('a', 32));

        expect($cleaned)
            ->toBeTrue()
            ->and($deleted)
            ->toBe(array_reverse($instances))
            ->and($networkExists)
            ->toBeFalse()
            ->and($state->read('standby/recovery/'.str_repeat('a', 32).'.json')['recovered'])
            ->toBeTrue()
            ->and($builder->cleanupCold(str_repeat('a', 32)))
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
                            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                        ]] : [],
                    JSON_THROW_ON_ERROR,
                ));
            }
            if (
                $command === standby_incus_command(
                    'network',
                    'create',
                    'local:oe-standby',
                    'ipv4.address=auto',
                    'ipv4.nat=true',
                    'user.orbit.e2e.owner=orbit-e2e',
                )
            ) {
                $networkExists = true;

                return Process::result();
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
                            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
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
                    '--network',
                    'oe-standby',
                    '--config',
                    'user.orbit.e2e.owner=orbit-e2e',
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
            str_repeat('d', 32),
        ))
            ->toThrow(RuntimeException::class, 'Incus command failed');

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
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
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
            $evidence,
        ))
            ->toThrow(RuntimeException::class, 'already exists');

        expect($observed)
            ->toBe([
                'network',
                'orbit-e2e-standby-gateway',
                'orbit-e2e-standby-app-dev',
                'orbit-e2e-standby-app-prod',
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
                            'ipv4.address=auto',
                            'ipv4.nat=true',
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
            'schema' => 2,
            'operation_id' => $evidence,
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
        $cleaned = $builder->cleanupCold($evidence);

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
            str_repeat('e', 32),
        ))
            ->toThrow(RuntimeException::class, 'blocked until explicit recovery');
    });
});
