<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\TopologyConverger;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyTarget;
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

function task7_host(): IncusHost
{
    return new IncusHost(remote: 'lab', project: 'orbit', pool: 'orbit-e2e');
}

function task7_vm(string $name, string $owner = 'orbit-e2e'): string
{
    return json_encode([[
        'name' => $name,
        'type' => 'virtual-machine',
        'status' => 'Stopped',
        'status_code' => 102,
        'config' => ['user.orbit.e2e.owner' => $owner],
        'devices' => ['root' => ['pool' => 'orbit-e2e']],
    ]], JSON_THROW_ON_ERROR);
}

describe('TopologyConverger', function () {
    it('validates and converges an existing ready topology in the required order', function () {
        $recorded = [];
        Process::fake(function (PendingProcess $process) use (&$recorded) {
            $command = $process->command;
            assert(is_array($command));
            $recorded[] = $command;

            if (array_slice($command, -4) === ['network', 'list', 'lab:', '--format=json']) {
                return Process::result(json_encode([[
                    'name' => 'oe-b32d6c83af72',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR));
            }

            if (($command[count($command) - 1] ?? null) === '--format=json') {
                $target = $command[count($command) - 2];

                return Process::result(task7_vm(
                    str_contains($target, ':') ? substr($target, strpos($target, ':') + 1) : $target,
                ));
            }

            return Process::result();
        });

        $sha = str_repeat('a', 40);
        $report = new TopologyConverger(task7_host())->converge(
            new TopologyTarget('NCK-123'),
            new SourceState($sha, $sha, false),
            new LaravelRelease('v13.10.1', str_repeat('b', 40)),
        );

        expect(array_keys($report->steps))->toBe([
            'validate.prerequisites',
            'bootstrap.gateway',
            'pin.ssh-hosts',
            'provision.app-dev',
            'provision.app-prod',
            'configure.app-dev-cli',
            'create.sample-resources',
            'hydrate.sample-apps',
            'normalize.permissions',
        ]);

        $mutations = collect($recorded)
            ->filter(fn (array $command): bool => in_array('exec', $command, true))
            ->values()
            ->all();

        expect($mutations)
            ->toHaveCount(11)
            ->and(array_column(array_slice($mutations, 0, 3), 4))
            ->toBe([
                'lab:orbit-e2e-nck-123-gateway',
                'lab:orbit-e2e-nck-123-gateway',
                'lab:orbit-e2e-nck-123-gateway',
            ]);

        Process::assertDidntRun(
            fn (PendingProcess $process): bool => (
                is_array($process->command) && in_array('init', $process->command, true)
            ),
        );
        Process::assertDidntRun(
            fn (PendingProcess $process): bool => (
                is_array($process->command) && in_array('create', $process->command, true)
            ),
        );
        Process::assertDidntRun(
            fn (PendingProcess $process): bool => (
                is_array($process->command) && in_array('start', $process->command, true)
            ),
        );
    });

    it('fails before mutation when a required network is absent', function () {
        Process::fake(['*' => Process::result('[]')]);

        expect(fn () => new TopologyConverger(task7_host())->converge(
            new TopologyTarget('NCK-123'),
            new SourceState(str_repeat('a', 40), str_repeat('a', 40), false),
            new LaravelRelease('v13.10.1', str_repeat('b', 40)),
        ))
            ->toThrow(RuntimeException::class, 'network oe-b32d6c83af72 does not exist');

        Process::assertDidntRun(
            fn (PendingProcess $process): bool => (
                is_array($process->command) && in_array('start', $process->command, true)
            ),
        );
    });

    it('preflights ownership of every resource before any mutation', function (string $foreignResource) {
        $recorded = [];
        Process::fake(function (PendingProcess $process) use (&$recorded, $foreignResource) {
            $command = $process->command;
            assert(is_array($command));
            $recorded[] = $command;

            if (array_slice($command, -4) === ['network', 'list', 'lab:', '--format=json']) {
                return Process::result(json_encode([[
                    'name' => 'oe-b32d6c83af72',
                    'config' => ['user.orbit.e2e.owner' => $foreignResource === 'network' ? 'foreign' : 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR));
            }

            $target = $command[count($command) - 2];
            $name = str_contains($target, ':') ? substr($target, strpos($target, ':') + 1) : $target;
            $owner = $foreignResource === 'app-prod' && str_ends_with($name, '-app-prod') ? 'foreign' : 'orbit-e2e';

            return Process::result(task7_vm($name, $owner));
        });

        expect(fn () => new TopologyConverger(task7_host())->converge(
            new TopologyTarget('NCK-123'),
            new SourceState(str_repeat('a', 40), str_repeat('a', 40), false),
            new LaravelRelease('v13.10.1', str_repeat('b', 40)),
        ))
            ->toThrow(RuntimeException::class, 'ownership metadata does not match');

        expect(collect($recorded)->filter(
            fn (array $command): bool => in_array('start', $command, true) || in_array('exec', $command, true),
        ))->toBeEmpty();
    })->with(['network', 'app-prod']);
});
