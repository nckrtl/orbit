<?php

declare(strict_types=1);

use App\E2E\LegacyIncusRevalidator;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Process\Pool;
use Illuminate\Process\ProcessPoolResults;
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
    return liveIncusCommand('query', '--raw', $path);
}

/** @param array<string, mixed> $metadata */
function liveIncusResult(array $metadata, int $exitCode = 0): \Illuminate\Contracts\Process\ProcessResult
{
    return Process::result(
        json_encode([
            'type' => 'sync',
            'status' => 'Success',
            'status_code' => 200,
            'metadata' => $metadata,
        ], JSON_THROW_ON_ERROR),
        '',
        $exitCode,
    );
}

function reviewedIncusInstance(): array
{
    return [
        'name' => 'old-vm',
        'remote' => 'lab',
        'project' => 'orbit',
        'status' => 'STOPPED',
        'metadata' => [],
        'dependencies' => [],
    ];
}

dataset('invalid raw Incus envelopes', [
    'nonzero 404 error envelope' => [
        static fn () => Process::result(
            json_encode([
                'type' => 'error',
                'error_code' => 404,
            ], JSON_THROW_ON_ERROR),
            '',
            1,
        ),
    ],
    'nonzero sync envelope' => [
        static fn () => liveIncusResult([
            'name' => 'old-vm',
            'type' => 'virtual-machine',
            'status' => 'STOPPED',
            'config' => [],
            'devices' => [],
        ], 1),
    ],
    'plain stderr' => [static fn () => Process::result('', 'Error: not found', 1)],
    'missing output' => [static fn () => Process::result()],
    'malformed output' => [static fn () => Process::result('{')],
    'non-404 error envelope' => [
        static fn () => Process::result(json_encode([
            'type' => 'error',
            'error_code' => 500,
        ], JSON_THROW_ON_ERROR)),
    ],
    'string 404 error code' => [
        static fn () => Process::result(json_encode([
            'type' => 'error',
            'error_code' => '404',
        ], JSON_THROW_ON_ERROR)),
    ],
    'unwrapped present object' => [
        static fn () => Process::result(json_encode([
            'name' => 'old-vm',
            'type' => 'virtual-machine',
        ], JSON_THROW_ON_ERROR)),
    ],
]);

