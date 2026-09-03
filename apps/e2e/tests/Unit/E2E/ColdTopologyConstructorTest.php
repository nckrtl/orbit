<?php

declare(strict_types=1);

use App\E2E\ColdTopologyConstructor;
use App\E2E\HostCapacity;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\State\StatePaths;
use App\E2E\TopologyConverger;
use App\E2E\Value\AttemptId;
use App\E2E\Value\ColdTopologyPlan;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyTarget;
use App\E2E\WorktreeSynchronizer;
use App\Exceptions\E2E\ColdTopologyCleanupException;
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

/** @mago-expect lint:too-many-properties,cyclomatic-complexity The fake models one complete mutable Incus transaction. */
final class ColdConstructorProcessState
{
    /** @var array<string, string> */
    public array $instances = [];

    /** @var list<string> */
    public array $deleted = [];

    public bool $networkExists = false;

    public bool $foreignOnGit = false;

    private bool $ownershipDrifted = false;

    public function __construct(
        public readonly TopologyTarget $target,
        public readonly string $operation,
    ) {}

    public function result(PendingProcess $process): \Illuminate\Contracts\Process\ProcessResult
    {
        $command = $process->command;
        assert(is_array($command));
        if (($command[0] ?? null) === 'python3') {
            return Process::result("{\"changed\":true}\n");
        }
        if (($command[0] ?? null) === 'git') {
            $this->ownershipDrifted = $this->foreignOnGit;

            return Process::result('', 'injected source failure', 1);
        }
        if (($command[3] ?? null) === 'image') {
            return Process::result(json_encode([[
                'type' => 'virtual-machine',
                'fingerprint' => str_repeat('f', 64),
                'aliases' => [['name' => TopologyRecipe::BASE_IMAGE]],
            ]], JSON_THROW_ON_ERROR));
        }
        if ($command === ['incus', '--project', 'default', 'network', 'list', 'local:', '--format=json']) {
            return Process::result(json_encode(
                $this->networkExists
                    ? [[
                        'name' => $this->target->network(),
                        'config' => [
                            'user.orbit.e2e.owner' => 'orbit-e2e',
                            'user.orbit.e2e.operation' => $this->operation,
                            'ipv4.address' => '10.232.2.1/24',
                            'ipv4.dhcp.ranges' => '10.232.2.10-10.232.2.13',
                        ],
                        'used_by' => [],
                    ]] : [],
                JSON_THROW_ON_ERROR,
            ));
        }
        if (($command[3] ?? null) === 'list' && ($command[array_key_last($command)] ?? null) === '--format=json') {
            $selector = preg_replace('/\Alocal:/', '', (string) ($command[4] ?? ''));
            $names = $selector === '' ? array_keys($this->instances) : [$selector];
            $resources = [];
            foreach ($names as $name) {
                $status = $this->instances[$name] ?? null;
                if ($status === null) {
                    continue;
                }
                $operation = $this->ownershipDrifted && $name === $this->target->instance('extra')
                    ? str_repeat('e', 32)
                    : $this->operation;
                $resource = cold_constructor_instance($name, $this->target->network(), $operation);
                $resource['status'] = $status;
                $resource['status_code'] = $status === 'Running' ? 103 : 102;
                $resources[] = $resource;
            }

            return Process::result(json_encode($resources, JSON_THROW_ON_ERROR));
        }
        if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'create') {
            $this->networkExists = true;

            return Process::result();
        }
        if (($command[3] ?? null) === 'init') {
            $name = preg_replace('/\Alocal:/', '', (string) ($command[5] ?? ''));
            $this->instances[$name] = 'Stopped';

            return Process::result();
        }
        if (in_array($command[3] ?? null, ['start', 'stop'], true)) {
            $name = preg_replace('/\Alocal:/', '', (string) ($command[4] ?? ''));
            $this->instances[$name] = ($command[3] ?? null) === 'start' ? 'Running' : 'Stopped';

            return Process::result();
        }
        if (($command[3] ?? null) === 'delete') {
            $name = preg_replace('/\Alocal:/', '', (string) ($command[4] ?? ''));
            $this->deleted[] = $name;
            unset($this->instances[$name]);

            return Process::result();
        }
        if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'delete') {
            $this->deleted[] = $this->target->network();
            $this->networkExists = false;

            return Process::result();
        }
        if (($command[3] ?? null) === 'exec') {
            return str_contains(implode(' ', $command), 'ip -4 route show default')
                ? Process::result("2: enp5s0    inet 10.232.2.10/24 scope global enp5s0\n")
                : Process::result();
        }

        throw new RuntimeException(json_encode($command, JSON_THROW_ON_ERROR));
    }
}

