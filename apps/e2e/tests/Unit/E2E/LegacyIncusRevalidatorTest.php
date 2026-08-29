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
    it('batches exact resources without querying one resource twice', function (): void {
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;
            $name = str_contains($process->command[2], 'old-vm') ? 'old-vm' : 'other-vm';

            return Process::result(json_encode([
                'name' => $name,
                'type' => 'virtual-machine',
                'status' => $name === 'old-vm' ? 'STOPPED' : 'RUNNING',
                'config' => [],
                'devices' => [],
            ], JSON_THROW_ON_ERROR));
        });
        $expected = static fn (string $name): array => [
            'name' => $name,
            'remote' => 'lab',
            'project' => 'orbit',
            'status' => 'RUNNING',
            'metadata' => [],
            'dependencies' => [],
        ];

        $current = new LegacyIncusRevalidator()->currentBatch([
            'instances' => [$expected('old-vm'), $expected('other-vm')],
        ], 'delete_instances');

        expect(array_column($current['instances'], 'status'))
            ->toBe(['STOPPED', 'RUNNING'])
            ->and($commands)
            ->toBe([
                liveIncusQuery('lab:/1.0/instances/old-vm?project=orbit'),
                liveIncusQuery('lab:/1.0/instances/other-vm?project=orbit'),
            ]);
    });

    it('keeps same identities in separate scopes distinct within one pool barrier', function (): void {
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;
            $scope = str_contains($process->command[2], 'remote-a:') ? 'remote-a' : 'remote-b';

            return Process::result(json_encode([
                'name' => 'shared-vm',
                'type' => 'virtual-machine',
                'status' => $scope === 'remote-a' ? 'STOPPED' : 'RUNNING',
                'config' => [],
                'devices' => [],
            ], JSON_THROW_ON_ERROR));
        });

        $expected = static fn (string $remote, string $project): array => [
            'name' => 'shared-vm',
            'remote' => $remote,
            'project' => $project,
            'metadata' => [],
            'dependencies' => [],
        ];

        $current = new LegacyIncusRevalidator()->currentBatch([
            'instances' => [$expected('remote-a', 'project-a'), $expected('remote-b', 'project-b')],
        ]);

        expect(array_column($current['instances'], 'status'))
            ->toBe(['STOPPED', 'RUNNING'])
            ->and($commands)
            ->toBe([
                liveIncusQuery('remote-a:/1.0/instances/shared-vm?project=project-a'),
                liveIncusQuery('remote-b:/1.0/instances/shared-vm?project=project-b'),
            ]);
    });

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

    it('rejects extra stable metadata while ignoring volatile metadata', function (): void {
        Process::fake(['*' => Process::result(json_encode([
            'name' => 'old-vm',
            'type' => 'virtual-machine',
            'status' => 'RUNNING',
            'config' => ['owner' => 'alice', 'limits.cpu' => '4', 'volatile.uuid' => 'live-only'],
            'devices' => ['eth0' => ['network' => 'old-net']],
        ], JSON_THROW_ON_ERROR))]);

        expect(fn () => new LegacyIncusRevalidator()->assertCurrent('instances', [
            'name' => 'old-vm',
            'remote' => 'lab',
            'project' => 'orbit',
            'status' => 'RUNNING',
            'metadata' => ['owner' => 'alice'],
            'dependencies' => ['old-net'],
        ]))
            ->toThrow(RuntimeException::class, 'metadata changed');
        Process::assertRan(liveIncusQuery('lab:/1.0/instances/old-vm?project=orbit'));
    });

    it('rejects an instance with a second network device', function (): void {
        Process::fake(['*' => Process::result(json_encode([
            'name' => 'old-vm',
            'type' => 'virtual-machine',
            'status' => 'RUNNING',
            'config' => [],
            'devices' => [
                'eth0' => ['network' => 'old-net'],
                'eth1' => ['network' => 'new-net'],
            ],
        ], JSON_THROW_ON_ERROR))]);

        expect(fn () => new LegacyIncusRevalidator()->assertCurrent('instances', [
            'name' => 'old-vm',
            'remote' => 'lab',
            'project' => 'orbit',
            'status' => 'RUNNING',
            'metadata' => [],
            'dependencies' => ['old-net'],
        ]))
            ->toThrow(RuntimeException::class, 'dependencies changed');
    });

    it('rejects a network with a newly used instance', function (): void {
        Process::fake(['*' => Process::result(json_encode([
            'name' => 'old-net',
            'type' => 'bridge',
            'config' => [],
            'used_by' => ['/1.0/instances/old-vm', '/1.0/instances/new-vm'],
        ], JSON_THROW_ON_ERROR))]);

        expect(fn () => new LegacyIncusRevalidator()->assertCurrent('networks', [
            'name' => 'old-net',
            'remote' => 'lab',
            'project' => 'orbit',
            'metadata' => [],
            'dependencies' => [],
        ]))
            ->toThrow(RuntimeException::class, 'dependencies changed');
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

    it('distinguishes an exact missing resource from an unreadable Incus host', function (): void {
        Process::fake([
            '*' => Process::sequence()
                ->push(Process::result(
                    json_encode([
                        'type' => 'error',
                        'status_code' => 404,
                        'error' => 'Resource not found',
                    ], JSON_THROW_ON_ERROR),
                    '',
                    1,
                ))
                ->push(Process::result('', 'Error: connection refused', 1)),
        ]);
        $expected = [
            'name' => 'old-vm',
            'remote' => 'lab',
            'project' => 'orbit',
            'status' => 'STOPPED',
            'metadata' => [],
            'dependencies' => [],
        ];
        $revalidator = new LegacyIncusRevalidator;

        expect($revalidator->isCurrent('instances', $expected))
            ->toBeFalse()
            ->and(fn () => $revalidator->isCurrent('instances', $expected))
            ->toThrow(RuntimeException::class, 'live Incus resource read failed');
    });
});
