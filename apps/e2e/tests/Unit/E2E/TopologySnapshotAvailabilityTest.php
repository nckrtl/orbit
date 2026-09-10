<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\StaleTopologySnapshotManifest;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\TopologySnapshotAvailability;
use App\E2E\TopologySnapshotReplacementStore;
use App\E2E\Value\AttemptId;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologySnapshotIdentity;
use App\E2E\Value\TopologySnapshotReplacementInstallation;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

function availabilityGeneration(): TopologySnapshotGeneration
{
    return new TopologySnapshotGeneration(
        'aaaaaaaaaaaa-bbbbbbbbbbbb',
        str_repeat('b', 40),
        array_fill_keys(TopologyProfile::ROLES, 'main-aaaaaaaaaaaa-bbbbbbbbbbbb'),
        str_repeat('c', 64),
        str_repeat('d', 64),
        new LaravelRelease('v13.10.1', str_repeat('e', 40)),
        str_repeat('f', 64),
        2,
        'ubuntu-26.04-amd64-v1',
        'orbit-base-ubuntu-26.04-runtime',
        'gateway_app-dev_app-prod',
        TopologyProfile::ROLES,
        ['gateway', 'app-dev'],
    );
}

function availabilityReplacementInstallation(
    TopologySnapshotGeneration $old,
): TopologySnapshotReplacementInstallation {
    $new = new TopologySnapshotGeneration(
        'replacement-generation',
        str_repeat('6', 40),
        ['gateway' => 'main-replacement-gateway', 'app-dev' => 'main-replacement-app-dev', 'app-prod' => 'main-replacement-app-prod'],
        str_repeat('7', 64),
        $old->baseImageFingerprint,
        $old->laravel,
        str_repeat('8', 64),
        2,
        $old->coldEpoch,
        $old->baseImageAlias,
        TopologyProfile::NAME,
        TopologyProfile::ROLES,
        TopologyProfile::CHECKOUT_ROLES,
        $old->id,
    );

    return new TopologySnapshotReplacementInstallation(
        'AUX-231',
        new AttemptId(str_repeat('a', 32)),
        new AttemptId(str_repeat('b', 32)),
        new OperationId(str_repeat('c', 32)),
        str_repeat('d', 40),
        str_repeat('e', 40),
        str_repeat('f', 40),
        $new->mainSha,
        str_repeat('a', 64),
        str_repeat('b', 64),
        $old,
        $new,
        $new->baseImageAlias,
        $new->baseImageFingerprint,
        'oe-replacement',
        ['gateway' => 'replacement-gateway', 'app-dev' => 'replacement-app-dev', 'app-prod' => 'replacement-app-prod'],
        ['gateway' => 'snapshot-gateway', 'app-dev' => 'snapshot-app-dev', 'app-prod' => 'snapshot-app-prod'],
        ['gateway' => 'snapshot-gateway-next', 'app-dev' => 'snapshot-app-dev-next', 'app-prod' => 'snapshot-app-prod-next'],
        ['gateway' => 'snapshot-gateway-old', 'app-dev' => 'snapshot-app-dev-old', 'app-prod' => 'snapshot-app-prod-old'],
    );
}

/** @param list<string> $instances */
function availabilityInventoryJson(array $instances): string
{
    return json_encode(array_map(
        static fn (string $name): array => [
            'name' => $name,
            'type' => 'virtual-machine',
            'status' => 'Stopped',
            'status_code' => 102,
            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
            'devices' => ['root' => ['pool' => 'orbit-e2e']],
        ],
        $instances,
    ), JSON_THROW_ON_ERROR);
}

/**
 * @param  list<string>  $instances  the VMs the host holds
 * @param  list<string>  $withSnapshot  the VMs that still hold the promoted snapshot
 * @param  string  $owner  the ownership metadata of the snapshots
 */
function fakeAvailabilityHost(
    array $instances,
    array $withSnapshot,
    string $owner = 'orbit-e2e',
): void {
    Process::fake(function (PendingProcess $process) use ($instances, $withSnapshot, $owner) {
        $command = $process->command;
        assert(is_array($command), 'Incus uses argument arrays.');
        if (($command[3] ?? null) === 'snapshot' && ($command[4] ?? null) === 'list') {
            $instance = preg_replace('/\A[^:]+:/', '', (string) ($command[5] ?? ''));

            return Process::result(json_encode(
                in_array($instance, $withSnapshot, true)
                    ? [[
                        'name' => 'main-aaaaaaaaaaaa-bbbbbbbbbbbb',
                        'config' => ['user.orbit.e2e.owner' => $owner],
                    ]]
                    : [],
                JSON_THROW_ON_ERROR,
            ));
        }

        return Process::result(availabilityInventoryJson($instances));
    });
}