function cold_constructing_service(IncusHost $host, StatePaths $paths): ColdTopologyConstructor
{
    /** @mago-expect analysis:possibly-invalid-argument The failure is injected before convergence. */
    $converger = new ReflectionClass(TopologyConverger::class)->newInstanceWithoutConstructor();

    return new ColdTopologyConstructor(
        $host,
        new IncusNetworkLifecycle($host),
        new WorktreeSynchronizer($host, dirname(__DIR__, 5), new OperationId(str_repeat('d', 32))),
        $converger,
        new HostCapacity($host, 9),
        $paths,
    );
}

function cold_constructing_plan(TopologyTarget $target, OperationId $operation): ColdTopologyPlan
{
    return new ColdTopologyPlan(
        $target,
        '/tmp',
        str_repeat('a', 40),
        [TopologyRecipe::BASE_IMAGE => str_repeat('f', 64)],
        new LaravelRelease('v13.0.0', str_repeat('b', 40)),
        $operation,
        ['user.orbit.e2e.operation' => $operation->value],
    );
}

describe('ColdTopologyConstructor cleanup', function () {
    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    });

    it('automatically rolls back resources after a post-mutation construction failure', function () {
        $operation = new OperationId(str_repeat('d', 32));
        $target = TopologyTarget::disposableCold('ORB-106', attemptId(), TopologyRecipe::coldAcceptance());
        $state = new ColdConstructorProcessState($target, $operation->value);
        Process::fake($state->result(...));

        expect(fn () => cold_constructing_service(
            new IncusHost(pool: 'orbit-e2e'),
            new StatePaths(temporaryPath('cold-construction-', 4)),
        )->construct(cold_constructing_plan($target, $operation)))
            ->toThrow(InvalidArgumentException::class, 'The Git command failed.');

        expect($state->instances)->toBe([]);
        expect($state->networkExists)->toBeFalse();
        expect($state->deleted)->toBe([
            $target->instance('extra'),
            $target->instance('app-prod'),
            $target->instance('operator'),
            $target->instance('gateway'),
            $target->network(),
        ]);
    });

    it('reports cleanup refusal and preserves the primary construction failure', function () {
        $operation = new OperationId(str_repeat('d', 32));
        $target = TopologyTarget::disposableCold('ORB-106', attemptId(), TopologyRecipe::coldAcceptance());
        $state = new ColdConstructorProcessState($target, $operation->value);
        $state->foreignOnGit = true;
        Process::fake($state->result(...));
        $exception = null;

        try {
            cold_constructing_service(
                new IncusHost(pool: 'orbit-e2e'),
                new StatePaths(temporaryPath('cold-construction-', 4)),
            )->construct(cold_constructing_plan($target, $operation));
        } catch (ColdTopologyCleanupException $failure) {
            $exception = $failure;
        }

        expect($exception)
            ->toBeInstanceOf(ColdTopologyCleanupException::class)
            ->and($exception?->getPrevious())
            ->toBeInstanceOf(InvalidArgumentException::class)
            ->and($exception?->getPrevious()?->getMessage())
            ->toBe('The Git command failed.')
            ->and($exception?->cleanup->refused)
            ->toHaveCount(1)
            ->and($exception?->cleanup->refused[0] ?? null)
            ->toContain('belongs to another operation');
        expect($state->deleted)->toBe([]);
        expect(array_keys($state->instances))->toBe(array_map($target->instance(...), $target->recipe->nodeKeys()));
        expect($state->networkExists)->toBeTrue();
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
