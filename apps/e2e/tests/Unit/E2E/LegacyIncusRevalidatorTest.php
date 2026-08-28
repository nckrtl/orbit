<?php

declare(strict_types=1);

use App\E2E\LegacyIncusRevalidator;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

function liveIncusCommand(string ...$arguments): array
{
    return ['incus', ...$arguments];
}

function liveIncusQuery(string $path): array
{
    return liveIncusCommand('query', $path);
}

describe('legacy Incus revalidation', function (): void {
    it('reads one exact instance and checks its reviewed facts', function (): void {
        Process::fake(function (PendingProcess $process) {
            expect($process->command)->toBe(liveIncusQuery('lab:/1.0/instances/old-vm?project=orbit'));

            return Process::result(json_encode([
                'type' => 'sync',
                'status' => 'Success',
                'status_code' => 200,
                'metadata' => [
                    'name' => 'old-vm',
                    'type' => 'virtual-machine',
                    'status' => 'Stopped',
                    'config' => ['owner' => 'alice'],
                    'devices' => ['eth0' => ['network' => 'old-net']],
                    'owner' => 'alice',
                ],
            ], JSON_THROW_ON_ERROR));
        });

        new LegacyIncusRevalidator()->assertCurrent('instances', [
            'name' => 'old-vm',
            'remote' => 'lab',
            'project' => 'orbit',
            'status' => 'STOPPED',
            'metadata' => ['owner' => 'alice'],
            'dependencies' => ['old-net'],
            'owner' => 'alice',
        ]);
    });

    it('uses an exact snapshot identity and refuses changed ownership', function (): void {
        Process::fake(function (PendingProcess $process) {
            expect($process->command)->toBe(liveIncusQuery('lab:/1.0/instances/old-vm/snapshots/ready?project=orbit'));

            return Process::result(json_encode([
                'name' => 'ready',
                'config' => ['owner' => 'replacement'],
            ], JSON_THROW_ON_ERROR));
        });

        expect(fn () => new LegacyIncusRevalidator()->assertCurrent('snapshots', [
            'name' => 'old-vm/ready',
            'remote' => 'lab',
            'project' => 'orbit',
            'metadata' => ['owner' => 'reviewed'],
            'dependencies' => [],
        ]))
            ->toThrow(RuntimeException::class, 'metadata changed');
    });

    it('fails closed on an exact network kind or identity mismatch', function (): void {
        Process::fake(['*' => Process::result(json_encode([
            'name' => 'replacement-net',
            'type' => 'bridge',
            'config' => [],
        ], JSON_THROW_ON_ERROR))]);

        expect(fn () => new LegacyIncusRevalidator()->assertCurrent('networks', [
            'name' => 'old-net',
            'remote' => 'lab',
            'project' => 'orbit',
            'metadata' => [],
            'dependencies' => [],
        ]))
            ->toThrow(RuntimeException::class, 'identity changed');

        Process::assertRan(liveIncusQuery('lab:/1.0/networks/old-net?project=orbit'));
    });

    it('accepts reviewed metadata and dependencies as subsets of the live resource', function (): void {
        Process::fake(['*' => Process::result(json_encode([
            'name' => 'old-vm',
            'type' => 'virtual-machine',
            'status' => 'RUNNING',
            'config' => ['owner' => 'alice', 'volatile.uuid' => 'live-only'],
            'devices' => ['eth0' => ['network' => 'old-net']],
        ], JSON_THROW_ON_ERROR))]);

        new LegacyIncusRevalidator()->assertCurrent('instances', [
            'name' => 'old-vm',
            'remote' => 'lab',
            'project' => 'orbit',
            'status' => 'RUNNING',
            'metadata' => ['owner' => 'alice'],
            'dependencies' => ['old-net'],
        ]);
        Process::assertRan(liveIncusQuery('lab:/1.0/instances/old-vm?project=orbit'));
    });

    it('allows a stopped instance after a reviewed running instance was quarantined', function (): void {
        Process::fake(['*' => Process::result(json_encode([
            'name' => 'old-vm',
            'type' => 'virtual-machine',
            'status' => 'STOPPED',
            'config' => [],
            'devices' => [],
        ], JSON_THROW_ON_ERROR))]);

        new LegacyIncusRevalidator()->assertCurrent(
            'instances',
            [
                'name' => 'old-vm',
                'remote' => 'lab',
                'project' => 'orbit',
                'status' => 'RUNNING',
                'metadata' => [],
                'dependencies' => [],
            ],
            'delete_instances',
        );
        Process::assertRan(liveIncusQuery('lab:/1.0/instances/old-vm?project=orbit'));
    });

    it('rejects unsafe scope and identity values before constructing an API path', function (): void {
        expect(fn () => new LegacyIncusRevalidator()->assertCurrent('instances', [
            'name' => 'old-vm?project=other',
            'remote' => 'lab',
            'project' => 'orbit',
            'metadata' => [],
            'dependencies' => [],
        ]))
            ->toThrow(RuntimeException::class);

        expect(fn () => new LegacyIncusRevalidator()->assertCurrent('instances', [
            'name' => 'old-vm',
            'remote' => 'lab/evil',
            'project' => 'orbit',
            'metadata' => [],
            'dependencies' => [],
        ]))
            ->toThrow(RuntimeException::class);
    });

    it('fails closed when an Incus response envelope has no object metadata', function (): void {
        Process::fake(['*' => Process::result(json_encode([
            'type' => 'sync',
            'status' => 'Success',
            'metadata' => [],
        ], JSON_THROW_ON_ERROR))]);

        expect(fn () => new LegacyIncusRevalidator()->assertCurrent('instances', [
            'name' => 'old-vm',
            'remote' => 'lab',
            'project' => 'orbit',
            'metadata' => [],
            'dependencies' => [],
        ]))
            ->toThrow(RuntimeException::class, 'invalid live resource object');
    });
});
