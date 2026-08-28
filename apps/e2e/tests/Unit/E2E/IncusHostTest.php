<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\State\OperationJournal;
use App\E2E\State\SecretRedactor;
use App\E2E\State\StatePaths;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\OperationId;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    /** @mago-expect analysis:possibly-invalid-argument The process facade only needs the container contract in unit tests. */
    Facade::setFacadeApplication($container);
});

function incusCommand(string ...$arguments): array
{
    return ['incus', '--project', 'orbit', ...$arguments];
}

function incusTarget(string $identity = ''): string
{
    return 'lab:'.$identity;
}

function vmJson(
    string $name = 'orbit-e2e-nck-123-gateway',
    string $owner = 'orbit-e2e',
    string $type = 'virtual-machine',
    string $pool = 'orbit-e2e',
): string {
    return json_encode([[
        'name' => $name,
        'type' => $type,
        'status' => 'Stopped',
        'status_code' => 102,
        'config' => ['user.orbit.e2e.owner' => $owner],
        'devices' => ['root' => ['pool' => $pool]],
    ]], JSON_THROW_ON_ERROR);
}

function incusHost(
    ?SecretRedactor $redactor = null,
    ?OperationJournal $journal = null,
    ?OperationId $operationId = null,
): IncusHost {
    return new IncusHost(
        remote: 'lab',
        project: 'orbit',
        pool: 'orbit-e2e',
        redactor: $redactor ?? new SecretRedactor,
        journal: $journal,
        operationId: $operationId,
    );
}

describe('IncusHost reads', function () {
    it('reads exact VM, network, and image identities as JSON', function () {
        $fingerprint = str_repeat('a', 64);
        Process::fake(function (PendingProcess $process) use ($fingerprint) {
            return match ($process->command) {
                incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json') => Process::result(
                    vmJson(),
                ),
                incusCommand('network', 'list', incusTarget(), '--format=json') => Process::result(json_encode([
                    ['name' => 'orbit-e2e-nck-123', 'config' => ['user.orbit.e2e.owner' => 'orbit-e2e']],
                ], JSON_THROW_ON_ERROR)),
                incusCommand('image', 'list', 'images:', 'ubuntu/24.04', '--format=json')
                    => Process::result(json_encode([[
                    'fingerprint' => $fingerprint,
                    'type' => 'virtual-machine',
                    'aliases' => [['name' => 'ubuntu/24.04']],
                ]], JSON_THROW_ON_ERROR)),
                default => Process::result('', 'Unexpected command.', 1),
            };
        });

        $host = incusHost();
        $instance = $host->instance('orbit-e2e-nck-123-gateway');

        expect($instance)
            ->toBeInstanceOf(IncusInstance::class)
            ->and($instance?->pool)
            ->toBe('orbit-e2e')
            ->and($instance?->isStopped())
            ->toBeTrue()
            ->and($host->network('orbit-e2e-nck-123')?->name)
            ->toBe('orbit-e2e-nck-123')
            ->and($host->imageFingerprint('images:ubuntu/24.04'))
            ->toBe($fingerprint);
    });

    it('returns null when an exact identity is absent', function () {
        Process::fake([
            '*' => Process::result(json_encode([
                ['name' => 'orbit-e2e-nck-123-app-dev', 'type' => 'virtual-machine'],
            ], JSON_THROW_ON_ERROR)),
        ]);

        expect(incusHost()->instance('orbit-e2e-nck-123-gateway'))->toBeNull();
    });

    it('resolves an exact local VM image alias on the configured remote', function () {
        $fingerprint = str_repeat('b', 64);
        Process::fake(function (PendingProcess $process) use ($fingerprint) {
            expect($process->command)->toBe(incusCommand(
                'image',
                'list',
                incusTarget(),
                'orbit-base',
                '--format=json',
            ));

            return Process::result(json_encode([[
                'fingerprint' => $fingerprint,
                'type' => 'virtual-machine',
                'aliases' => [['name' => 'orbit-base']],
            ]], JSON_THROW_ON_ERROR));
        });

        expect(incusHost()->imageFingerprint('orbit-base'))->toBe($fingerprint);
    });

    it('passes an external image alias as the exact init image operand', function () {
        Process::fake(function (PendingProcess $process) {
            expect($process->command)->toBe(incusCommand(
                'init',
                'images:ubuntu/26.04',
                incusTarget('orbit-e2e-nck-123-gateway'),
                '--vm',
                '--storage',
                'orbit-e2e',
                '--network',
                'orbit-e2e-nck-123',
                '--config',
                'user.orbit.e2e.owner=orbit-e2e',
            ));

            return Process::result();
        });

        incusHost()->initVm('images:ubuntu/26.04', 'orbit-e2e-nck-123-gateway', 'orbit-e2e-nck-123');
    });

    it('rejects missing or contradictory VM power status', function () {
        Process::fake(['*' => Process::result(str_replace('"status_code":102', '"status_code":103', vmJson()))]);

        expect(fn () => incusHost()->instance('orbit-e2e-nck-123-gateway'))
            ->toThrow(InvalidArgumentException::class, 'power status');

        Process::fake(['*' => Process::result(str_replace(
            ['"status":"Stopped",', '"status_code":102,'],
            '',
            vmJson(),
        ))]);

        expect(fn () => incusHost()->instance('orbit-e2e-nck-123-gateway'))
            ->toThrow(RuntimeException::class, 'no valid power status');
    });

    it('rejects malformed JSON and wrong instance types', function () {
        Process::fake(['*' => Process::result('{')]);
        expect(fn () => incusHost()->instance('orbit-e2e-nck-123-gateway'))
            ->toThrow(RuntimeException::class, 'malformed JSON');

        Process::fake(['*' => Process::result(vmJson(type: 'container'))]);
        expect(fn () => incusHost()->instance('orbit-e2e-nck-123-gateway'))
            ->toThrow(RuntimeException::class, 'not a virtual machine');
    });

    it('rejects an instance from a different storage pool', function () {
        Process::fake(['*' => Process::result(vmJson(pool: 'unrelated'))]);

        expect(fn () => incusHost()->instance('orbit-e2e-nck-123-gateway'))
            ->toThrow(RuntimeException::class, 'storage pool identity does not match');
    });
});

