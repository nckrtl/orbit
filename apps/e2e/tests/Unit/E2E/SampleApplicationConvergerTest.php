<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\SampleApplicationConverger;
use App\E2E\Value\LaravelRelease;
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

function sample_convergence_host(): IncusHost
{
    return new IncusHost(remote: 'lab', project: 'orbit', pool: 'orbit-e2e');
}

function sample_convergence_vm(string $name): string
{
    return json_encode([[
        'name' => $name,
        'type' => 'virtual-machine',
        'status' => 'Stopped',
        'status_code' => 102,
        'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
        'devices' => ['root' => ['pool' => 'orbit-e2e']],
    ]], JSON_THROW_ON_ERROR);
}

describe('SampleApplicationConverger', function () {
    it('pins the same stock Laravel commit on development and production', function () {
        $recorded = [];
        Process::fake(function (PendingProcess $process) use (&$recorded) {
            $command = $process->command;
            assert(is_array($command));
            $recorded[] = $command;

            if (($command[count($command) - 1] ?? null) === '--format=json') {
                $target = $command[count($command) - 2];

                return Process::result(sample_convergence_vm(
                    str_contains($target, ':') ? substr($target, strpos($target, ':') + 1) : $target,
                ));
            }

            return Process::result();
        });

        $commit = str_repeat('c', 40);
        $report = new SampleApplicationConverger(sample_convergence_host())->converge(
            new TopologyTarget('NCK-123'),
            new LaravelRelease('v13.10.1', $commit),
        );

        $commands = collect($recorded)
            ->filter(fn (array $command): bool => in_array('exec', $command, true))
            ->values();

        expect($report->toArray())
            ->toBe([
                'converged' => true,
                'steps' => [
                    'app-dev.checkout' => true,
                    'app-prod.checkout' => true,
                    'release.pinned' => true,
                    'permissions.normalized' => true,
                ],
            ])
            ->and($commands)
            ->toHaveCount(2)
            ->and($commands[0])
            ->toContain('hydrate', $commit, 'app-dev')
            ->and($commands[1])
            ->toContain('hydrate', $commit, 'app-prod');
    });
});
