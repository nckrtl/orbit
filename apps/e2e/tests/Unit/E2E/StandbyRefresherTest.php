<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use App\E2E\IncusHost;
use App\E2E\LaravelReleaseResolver;
use App\E2E\PreparedStateFingerprint;
use App\E2E\RefreshRequestStore;
use App\E2E\StandbyBuilder;
use App\E2E\StandbyManifestStore;
use App\E2E\StandbyRefresher;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationJournal;
use App\E2E\State\OperationLock;
use App\E2E\TopologyConverger;
use App\E2E\TopologyVerifier;
use App\E2E\Value\MigrationPlan;
use App\E2E\Value\RefreshResult;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

function standbyRefresherForPowerTests(IncusHost $host): StandbyRefresher
{
    $uninitialized = fn (string $class): object => new ReflectionClass($class)->newInstanceWithoutConstructor();

    return new StandbyRefresher(
        $host,
        $uninitialized(PreparedStateFingerprint::class),
        $uninitialized(StandbyManifestStore::class),
        $uninitialized(RefreshRequestStore::class),
        $uninitialized(StandbyBuilder::class),
        $uninitialized(WorktreeSynchronizer::class),
        $uninitialized(TopologyConverger::class),
        $uninitialized(TopologyVerifier::class),
        $uninitialized(LaravelReleaseResolver::class),
        $uninitialized(OperationLock::class),
        $uninitialized(OperationJournal::class),
        $uninitialized(AtomicJsonStore::class),
        $uninitialized(GitRepository::class),
        __DIR__,
    );
}

describe('StandbyRefresher contracts', function () {
    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    });

    it('keeps result and external migration identities exact', function () {
        $plan = MigrationPlan::fromArray([
            'fingerprint' => str_repeat('a', 64),
            'steps' => [[
                'role' => 'gateway',
                'argv' => ['install', '-m', '0600', '/dev/stdin', '/tmp/config'],
                'stdin' => "value=yes\n",
            ]],
        ]);
        $result = new RefreshResult('promoted', str_repeat('b', 32), str_repeat('c', 32), 'generation-1');

        expect($plan->steps[0]['role'])
            ->toBe('gateway')
            ->and($plan->steps[0]['stdin'])
            ->toBe("value=yes\n")
            ->and($result->toArray()['state'])
            ->toBe('promoted');
    });

    it('rejects migration targets outside the fixed standby roles', function () {
        expect(fn () => new MigrationPlan(str_repeat('a', 64), [[
            'role' => 'database',
            'argv' => ['true'],
            'stdin' => '',
        ]]))
            ->toThrow(InvalidArgumentException::class);
    });

    it('checks all three stopped VMs without starting or verifying them', function () {
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            assert(is_array($command), 'Incus uses argument arrays.');
            $name = preg_replace('/\A[^:]+:/', '', $command[count($command) - 2]);

            return Process::result(json_encode([[
                'name' => $name,
                'type' => 'virtual-machine',
                'status' => 'Stopped',
                'status_code' => 102,
                'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                'devices' => ['root' => ['pool' => 'orbit-e2e']],
            ]], JSON_THROW_ON_ERROR));
        });
        $refresher = standbyRefresherForPowerTests(new IncusHost(pool: 'orbit-e2e'));

        new ReflectionMethod($refresher, 'assertStopped')->invoke($refresher);

        Process::assertRanTimes(
            fn (PendingProcess $process): bool => (
                is_array($process->command) && in_array('list', $process->command, true)
            ),
            3,
        );
        Process::assertDidntRun(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && (in_array('start', $process->command, true) || in_array('exec', $process->command, true))
            ),
        );
    });

    it('stops running VMs and reads all three again before continuing', function () {
        $stopped = [];
        Process::fake(function (PendingProcess $process) use (&$stopped) {
            $command = $process->command;
            assert(is_array($command), 'Incus uses argument arrays.');
            if (in_array('stop', $command, true)) {
                $stopped[] = preg_replace('/\A[^:]+:/', '', $command[count($command) - 1]);

                return Process::result();
            }
            $name = preg_replace('/\A[^:]+:/', '', $command[count($command) - 2]);
            $isStopped = in_array($name, $stopped, true);

            return Process::result(json_encode([[
                'name' => $name,
                'type' => 'virtual-machine',
                'status' => $isStopped ? 'Stopped' : 'Running',
                'status_code' => $isStopped ? 102 : 103,
                'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                'devices' => ['root' => ['pool' => 'orbit-e2e']],
            ]], JSON_THROW_ON_ERROR));
        });
        $refresher = standbyRefresherForPowerTests(new IncusHost(pool: 'orbit-e2e'));

        new ReflectionMethod($refresher, 'stopAndProve')->invoke($refresher);

        expect($stopped)->toBe([
            'orbit-e2e-standby-app-prod',
            'orbit-e2e-standby-app-dev',
            'orbit-e2e-standby-gateway',
        ]);
        Process::assertRanTimes(
            fn (PendingProcess $process): bool => (
                is_array($process->command) && in_array('list', $process->command, true)
            ),
            9,
        );
    });
});