describe('TopologySnapshotAvailability', function () {
    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    });

    it('passes when every promoted snapshot is on the persistent topology snapshot VMs', function () {
        $identity = TopologySnapshotIdentity::primary();
        fakeAvailabilityHost($identity->instances(), $identity->instances());

        expect(fn () => new TopologySnapshotAvailability(new IncusHost(pool: 'orbit-e2e'), $identity)
            ->assertAvailable(availabilityGeneration()))
            ->not
            ->toThrow(Throwable::class);
    });

    it('refuses a generation inconsistent with an active replacement before any Incus command', function () {
        $identity = TopologySnapshotIdentity::primary();
        $installation = availabilityReplacementInstallation(availabilityGeneration());
        $replacements = new TopologySnapshotReplacementStore(new AtomicJsonStore(
            new StatePaths(temporaryPath('availability-replacement-', 4)),
        ));
        $replacements->start($installation, '2026-09-10T10:00:00Z');
        Process::fake();

        expect(fn () => new TopologySnapshotAvailability(new IncusHost(pool: 'orbit-e2e'), $identity, $replacements)
            ->assertAvailable($installation->newGeneration))
            ->toThrow(RuntimeException::class, 'replacement recovery is incomplete');

        Process::assertNothingRan();
    });

    it('names the recovery command when the manifest names snapshots the host lost', function () {
        $identity = TopologySnapshotIdentity::primary();
        fakeAvailabilityHost($identity->instances(), [$identity->instance('gateway')]);

        expect(fn () => new TopologySnapshotAvailability(new IncusHost(pool: 'orbit-e2e'), $identity)
            ->assertAvailable(availabilityGeneration()))
            ->toThrow(StaleTopologySnapshotManifest::class, 'bin/e2e-topology-snapshot rebuild');
    });

    it('reports the stale manifest as recoverable rather than corrupt', function () {
        $identity = TopologySnapshotIdentity::primary();
        fakeAvailabilityHost($identity->instances(), []);

        $failure = null;
        try {
            new TopologySnapshotAvailability(new IncusHost(pool: 'orbit-e2e'), $identity)
                ->assertAvailable(availabilityGeneration());
        } catch (StaleTopologySnapshotManifest $exception) {
            $failure = $exception->getMessage();
        }

        expect($failure)
            ->toContain('aaaaaaaaaaaa-bbbbbbbbbbbb')
            ->toContain('was rebuilt or replaced')
            ->toContain(StaleTopologySnapshotManifest::RECOVERY_COMMAND)
            ->not->toContain('checkout')
            ->not->toContain('corrupt state');
    });

    it('reads the sole current topology snapshot identity', function () {
        $identity = TopologySnapshotIdentity::primary();
        fakeAvailabilityHost($identity->instances(), $identity->instances());

        new TopologySnapshotAvailability(new IncusHost(pool: 'orbit-e2e'), $identity)
            ->assertAvailable(availabilityGeneration());

        Process::assertRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && ($process->command[3] ?? null) === 'snapshot'
                && ($process->command[5] ?? null) === 'local:orbit-e2e-topology-snapshot-gateway'
            ),
        );
    });

    it('lets an ownership failure through unchanged, because that is not a stale manifest', function () {
        $identity = TopologySnapshotIdentity::primary();
        fakeAvailabilityHost($identity->instances(), $identity->instances(), owner: 'someone-else');

        expect(fn () => new TopologySnapshotAvailability(new IncusHost(pool: 'orbit-e2e'), $identity)
            ->assertAvailable(availabilityGeneration()))
            ->toThrow(RuntimeException::class, 'ownership metadata does not match')
            ->and(fn () => new TopologySnapshotAvailability(new IncusHost(pool: 'orbit-e2e'), $identity)
                ->assertAvailable(availabilityGeneration()))
            ->not->toThrow(StaleTopologySnapshotManifest::class);
    });
});
