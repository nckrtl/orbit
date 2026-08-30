<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\StaleStandbyManifest;
use App\E2E\StandbyAvailability;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\StandbyGeneration;
use App\E2E\Value\StandbyIdentity;
use App\E2E\Value\TopologyProfile;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

function availabilityGeneration(): StandbyGeneration
{
    return new StandbyGeneration(
        'aaaaaaaaaaaa-bbbbbbbbbbbb',
        str_repeat('b', 40),
        array_fill_keys(TopologyProfile::ROLES, 'main-aaaaaaaaaaaa-bbbbbbbbbbbb'),
        str_repeat('c', 64),
        str_repeat('d', 64),
        new LaravelRelease('v13.10.1', str_repeat('e', 40)),
        str_repeat('f', 64),
        1,
        'ubuntu-26.04-amd64-v1',
        'orbit-base-ubuntu-26.04-runtime',
        'gateway_app-dev_app-prod',
        TopologyProfile::ROLES,
        ['gateway', 'app-dev'],
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
 * @param list<string> $instances the VMs the host holds
 * @param list<string> $withSnapshot the VMs that still hold the promoted snapshot
 * @param string $owner the ownership metadata of the snapshots
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

describe('StandbyAvailability', function () {
    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    });

    it('passes when every promoted snapshot is on this checkout\'s standby VMs', function () {
        $identity = StandbyIdentity::primary();
        fakeAvailabilityHost($identity->instances(), $identity->instances());

        expect(fn () => new StandbyAvailability(new IncusHost(pool: 'orbit-e2e'), $identity)
            ->assertAvailable(availabilityGeneration()))
            ->not
            ->toThrow(Throwable::class);
    });

    it('names the recovery command when the manifest names snapshots the host lost', function () {
        $identity = StandbyIdentity::primary();
        fakeAvailabilityHost($identity->instances(), [$identity->instance('gateway')]);

        expect(fn () => new StandbyAvailability(new IncusHost(pool: 'orbit-e2e'), $identity)
            ->assertAvailable(availabilityGeneration()))
            ->toThrow(StaleStandbyManifest::class, 'bin/e2e-standby rebuild');
    });

    it('reports the stale manifest as recoverable rather than corrupt', function () {
        $identity = StandbyIdentity::primary();
        fakeAvailabilityHost($identity->instances(), []);

        $failure = null;
        try {
            new StandbyAvailability(new IncusHost(pool: 'orbit-e2e'), $identity)
                ->assertAvailable(availabilityGeneration());
        } catch (StaleStandbyManifest $exception) {
            $failure = $exception->getMessage();
        }

        expect($failure)
            ->toContain('aaaaaaaaaaaa-bbbbbbbbbbbb')
            ->toContain('promoted from another checkout')
            ->toContain(StaleStandbyManifest::RECOVERY_COMMAND)
            ->not->toContain('corrupt state');
    });

    it('is stale when this checkout\'s standby VMs are gone entirely', function () {
        $identity = StandbyIdentity::live();
        fakeAvailabilityHost(StandbyIdentity::primary()->instances(), []);

        expect(fn () => new StandbyAvailability(new IncusHost(pool: 'orbit-e2e'), $identity)
            ->assertAvailable(availabilityGeneration()))
            ->toThrow(StaleStandbyManifest::class, 'orbit-e2e-live-standby-gateway does not exist');
    });

    it('reads the standby of its own identity, never the other checkout\'s', function () {
        $identity = StandbyIdentity::live();
        fakeAvailabilityHost($identity->instances(), $identity->instances());

        new StandbyAvailability(new IncusHost(pool: 'orbit-e2e'), $identity)
            ->assertAvailable(availabilityGeneration());

        Process::assertRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && ($process->command[3] ?? null) === 'snapshot'
                && ($process->command[5] ?? null) === 'local:orbit-e2e-live-standby-gateway'
            ),
        );
        Process::assertDidntRun(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && ($process->command[5] ?? null) === 'local:orbit-e2e-standby-gateway'
            ),
        );
    });

    it('lets an ownership failure through unchanged, because that is not a stale manifest', function () {
        $identity = StandbyIdentity::primary();
        fakeAvailabilityHost($identity->instances(), $identity->instances(), owner: 'someone-else');

        expect(fn () => new StandbyAvailability(new IncusHost(pool: 'orbit-e2e'), $identity)
            ->assertAvailable(availabilityGeneration()))
            ->toThrow(RuntimeException::class, 'ownership metadata does not match')
            ->and(fn () => new StandbyAvailability(new IncusHost(pool: 'orbit-e2e'), $identity)
                ->assertAvailable(availabilityGeneration()))
            ->not->toThrow(StaleStandbyManifest::class);
    });
});