describe('legacy Incus revalidation', function (): void {
    it('uses exact scoped pool and image selectors for batch query and comparison', function (): void {
        $fingerprint = str_repeat('d', 64);
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands, $fingerprint) {
            $commands[] = $process->command;

            return (
                str_contains($process->command[3], '/storage-pools/')
                    ? liveIncusResult(['name' => 'orbit-e2e', 'config' => ['size' => '1TiB']])
                    : liveIncusResult([
                        'fingerprint' => $fingerprint,
                        'aliases' => [['name' => 'some-other-display-name']],
                        'properties' => ['os' => 'Ubuntu'],
                    ])
            );
        });

        $current = new LegacyIncusRevalidator()->currentBatch([
            'base_images' => [[
                'name' => 'ubuntu-display',
                'remote' => 'local',
                'project' => 'default',
                'fingerprint' => $fingerprint,
            ]],
            'pools' => [[
                'name' => 'orbit-e2e',
                'identity' => 'display-only-pool-id',
                'remote' => 'local',
                'project' => 'default',
            ]],
        ]);

        expect($current['base_images'][0]['fingerprint'])
            ->toBe($fingerprint)
            ->and($current['pools'][0]['identity'])
            ->toBe('display-only-pool-id')
            ->and($commands)
            ->toBe([
                liveIncusQuery('local:/1.0/images/'.$fingerprint.'?project=default'),
                liveIncusQuery('local:/1.0/storage-pools/orbit-e2e?project=default'),
            ]);
    });

    it('rejects duplicate exact pool references even when display identities differ', function (): void {
        $pool = static fn (string $identity): array => [
            'name' => 'orbit-e2e',
            'identity' => $identity,
            'remote' => 'local',
            'project' => 'default',
        ];

        expect(fn () => new LegacyIncusRevalidator()->currentBatch([
            'pools' => [$pool('display-a'), $pool('display-b')],
        ]))
            ->toThrow(RuntimeException::class, 'duplicate resource');
    });

    it('rejects a changed live pool name or image fingerprint', function (
        string $kind,
        array $expected,
        array $live,
    ): void {
        Process::fake(['*' => liveIncusResult($live)]);

        expect(fn () => new LegacyIncusRevalidator()->assertCurrent($kind, $expected))
            ->toThrow(RuntimeException::class, 'identity changed');
    })->with([
        'pool API name' => [
            'pools',
            [
                'name' => 'orbit-e2e',
                'identity' => 'display-pool',
                'remote' => 'local',
                'project' => 'default',
            ],
            ['name' => 'replacement'],
        ],
        'base-image fingerprint' => [
            'base_images',
            [
                'name' => 'ubuntu-display',
                'remote' => 'local',
                'project' => 'default',
                'fingerprint' => str_repeat('d', 64),
            ],
            ['fingerprint' => str_repeat('e', 64)],
        ],
    ]);

    it('batches exact resources without querying one resource twice', function (): void {
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;
            $name = str_contains($process->command[3], 'old-vm') ? 'old-vm' : 'other-vm';

            return liveIncusResult([
                'name' => $name,
                'type' => 'virtual-machine',
                'status' => $name === 'old-vm' ? 'STOPPED' : 'RUNNING',
                'config' => [],
                'devices' => [],
            ]);
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
            $scope = str_contains($process->command[3], 'remote-a:') ? 'remote-a' : 'remote-b';

            return liveIncusResult([
                'name' => 'shared-vm',
                'type' => 'virtual-machine',
                'status' => $scope === 'remote-a' ? 'STOPPED' : 'RUNNING',
                'config' => [],
                'devices' => [],
            ]);
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

            return liveIncusResult([
                'name' => 'ready',
                'config' => ['owner' => 'replacement'],
            ]);
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
        Process::fake(['*' => liveIncusResult([
            'name' => 'replacement-net',
            'type' => 'bridge',
            'config' => [],
        ])]);

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
        Process::fake(['*' => liveIncusResult([
            'name' => 'old-vm',
            'type' => 'virtual-machine',
            'status' => 'RUNNING',
            'config' => ['owner' => 'alice', 'limits.cpu' => '4', 'volatile.uuid' => 'live-only'],
            'devices' => ['eth0' => ['network' => 'old-net']],
        ])]);

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
        Process::fake(['*' => liveIncusResult([
            'name' => 'old-vm',
            'type' => 'virtual-machine',
            'status' => 'RUNNING',
            'config' => [],
            'devices' => [
                'eth0' => ['network' => 'old-net'],
                'eth1' => ['network' => 'new-net'],
            ],
        ])]);

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
        Process::fake(['*' => liveIncusResult([
            'name' => 'old-net',
            'type' => 'bridge',
            'config' => [],
            'used_by' => ['/1.0/instances/old-vm', '/1.0/instances/new-vm'],
        ])]);

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
        Process::fake(['*' => liveIncusResult([
            'name' => 'old-vm',
            'type' => 'virtual-machine',
            'status' => 'STOPPED',
            'config' => [],
            'devices' => [],
        ])]);

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

    it('accepts only a zero-exit raw 404 error envelope as exact absence', function (): void {
        Process::fake([
            '*' => Process::sequence()
                ->push(Process::result(
                    json_encode([
                        'type' => 'error',
                        'error_code' => 404,
                        'error' => 'Resource not found',
                    ], JSON_THROW_ON_ERROR),
                    '',
                    0,
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

    it('fails the single path closed for every invalid raw Incus result', function (Closure $result): void {
        Process::fake(['*' => $result()]);

        expect(fn () => new LegacyIncusRevalidator()->isCurrent('instances', reviewedIncusInstance()))
            ->toThrow(RuntimeException::class);
    })->with('invalid raw Incus envelopes');

    it('fails the batch path closed for every invalid raw Incus result', function (Closure $result): void {
        Process::fake(['*' => $result()]);

        expect(fn () => new LegacyIncusRevalidator()->currentBatch([
            'instances' => [reviewedIncusInstance()],
        ]))
            ->toThrow(RuntimeException::class);
    })->with('invalid raw Incus envelopes');

    it('fails single and batch reads closed when the Incus process cannot launch', function (): void {
        Process::fake(function (): never {
            throw new RuntimeException('launch failed');
        });

        expect(fn () => new LegacyIncusRevalidator()->isCurrent('instances', reviewedIncusInstance()))
            ->toThrow(RuntimeException::class, 'could not run');
        expect(fn () => new LegacyIncusRevalidator()->currentBatch([
            'instances' => [reviewedIncusInstance()],
        ]))
            ->toThrow(RuntimeException::class, 'could not run');
    });

    it('fails closed when a batch omits a launched resource response', function (): void {
        $container = Facade::getFacadeApplication();
        assert($container instanceof Container);
        $container->instance(ProcessFactory::class, new class extends ProcessFactory {
            #[\Override]
            public function pool(callable $callback): Pool
            {
                return new class($this, $callback) extends Pool {
                    #[\Override]
                    public function run(): ProcessPoolResults
                    {
                        ($this->callback)($this);

                        return new ProcessPoolResults([]);
                    }
                };
            }
        });
        Facade::clearResolvedInstances();

        expect(fn () => new LegacyIncusRevalidator()->currentBatch([
            'instances' => [reviewedIncusInstance()],
        ]))
            ->toThrow(RuntimeException::class, 'result label is missing');
    });
});