describe('IncusHost network creation', function () {
    it('rejects names longer than the Linux bridge interface limit before invoking Incus', function () {
        $name = 'orbit-e2e-network-too-long';

        expect(fn () => incusHost()->createNetwork($name, []))
            ->toThrow(RuntimeException::class, 'Incus network names must be 15 ASCII characters or fewer.');

        Process::assertNothingRan();
    });
});

describe('IncusHost mutations', function () {
    it('does not start an already running owned VM', function () {
        Process::fake(function (PendingProcess $process) {
            return match ($process->command) {
                incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json') => Process::result(
                    str_replace(
                        ['"status":"Stopped"', '"status_code":102'],
                        ['"status":"Running"', '"status_code":103'],
                        vmJson(),
                    ),
                ),
                default => Process::result(),
            };
        });

        incusHost()->start('orbit-e2e-nck-123-gateway');

        Process::assertNotRan(incusCommand('start', incusTarget('orbit-e2e-nck-123-gateway')));
    });

    it('passes guest stdin through process input without adding it to argv', function () {
        $observedInput = null;
        $secret = 'token='.bin2hex(random_bytes(8))."\n";
        Process::fake(function (PendingProcess $process) use (&$observedInput) {
            if (
                $process->command === incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json')
            ) {
                return Process::result(vmJson());
            }
            $observedInput = $process->input;

            return Process::result();
        });

        incusHost()->exec(
            'orbit-e2e-nck-123-gateway',
            new GuestCommand(['install', '/dev/stdin', '/tmp/config'], 60, $secret),
        );

        expect($observedInput)->toBe($secret);
        Process::assertRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && in_array('install', $process->command, true)
                && ! in_array($secret, $process->command, true)
            ),
        );
    });

    it('uses argument arrays with global scope, storage, and exact identities', function () {
        Process::fake(function (PendingProcess $process) {
            return match ($process->command) {
                incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json') => Process::result(
                    vmJson(),
                ),
                incusCommand('network', 'list', incusTarget(), '--format=json') => Process::result(json_encode([
                    ['name' => 'oe-b32d6c83af72', 'config' => ['user.orbit.e2e.owner' => 'orbit-e2e']],
                ], JSON_THROW_ON_ERROR)),
                default => Process::result(),
            };
        });
        $host = incusHost();

        $network = $host->createNetwork('oe-b32d6c83af72', ['ipv4.address' => '10.20.30.1/24']);
        $instance = $host->initVm('orbit-base', 'orbit-e2e-nck-123-gateway', 'oe-b32d6c83af72');
        $copy = $host->copySnapshot('orbit-e2e-standby-gateway', 'main-g1', 'orbit-e2e-nck-123-gateway');
        $host->setNetwork('orbit-e2e-nck-123-gateway', 'oe-b32d6c83af72');
        $host->setMetadata('orbit-e2e-nck-123-gateway', ['user.orbit.e2e.issue' => 'NCK-123']);

        expect($network->name)
            ->toBe('oe-b32d6c83af72')
            ->and($network->metadata['user.orbit.e2e.owner'])
            ->toBe('orbit-e2e')
            ->and($instance->pool)
            ->toBe('orbit-e2e')
            ->and($copy->name)
            ->toBe('orbit-e2e-nck-123-gateway');

        foreach ([
            incusCommand(
                'network',
                'create',
                incusTarget('oe-b32d6c83af72'),
                'ipv4.address=10.20.30.1/24',
                'user.orbit.e2e.owner=orbit-e2e',
            ),
            incusCommand(
                'init',
                incusTarget('orbit-base'),
                incusTarget('orbit-e2e-nck-123-gateway'),
                '--vm',
                '--storage',
                'orbit-e2e',
                '--network',
                'oe-b32d6c83af72',
                '--config',
                'user.orbit.e2e.owner=orbit-e2e',
            ),
            incusCommand(
                'copy',
                incusTarget('orbit-e2e-standby-gateway/main-g1'),
                incusTarget('orbit-e2e-nck-123-gateway'),
                '--storage',
                'orbit-e2e',
            ),
            incusCommand(
                'config',
                'device',
                'override',
                incusTarget('orbit-e2e-nck-123-gateway'),
                'eth0',
                'network=oe-b32d6c83af72',
            ),
            incusCommand(
                'config',
                'set',
                incusTarget('orbit-e2e-nck-123-gateway'),
                'user.orbit.e2e.issue=NCK-123',
            ),
        ] as $command) {
            Process::assertRan($command);
        }
    });

    it('sets metadata only on exact owned instances and networks', function (string $resource) {
        Process::fake(function (PendingProcess $process) use ($resource) {
            return match ($process->command) {
                incusCommand('list', incusTarget($resource), '--format=json') => Process::result(
                    $resource === 'orbit-e2e-nck-123-gateway' ? vmJson() : '[]',
                ),
                incusCommand('network', 'list', incusTarget(), '--format=json') => Process::result(json_encode([
                    ['name' => 'orbit-e2e-nck-123', 'config' => ['user.orbit.e2e.owner' => 'orbit-e2e']],
                ], JSON_THROW_ON_ERROR)),
                default => Process::result(),
            };
        });

        incusHost()->setMetadata($resource, ['user.orbit.e2e.issue' => 'NCK-123']);

        $command = $resource === 'orbit-e2e-nck-123-gateway'
            ? incusCommand('config', 'set', incusTarget($resource), 'user.orbit.e2e.issue=NCK-123')
            : incusCommand('network', 'set', incusTarget($resource), 'user.orbit.e2e.issue=NCK-123');
        Process::assertRan($command);
    })->with(['orbit-e2e-nck-123-gateway', 'orbit-e2e-nck-123']);

    it('does not set metadata when exact resource ownership does not match', function () {
        Process::fake(function (PendingProcess $process) {
            return match ($process->command) {
                incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json') => Process::result(
                    vmJson(owner: 'someone-else'),
                ),
                default => Process::result(),
            };
        });

        expect(fn () => incusHost()->setMetadata(
            'orbit-e2e-nck-123-gateway',
            ['user.orbit.e2e.issue' => 'NCK-123'],
        ))
            ->toThrow(RuntimeException::class, 'ownership metadata does not match');

        Process::assertDidntRun(incusCommand(
            'config',
            'set',
            incusTarget('orbit-e2e-nck-123-gateway'),
            'user.orbit.e2e.issue=NCK-123',
        ));
    });

    it('validates the exact owned network before attaching it', function () {
        Process::fake(function (PendingProcess $process) {
            return match ($process->command) {
                incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json') => Process::result(
                    vmJson(),
                ),
                incusCommand('network', 'list', incusTarget(), '--format=json') => Process::result(json_encode([
                    ['name' => 'orbit-e2e-nck-123', 'config' => ['user.orbit.e2e.owner' => 'someone-else']],
                ], JSON_THROW_ON_ERROR)),
                default => Process::result(),
            };
        });

        expect(fn () => incusHost()->setNetwork('orbit-e2e-nck-123-gateway', 'orbit-e2e-nck-123'))
            ->toThrow(RuntimeException::class, 'network orbit-e2e-nck-123 ownership metadata does not match');

        Process::assertDidntRun(incusCommand(
            'config',
            'device',
            'override',
            incusTarget('orbit-e2e-nck-123-gateway'),
            'eth0',
            'network=orbit-e2e-nck-123',
        ));
    });

    it('re-reads and verifies ownership before destructive instance calls', function () {
        Process::fake([
            '*' => Process::sequence()
                ->push(vmJson())
                ->push(Process::result()),
        ]);

        incusHost()->deleteInstance('orbit-e2e-nck-123-gateway');

        Process::assertRanInOrder([
            incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json'),
            incusCommand('delete', incusTarget('orbit-e2e-nck-123-gateway')),
        ]);
    });

    it('re-reads exact snapshots before restore and deletion', function (string $operation) {
        Process::fake([
            '*' => Process::sequence()
                ->push(vmJson())
                ->push(json_encode([[
                    'name' => 'main-g1',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR))
                ->push(Process::result()),
        ]);

        incusHost()->{$operation}('orbit-e2e-nck-123-gateway', 'main-g1');

        Process::assertRanInOrder([
            incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json'),
            incusCommand(
                'snapshot',
                'list',
                incusTarget('orbit-e2e-nck-123-gateway'),
                '--format=json',
            ),
            incusCommand(
                'snapshot',
                $operation === 'restore' ? 'restore' : 'delete',
                incusTarget('orbit-e2e-nck-123-gateway'),
                'main-g1',
            ),
        ]);
    })->with(['restore', 'deleteSnapshot']);

    it('accepts a fully qualified snapshot identity for the requested instance', function () {
        Process::fake([
            '*' => Process::sequence()
                ->push(vmJson())
                ->push(json_encode([[
                    'name' => 'orbit-e2e-nck-123-gateway/main-g1',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR))
                ->push(Process::result()),
        ]);

        incusHost()->restore('orbit-e2e-nck-123-gateway', 'main-g1');
        Process::assertRan(incusCommand(
            'snapshot',
            'restore',
            incusTarget('orbit-e2e-nck-123-gateway'),
            'main-g1',
        ));
    });

    it('rejects a fully qualified snapshot identity for another instance', function () {
        Process::fake([
            '*' => Process::sequence()
                ->push(vmJson())
                ->push(json_encode([[
                    'name' => 'other-instance/main-g1',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR)),
        ]);

        expect(fn () => incusHost()->restore('orbit-e2e-nck-123-gateway', 'main-g1'))
            ->toThrow(RuntimeException::class, 'snapshot identity changed');
        Process::assertDidntRun(incusCommand(
            'snapshot',
            'restore',
            incusTarget('orbit-e2e-nck-123-gateway'),
            'main-g1',
        ));
    });

    it('rejects ownership mismatch without destructive mutation', function () {
        Process::fake(['*' => Process::result(vmJson(owner: 'someone-else'))]);

        expect(fn () => incusHost()->deleteInstance('orbit-e2e-nck-123-gateway'))
            ->toThrow(RuntimeException::class, 'ownership metadata does not match');

        Process::assertDidntRun(incusCommand('delete', incusTarget('orbit-e2e-nck-123-gateway')));
    });

    it('runs guest commands with their timeout and returns nonzero results', function () {
        Process::fake(function (PendingProcess $process) {
            return $process->command === incusCommand('list', incusTarget('orbit-e2e-nck-123-gateway'), '--format=json')
                ? Process::result(vmJson())
                : Process::result('stdout', 'stderr', 17);
        });

        $result = incusHost()->exec('orbit-e2e-nck-123-gateway', new GuestCommand(['sh', '-lc', 'exit 17'], 42));

        expect($result->stdout)
            ->toBe("stdout\n")
            ->and($result->stderr)
            ->toBe("stderr\n")
            ->and($result->exitCode)
            ->toBe(17)
            ->and($result->successful())
            ->toBeFalse();
        Process::assertRan(
            fn (PendingProcess $process): bool => (
                $process->command === incusCommand(
                    'exec',
                    incusTarget('orbit-e2e-nck-123-gateway'),
                    '--',
                    'sh',
                    '-lc',
                    'exit 17',
                )
                && $process->timeout === 42
            ),
        );
    });
});

describe('IncusHost failures', function () {
    it('reports nonzero exits without exposing secrets', function () {
        Process::fake(['*' => Process::result('', 'Bearer token-value', 9)]);

        try {
            incusHost(new SecretRedactor(['token-value']))->imageFingerprint('ubuntu');
            $this->fail('Expected the Incus command to fail.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())
                ->toContain('[REDACTED]')
                ->and(str_contains($exception->getMessage(), 'token-value'))
                ->toBeFalse();
        }
    });

    it('wraps timeouts and redacts their output', function () {
        Process::fake(function (): never {
            throw new RuntimeException('Timed out with password-value');
        });

        expect(fn () => incusHost(new SecretRedactor(['password-value']))->imageFingerprint('ubuntu'))
            ->toThrow(RuntimeException::class, '[REDACTED]');
    });

    it('journals failures under the caller operation identity', function () {
        $state = new StatePaths(sys_get_temp_dir().'/orbit-incus-host-'.bin2hex(random_bytes(6)));
        $redactor = new SecretRedactor(['token-value']);
        $journal = new OperationJournal($state, $redactor);
        $operationId = new OperationId('acquire-nck-123');
        Process::fake(['*' => Process::result('', 'Bearer token-value', 9)]);

        expect(fn () => incusHost($redactor, $journal, $operationId)->imageFingerprint('ubuntu'))
            ->toThrow(RuntimeException::class);

        expect($journal->entries($operationId))
            ->toHaveCount(1)
            ->and($journal->entries($operationId)[0]['error'])
            ->toContain('[REDACTED]')
            ->and(str_contains((string) $journal->entries($operationId)[0]['error'], 'token-value'))
            ->toBeFalse();
    });

    it('rejects unsafe public identities before running Incus', function () {
        Process::fake();

        expect(fn () => incusHost()->deleteInstance('orbit-e2e-*'))
            ->toThrow(RuntimeException::class, 'Invalid')
            ->and(fn () => incusHost()->setMetadata('safe', ['user.other.owner' => 'orbit-e2e']))
            ->toThrow(RuntimeException::class, 'ownership metadata');
        Process::assertNothingRan();
    });
});
