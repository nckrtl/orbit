<?php

declare(strict_types=1);

use App\E2E\ColdTopologyConstructor;
use App\E2E\HostCapacity;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\State\StatePaths;
use App\E2E\TopologyConverger;
use App\E2E\Value\AttemptId;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyTarget;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

function cold_constructor_service(IncusHost $host): ColdTopologyConstructor
{
    /** @mago-expect analysis:possibly-invalid-argument Test helpers resolve only known class names. */
    $uninitialized = fn (string $class): object => new ReflectionClass($class)->newInstanceWithoutConstructor();

    return new ColdTopologyConstructor(
        $host,
        new IncusNetworkLifecycle($host),
        $uninitialized(WorktreeSynchronizer::class),
        $uninitialized(TopologyConverger::class),
        new HostCapacity($host, 9),
        new StatePaths(temporaryPath('cold-constructor-', 4)),
    );
}

/** @return array<string, mixed> */
function cold_constructor_instance(string $name, string $network, string $operation): array
{
    return [
        'name' => $name,
        'type' => 'virtual-machine',
        'status' => 'Stopped',
        'status_code' => 102,
        'config' => [
            'user.orbit.e2e.owner' => 'orbit-e2e',
            'user.orbit.e2e.operation' => $operation,
        ],
        'devices' => [
            'root' => ['pool' => 'orbit-e2e'],
            'eth0' => ['network' => $network],
        ],
    ];
}

describe('ColdTopologyConstructor cleanup', function () {
    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    });

    it('deletes the exact variable-size inventory in reverse Node order', function () {
        $operation = str_repeat('d', 32);
        $target = TopologyTarget::disposableCold(
            'ORB-106',
            new AttemptId(str_repeat('a', 32)),
            TopologyRecipe::coldAcceptance(),
        );
        $existing = array_map($target->instance(...), $target->recipe->nodeKeys());
        $networkExists = true;
        $deleted = [];
        Process::fake(function (PendingProcess $process) use (
            &$existing,
            &$networkExists,
            &$deleted,
            $target,
            $operation,
        ) {
            $command = $process->command;
            assert(is_array($command));
            if (($command[0] ?? null) === 'python3') {
                return Process::result(json_encode(['changed' => true], JSON_THROW_ON_ERROR));
            }
            if ($command === ['incus', '--project', 'default', 'list', 'local:', '--format=json']) {
                return Process::result(json_encode(array_map(
                    fn (string $name): array => cold_constructor_instance($name, $target->network(), $operation),
                    $existing,
                ), JSON_THROW_ON_ERROR));
            }
            if ($command === ['incus', '--project', 'default', 'network', 'list', 'local:', '--format=json']) {
                return Process::result(json_encode(
                    $networkExists
                        ? [[
                            'name' => $target->network(),
                            'config' => [
                                'user.orbit.e2e.owner' => 'orbit-e2e',
                                'user.orbit.e2e.operation' => $operation,
                            ],
                            'used_by' => [],
                        ]] : [],
                    JSON_THROW_ON_ERROR,
                ));
            }
            if (($command[3] ?? null) === 'delete') {
                $name = preg_replace('/\Alocal:/', '', (string) ($command[4] ?? ''));
                $deleted[] = $name;
                $existing = array_values(array_diff($existing, [$name]));

                return Process::result();
            }
            if (array_slice($command, 3, 2) === ['network', 'delete']) {
                $deleted[] = $target->network();
                $networkExists = false;

                return Process::result();
            }

            throw new RuntimeException(json_encode($command, JSON_THROW_ON_ERROR));
        });

        $result = cold_constructor_service(new IncusHost(pool: 'orbit-e2e'))
            ->cleanup($target, new OperationId($operation));

        expect($result->successful())->toBeTrue();
        expect($result->refused)->toBe([]);
        expect($result->removed)->toBe([
            $target->instance('extra'),
            $target->instance('app-prod'),
            $target->instance('operator'),
            $target->instance('gateway'),
            $target->network(),
        ]);
        expect($deleted)->toBe($result->removed);
    });

    it('refuses every deletion when one resource belongs to another operation', function () {
        $target = TopologyTarget::disposableCold(
            'ORB-106',
            new AttemptId(str_repeat('a', 32)),
            TopologyRecipe::coldAcceptance(),
        );
        $instances = array_map($target->instance(...), $target->recipe->nodeKeys());
        Process::fake(function (PendingProcess $process) use ($instances, $target) {
            $command = $process->command;
            assert(is_array($command));
            if ($command === ['incus', '--project', 'default', 'list', 'local:', '--format=json']) {
                return Process::result(json_encode(array_map(
                    fn (string $name): array => cold_constructor_instance(
                        $name,
                        $target->network(),
                        str_repeat($name === $target->instance('extra') ? 'e' : 'd', 32),
                    ),
                    $instances,
                ), JSON_THROW_ON_ERROR));
            }
            if ($command === ['incus', '--project', 'default', 'network', 'list', 'local:', '--format=json']) {
                return Process::result('[]');
            }

            throw new RuntimeException('A cleanup mutation was attempted.');
        });

        $result = cold_constructor_service(new IncusHost(pool: 'orbit-e2e'))
            ->cleanup($target, new OperationId(str_repeat('d', 32)));

        expect($result->successful())->toBeFalse();
        expect($result->removed)->toBe([]);
        expect($result->refused)->toHaveCount(1);
        expect($result->refused[0])->toContain('belongs to another operation');
    });
});
