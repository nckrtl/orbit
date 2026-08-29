<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\State\OperationJournal;
use App\E2E\State\StatePaths;
use App\E2E\Value\GuestCommand;
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
    Facade::setFacadeApplication($container);
});

describe('IncusHost journal security', function () {
    it('does not retain arbitrary guest argv when exec fails', function () {
        $state = new StatePaths(sys_get_temp_dir().'/orbit-incus-journal-'.bin2hex(random_bytes(6)));
        $operation = new OperationId(str_repeat('a', 32));
        $journal = new OperationJournal($state);
        $host = new IncusHost(
            remote: 'lab',
            project: 'orbit',
            pool: 'orbit-e2e',
            journal: $journal,
            operationId: $operation,
        );

        Process::fake(function (PendingProcess $process) {
            if (($process->command[3] ?? null) === 'list') {
                return Process::result(json_encode([[
                    'name' => 'vm',
                    'type' => 'virtual-machine',
                    'status' => 'Running',
                    'status_code' => 103,
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                    'devices' => ['root' => ['pool' => 'orbit-e2e']],
                ]], JSON_THROW_ON_ERROR));
            }

            throw new RuntimeException('guest command failed: tool --password arbitrary-guest-value');
        });

        $exception = null;
        try {
            $host->exec('vm', new GuestCommand(['tool', '--password', 'arbitrary-guest-value']));
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }

        expect($exception)->toBeInstanceOf(RuntimeException::class);

        $entry = $journal->entries($operation)[0];
        expect($entry['command'])
            ->toBe(['incus', '--project', 'orbit', 'exec', 'lab:vm', '--'])
            ->and($entry['error'])
            ->not->toContain('arbitrary-guest-value')->and($exception?->getMessage())
            ->not->toContain('arbitrary-guest-value')->and(json_encode($entry, JSON_THROW_ON_ERROR))
            ->not->toContain('arbitrary-guest-value');
    });

    it('retains non-exec command context and error detail', function () {
        $state = new StatePaths(sys_get_temp_dir().'/orbit-incus-journal-'.bin2hex(random_bytes(6)));
        $operation = new OperationId(str_repeat('b', 32));
        $journal = new OperationJournal($state);
        $host = new IncusHost(
            remote: 'lab',
            project: 'orbit',
            pool: 'orbit-e2e',
            journal: $journal,
            operationId: $operation,
        );
        Process::fake(['*' => Process::result('', 'safe incus detail', 7)]);

        expect(fn () => $host->imageFingerprint('ubuntu'))->toThrow(RuntimeException::class);

        $entry = $journal->entries($operation)[0];
        expect($entry['command'])
            ->toBe(['incus', '--project', 'orbit', 'image', 'list', 'lab:', 'ubuntu', '--format=json'])
            ->and($entry['error'])
            ->toContain('safe incus detail');
    });
});
