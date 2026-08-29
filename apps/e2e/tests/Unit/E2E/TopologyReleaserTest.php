<?php

declare(strict_types=1);

use App\E2E\AcquisitionRollback;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\TopologyManifestStore;
use App\E2E\TopologyReleaser;
use App\E2E\Value\AttemptId;
use App\E2E\Value\OperationId;
use App\E2E\Value\ReleaseResult;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
    Process::preventStrayProcesses();
});

/** @mago-expect lint:cyclomatic-complexity The release scenarios keep exact cleanup behavior visible. */
describe('topology release', function () {
    it('removes an abandoned acquisition manifest after exact cleanup', function () {
        Process::fake(['*' => Process::result('[]')]);
        $root = temporaryPath('orbit-release-', 8);
        $paths = new StatePaths($root);
        $store = new AtomicJsonStore($paths);
        readyReleaseState($store);
        $store->write('leases/NCK-12.json', [
            'schema' => 2,
            'issue' => 'NCK-12',
            'attempt' => releaseAttempt()->value,
            'state' => 'acquiring',
            'operation_id' => str_repeat('a', 32),
            'expires_at' => '2020-01-01T00:00:00+00:00',
            'pid' => 999999,
            'process_start_identity' => 'dead-test-owner',
            'acquired_at' => '2020-01-01T00:00:00+00:00',
        ]);
        $target = featureTarget('NCK-12');
        $rollback = new AcquisitionRollback(
            static fn (array $resources): array => array_fill_keys($resources, null),
            static function (array $resources): void {},
            static function (array $resources): void {},
            static function (string $resource): void {},
        );
        $releaser = new TopologyReleaser(
            new IncusHost,
            new IncusNetworkLifecycle(new IncusHost),
            new TopologyManifestStore($store),
            $store,
            $paths,
            new OperationId(str_repeat('b', 32)),
            acquisitionRollback: $rollback,
        );

        $result = $releaser->release($target->issue);

        expect($result->alreadyAbsent)
            ->toHaveCount(count(TopologyProfile::ROLES) + 1)
            ->and($store->read(releaseTopologyPath()))
            ->toBeNull()
            ->and($store->read('leases/NCK-12.json'))
            ->toBeNull()
            ->and($store->read('releases/NCK-12.json'))
            ->not->toBeNull();
    });

    function releaseAttempt(): AttemptId
    {
        return new AttemptId(str_repeat('a', 32));
    }

    function releaseTopologyPath(string $issue = 'NCK-12'): string
    {
        return 'topologies/'.$issue.'/'.releaseAttempt()->value.'.json';
    }

    function readyReleaseState(AtomicJsonStore $store, string $issue = 'NCK-12'): void
    {
        $target = featureTarget($issue);
        $store->write('leases/'.$issue.'.json', [
            'schema' => 2,
            'issue' => $issue,
            'attempt' => releaseAttempt()->value,
            'state' => 'ready',
            'operation_id' => str_repeat('a', 32),
        ]);
        $store->write(releaseTopologyPath($issue), [
            'schema' => 2,
            'issue' => $issue,
            'attempt_id' => releaseAttempt()->value,
            'purpose' => 'discovery',
            'profile' => TopologyProfile::NAME,
            'generation' => [
                'schema' => 4,
                'id' => 'generation-1',
                'main_sha' => str_repeat('a', 40),
                'snapshots' => [
                    'gateway' => 'main-gateway',
                    'app-dev' => 'main-app-dev',
                    'app-prod' => 'main-app-prod',
                ],
                'prepared_fingerprint' => str_repeat('a', 64),
                'base_image_fingerprint' => str_repeat('b', 64),
                'structural_fingerprint' => str_repeat('e', 64),
                'prepared_schema' => 1,
                'cold_epoch' => 'ubuntu-26.04-amd64-v1',
                'base_image_alias' => 'orbit-base-ubuntu-26.04-runtime',
                'topology' => [
                    'profile' => TopologyProfile::NAME,
                    'roles' => TopologyProfile::ROLES,
                    'checkout_roles' => TopologyProfile::CHECKOUT_ROLES,
                ],
                'laravel_pin' => ['tag' => 'v1.0.0', 'commit' => str_repeat('c', 40)],
                'previous_generation_id' => null,
            ],
            'network' => $target->network(),
            'instances' => array_combine(TopologyProfile::ROLES, array_map(
                $target->instance(...),
                TopologyProfile::ROLES,
            )),
            'source' => [
                'host_sha' => str_repeat('d', 40),
                'guest_sha' => str_repeat('e', 40),
                'dirty' => false,
                'tree_hash' => null,
                'overlay_paths' => [],
                'operation_id' => null,
            ],
            'verification' => [
                'passed' => true,
                'probes' => ['ready' => verificationProbeFixture(probe: 'ready')],
            ],
        ]);
        $store->write('topologies/'.$issue.'/active.json', [
            'schema' => 2,
            'issue' => $issue,
            'attempt' => releaseAttempt()->value,
        ]);
    }

    function completedReleaseState(AtomicJsonStore $store): void
    {
        readyReleaseState($store);
        $store->write(
            'releases/NCK-12.json',
            new ReleaseResult('a'.str_repeat('0', 31), 'b'.str_repeat('0', 31), ['deleted:old'], [])->toArray(),
        );
    }

    /** @param array<array-key, mixed> $value */
    function releaseStateDigest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    it('refuses retained evidence while active artifacts exist', function () {
        $root = temporaryPath('orbit-release-', 8);
        $paths = new StatePaths($root);
        $store = new AtomicJsonStore($paths);
        completedReleaseState($store);
        $releaser = new TopologyReleaser(
            new IncusHost,
            new IncusNetworkLifecycle(new IncusHost),
            new TopologyManifestStore($store),
            $store,
            $paths,
            new OperationId(str_repeat('a', 32)),
        );
        Process::preventStrayProcesses();
        expect(fn () => $releaser->release('NCK-12'))
            ->toThrow(RuntimeException::class, 'active topology state');
        expect($store->read('leases/NCK-12.json'))
            ->not->toBeNull()->and($store->read(releaseTopologyPath()))
            ->not->toBeNull();
        Process::assertNothingRan();
    });

    it('preserves a retained lease when the manifest is absent but lease is current', function () {
        $root = temporaryPath('orbit-release-', 8);
        $paths = new StatePaths($root);
        $store = new AtomicJsonStore($paths);
        completedReleaseState($store);
        unlink($paths->root().'/'.releaseTopologyPath());
        unlink($paths->root().'/topologies/NCK-12/active.json');
        Process::preventStrayProcesses();
        $releaser = new TopologyReleaser(
            new IncusHost,
            new IncusNetworkLifecycle(new IncusHost),
            new TopologyManifestStore($store),
            $store,
            $paths,
            new OperationId(str_repeat('a', 32)),
        );
        expect(fn () => $releaser->release('NCK-12'))
            ->toThrow(RuntimeException::class, 'active topology state');
        expect($store->read('leases/NCK-12.json'))->not->toBeNull();
        Process::assertNothingRan();
    });

    it('refuses retained evidence when the manifest is malformed', function () {
        $root = temporaryPath('orbit-release-', 8);
        $paths = new StatePaths($root);
        $store = new AtomicJsonStore($paths);
        completedReleaseState($store);
        file_put_contents($paths->root().'/'.releaseTopologyPath(), '{malformed');
        Process::preventStrayProcesses();

        $releaser = new TopologyReleaser(
            new IncusHost,
            new IncusNetworkLifecycle(new IncusHost),
            new TopologyManifestStore($store),
            $store,
            $paths,
            new OperationId(str_repeat('a', 32)),
        );
        expect(fn () => $releaser->release('NCK-12'))
            ->toThrow(RuntimeException::class, 'active topology state');
        expect($store->read('leases/NCK-12.json'))
            ->not
            ->toBeNull()
            ->and(is_link($paths->root().'/'.releaseTopologyPath()))
            ->toBeFalse()
            ->and(file_exists($paths->root().'/'.releaseTopologyPath()))
            ->toBeTrue();
        Process::assertNothingRan();
    });

    it('keeps evidence identity across a local state failure boundary', function () {
        $root = temporaryPath('orbit-release-', 8);
        $paths = new StatePaths($root);
        $store = new AtomicJsonStore($paths);
        completedReleaseState($store);
        unlink($paths->root().'/leases/NCK-12.json');
        symlink('/tmp', $paths->root().'/leases/NCK-12.json');
        $releaser = new TopologyReleaser(
            new IncusHost,
            new IncusNetworkLifecycle(new IncusHost),
            new TopologyManifestStore($store),
            $store,
            $paths,
            new OperationId(str_repeat('a', 32)),
        );
        Process::preventStrayProcesses();
        expect(fn () => $releaser->release('NCK-12'))
            ->toThrow(RuntimeException::class, 'active topology state');
        Process::assertNothingRan();
        expect($store->read('releases/NCK-12.json')['evidence_id'])->toBe('b'.str_repeat('0', 31));
        unlink($paths->root().'/leases/NCK-12.json');
        expect(fn () => $releaser->release('NCK-12'))
            ->toThrow(RuntimeException::class, 'active topology state');
        expect($store->read(releaseTopologyPath()))->not->toBeNull();
        Process::assertNothingRan();
    });
    it('returns compact exact release evidence', function () {
        $result = new ReleaseResult(
            str_repeat('a', 32),
            str_repeat('b', 32),
            ['deleted:orbit-e2e-nck-12'],
            ['orbit-e2e-nck-12-aaaaaaaa-app-prod'],
        );

        expect($result->toArray())->toBe([
            'state' => 'released',
            'operation_id' => str_repeat('a', 32),
            'evidence_id' => str_repeat('b', 32),
            'released' => ['deleted:orbit-e2e-nck-12'],
            'already_absent' => ['orbit-e2e-nck-12-aaaaaaaa-app-prod'],
        ]);
    });

    /** @mago-expect lint:cyclomatic-complexity The case tracks the complete release state transition. */
    it('persists the injected operation identity for a completed release', function () {
        $paths = new StatePaths(temporaryPath('orbit-release-complete-', 8));
        $store = new AtomicJsonStore($paths);
        $target = featureTarget('NCK-123');
        readyReleaseState($store, $target->issue);
        $instances = array_fill_keys(array_map($target->instance(...), TopologyProfile::ROLES), true);
        $networkExists = true;
        $commands = [];

        /** @mago-expect lint:cyclomatic-complexity The process fake models exact external cleanup branches. */
        Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
            &$instances,
            &$networkExists,
            &$commands,
            $target,
        ) {
            $command = $process->command;
            $commands[] = $command;
            if (
                ($command[0] ?? null) === 'python3'
                && str_ends_with((string) ($command[1] ?? ''), '/resources/host/reconcile-firewall.py')
            ) {
                return Process::result('{"changed":true}');
            }
            if (array_slice($command, 0, 5) === ['sudo', '-n', 'iptables', '-w', '5']) {
                return in_array('-C', $command, true) ? Process::result('', '', 1) : Process::result();
            }
            if (($command[3] ?? null) === 'list') {
                $name = preg_replace('/\A[^:]+:/', '', (string) ($command[4] ?? ''));
                if ($name === '') {
                    return Process::result(json_encode(array_map(
                        static fn (string $instance): array => [
                            'name' => $instance,
                            'type' => 'virtual-machine',
                            'status' => 'Running',
                            'status_code' => 103,
                            'config' => [
                                'user.orbit.e2e.owner' => 'orbit-e2e',
                                'user.orbit.e2e.issue' => $target->issue,
                                'user.orbit.e2e.attempt' => releaseAttempt()->value,
                                'user.orbit.e2e.generation' => 'generation-1',
                                'user.orbit.e2e.operation' => str_repeat('a', 32),
                            ],
                            'devices' => [
                                'root' => ['pool' => 'default'],
                                'eth0' => [
                                    'network' => $target->network(),
                                    'hwaddr' => $target->mac(match (true) {
                                        str_ends_with($instance, '-gateway') => 'gateway',
                                        str_ends_with($instance, '-app-dev') => 'app-dev',
                                        default => 'app-prod',
                                    }),
                                ],
                            ],
                        ],
                        array_keys($instances),
                    ), JSON_THROW_ON_ERROR));
                }
                if (! isset($instances[$name])) {
                    return Process::result('[]');
                }

                return Process::result(json_encode([[
                    'name' => $name,
                    'type' => 'virtual-machine',
                    'status' => 'Running',
                    'status_code' => 103,
                    'config' => [
                        'user.orbit.e2e.owner' => 'orbit-e2e',
                        'user.orbit.e2e.issue' => $target->issue,
                        'user.orbit.e2e.attempt' => releaseAttempt()->value,
                        'user.orbit.e2e.generation' => 'generation-1',
                        'user.orbit.e2e.operation' => str_repeat('a', 32),
                    ],
                    'devices' => [
                        'root' => ['pool' => 'default'],
                        'eth0' => [
                            'network' => $target->network(),
                            'hwaddr' => $target->mac(match (true) {
                                str_ends_with($name, '-gateway') => 'gateway',
                                str_ends_with($name, '-app-dev') => 'app-dev',
                                default => 'app-prod',
                            }),
                        ],
                    ],
                ]], JSON_THROW_ON_ERROR));
            }
            if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'list') {
                return Process::result(json_encode(
                    $networkExists
                        ? [[
                            'name' => $target->network(),
                            'config' => [
                                'user.orbit.e2e.owner' => 'orbit-e2e',
                                'user.orbit.e2e.issue' => $target->issue,
                                'user.orbit.e2e.attempt' => releaseAttempt()->value,
                                'user.orbit.e2e.operation' => str_repeat('a', 32),
                            ],
                        ]] : [],
                    JSON_THROW_ON_ERROR,
                ));
            }
            if (($command[3] ?? null) === 'delete') {
                $name = preg_replace('/\A[^:]+:/', '', (string) ($command[4] ?? ''));
                unset($instances[$name]);

                return Process::result();
            }
            if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'delete') {
                $networkExists = false;

                return Process::result();
            }

            return Process::result();
        });

        $operation = new OperationId(str_repeat('a', 32));
        $host = new IncusHost;
        $result = new TopologyReleaser(
            $host,
            new IncusNetworkLifecycle($host),
            new TopologyManifestStore($store),
            $store,
            $paths,
            $operation,
        )->release('NCK-123');
        $stored = $store->read('releases/NCK-123.json');

        expect($result->operationId)
            ->toBe($operation->value)
            ->and($result->evidenceId)
            ->not
            ->toBe($operation->value)
            ->and($stored)
            ->toBe($result->toArray())
            ->and($instances)
            ->toBe([])
            ->and($networkExists)
            ->toBeFalse();
        $stops = array_values(array_filter(
            $commands,
            static fn (array $command): bool => ($command[3] ?? null) === 'stop',
        ));
        expect($stops)
            ->toHaveCount(3)
            ->and(collect($stops)->every(
                static fn (array $command): bool => in_array('--force', $command, true),
            ))
            ->toBeTrue();
    });

    it('refuses release when the deterministic topology MAC drifted', function () {
        $paths = new StatePaths(temporaryPath('orbit-release-mac-', 8));
        $store = new AtomicJsonStore($paths);
        $target = featureTarget('NCK-123');
        readyReleaseState($store, $target->issue);
        $commands = [];
        Process::fake(function (\Illuminate\Process\PendingProcess $process) use (&$commands, $target) {
            $commands[] = $process->command;
            if (($process->command[3] ?? null) !== 'list') {
                return Process::result('[]');
            }

            return Process::result(json_encode(array_map(
                static fn (string $role): array => [
                    'name' => $target->instance($role),
                    'type' => 'virtual-machine',
                    'status' => 'Running',
                    'status_code' => 103,
                    'config' => [
                        'user.orbit.e2e.owner' => 'orbit-e2e',
                        'user.orbit.e2e.issue' => $target->issue,
                        'user.orbit.e2e.attempt' => releaseAttempt()->value,
                        'user.orbit.e2e.generation' => 'generation-1',
                        'user.orbit.e2e.operation' => str_repeat('a', 32),
                    ],
                    'devices' => [
                        'root' => ['pool' => 'default'],
                        'eth0' => [
                            'network' => $target->network(),
                            'hwaddr' => $role === 'gateway' ? '00:16:3e:00:00:00' : $target->mac($role),
                        ],
                    ],
                ],
                TopologyProfile::ROLES,
            ), JSON_THROW_ON_ERROR));
        });

        $host = new IncusHost;
        $releaser = new TopologyReleaser(
            $host,
            new IncusNetworkLifecycle($host),
            new TopologyManifestStore($store),
            $store,
            $paths,
            new OperationId(str_repeat('a', 32)),
        );

        expect(fn () => $releaser->release($target->issue))
            ->toThrow(RuntimeException::class, 'identity does not match')
            ->and(collect($commands)->contains(
                static fn (array $command): bool => (
                    array_intersect(
                        $command,
                        ['stop', 'delete'],
                    ) !== []
                ),
            ))
            ->toBeFalse();
    });

    it('refuses cleanup without the exact manifest', function () {
        $root = temporaryPath('orbit-release-', 8);
        $paths = new StatePaths($root);
        $store = new AtomicJsonStore($paths);
        $host = new IncusHost;
        $releaser = new TopologyReleaser(
            $host,
            new IncusNetworkLifecycle($host),
            new TopologyManifestStore($store),
            $store,
            $paths,
            new OperationId(str_repeat('a', 32)),
        );

        expect(fn () => $releaser->release('NCK-12'))
            ->toThrow(RuntimeException::class, 'exact feature topology manifest');
    });

    it('returns already absent evidence after an exact completed release', function () {
        Process::fake(['*' => Process::result('[]')]);

        $root = temporaryPath('orbit-release-', 8);
        $paths = new StatePaths($root);
        $store = new AtomicJsonStore($paths);
        $store->write(
            'releases/NCK-12.json',
            new ReleaseResult(
                str_repeat('a', 32),
                str_repeat('b', 32),
                ['deleted:orbit-e2e-nck-12'],
                [],
            )->toArray(),
        );
        $host = new IncusHost;
        $releaser = new TopologyReleaser(
            $host,
            new IncusNetworkLifecycle($host),
            new TopologyManifestStore($store),
            $store,
            $paths,
            new OperationId(str_repeat('a', 32)),
        );

        $result = $releaser->release('NCK-12');

        expect($result->released)
            ->toBe([])
            ->and($result->alreadyAbsent)
            ->toBe(['deleted:orbit-e2e-nck-12'])
            ->and($result->evidenceId)
            ->toBe(str_repeat('b', 32));
    });

    it('refuses success when network deletion leaves the exact network present', function () {
        $paths = new StatePaths(temporaryPath('orbit-release-network-', 8));
        $store = new AtomicJsonStore($paths);
        readyReleaseState($store);
        Process::fake(function (\Illuminate\Process\PendingProcess $process) {
            $command = $process->command;
            if (
                ($command[0] ?? null) === 'python3'
                && str_ends_with((string) ($command[1] ?? ''), '/resources/host/reconcile-firewall.py')
            ) {
                return Process::result('{"changed":true}');
            }
            if (array_slice($command, 0, 5) === ['sudo', '-n', 'iptables', '-w', '5']) {
                return Process::result('', '', in_array('-C', $command, true) ? 1 : 0);
            }
            if (($command[3] ?? null) === 'list') {
                return Process::result('[]');
            }
            if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'list') {
                return Process::result(json_encode([[
                    'name' => featureTarget('NCK-12')->network(),
                    'config' => [
                        'user.orbit.e2e.owner' => 'orbit-e2e',
                        'user.orbit.e2e.issue' => 'NCK-12',
                        'user.orbit.e2e.attempt' => releaseAttempt()->value,
                        'user.orbit.e2e.operation' => str_repeat('a', 32),
                    ],
                ]], JSON_THROW_ON_ERROR));
            }

            return Process::result();
        });

        $host = new IncusHost;
        $releaser = new TopologyReleaser(
            $host,
            new IncusNetworkLifecycle($host),
            new TopologyManifestStore($store),
            $store,
            $paths,
            new OperationId(str_repeat('a', 32)),
        );

        expect(fn () => $releaser->release('NCK-12'))
            ->toThrow(RuntimeException::class, 'resources remain after release deletion');
        expect($store->read('releases/NCK-12.json'))->toBeNull();
    });

    it('finishes an exact pending release after local finalization was interrupted', function () {
        $root = temporaryPath('orbit-release-', 8);
        $paths = new StatePaths($root);
        $store = new AtomicJsonStore($paths);
        readyReleaseState($store);
        $result = new ReleaseResult(
            str_repeat('c', 32),
            str_repeat('b', 32),
            ['deleted:old'],
            ['already-absent:old'],
        );
        $store->write('release-pending/NCK-12.json', [
            'schema' => 1,
            'issue' => 'NCK-12',
            'operation_id' => $result->operationId,
            'evidence_id' => $result->evidenceId,
            'lease_sha256' => releaseStateDigest($store->read('leases/NCK-12.json')),
            'topology_sha256' => releaseStateDigest($store->read(releaseTopologyPath())),
            'result' => $result->toArray(),
        ]);
        Process::fake(['*' => Process::result('[]')]);

        $replayed = new TopologyReleaser(
            new IncusHost,
            new IncusNetworkLifecycle(new IncusHost),
            new TopologyManifestStore($store),
            $store,
            $paths,
            new OperationId(str_repeat('a', 32)),
        )->release('NCK-12');

        expect($replayed->operationId)
            ->toBe(str_repeat('a', 32))
            ->and($replayed->evidenceId)
            ->toBe(str_repeat('b', 32))
            ->and($replayed->released)
            ->toBe([])
            ->and($replayed->alreadyAbsent)
            ->toBe(['deleted:old', 'already-absent:old'])
            ->and($store->read('releases/NCK-12.json'))
            ->toBe($result->toArray())
            ->and($store->read('release-pending/NCK-12.json'))
            ->toBeNull()
            ->and($store->read('leases/NCK-12.json'))
            ->toBeNull()
            ->and($store->read(releaseTopologyPath()))
            ->toBeNull();
    });

    it('preserves active state that does not match pending release identity', function () {
        $root = temporaryPath('orbit-release-', 8);
        $paths = new StatePaths($root);
        $store = new AtomicJsonStore($paths);
        readyReleaseState($store);
        $result = new ReleaseResult(str_repeat('c', 32), str_repeat('b', 32), [], []);
        $store->write('release-pending/NCK-12.json', [
            'schema' => 1,
            'issue' => 'NCK-12',
            'operation_id' => $result->operationId,
            'evidence_id' => $result->evidenceId,
            'lease_sha256' => releaseStateDigest(['state' => 'different']),
            'topology_sha256' => releaseStateDigest($store->read(releaseTopologyPath())),
            'result' => $result->toArray(),
        ]);
        Process::preventStrayProcesses();
        $releaser = new TopologyReleaser(
            new IncusHost,
            new IncusNetworkLifecycle(new IncusHost),
            new TopologyManifestStore($store),
            $store,
            $paths,
            new OperationId(str_repeat('a', 32)),
        );

        expect(fn () => $releaser->release('NCK-12'))
            ->toThrow(RuntimeException::class, 'pending release state does not match');
        expect($store->read('leases/NCK-12.json'))
            ->not->toBeNull()->and($store->read(releaseTopologyPath()))
            ->not->toBeNull()->and($store->read('release-pending/NCK-12.json'))
            ->not->toBeNull()->and($store->read('releases/NCK-12.json'))->toBeNull();
        Process::assertNothingRan();
    });
});
