<?php

declare(strict_types=1);

use App\Actions\Processes\AddProcessAction;
use App\Actions\Processes\CascadeAppInstanceProcessesAction;
use App\Actions\Processes\RemoveProcessAction;
use App\Data\Processes\AddProcessData;
use App\Domain\AppInstances\AppInstanceRemovalStatus;
use App\Domain\AppInstances\AppInstanceRemovalStep;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;
use App\Models\Node;
use App\Models\Process;
use App\Models\Route;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process as SymfonyProcess;

it('serializes fixed-set owners across processes in stable identifier order', function (): void {
    $directory = sys_get_temp_dir().'/orbit-process-admission-'.Str::uuid();
    mkdir($directory, permissions: 0o700, recursive: true);
    $script = "{$directory}/worker.php";
    file_put_contents($script, orb131_process_admission_worker());

    try {
        $first = new SymfonyProcess([
            PHP_BINARY,
            $script,
            base_path(),
            $directory,
            'first',
            '[22,11]',
        ]);
        $first->start();
        orb131_wait_for_admission_file("{$directory}/first.ready", $first);

        $second = new SymfonyProcess([
            PHP_BINARY,
            $script,
            base_path(),
            $directory,
            'second',
            '[11,22]',
        ]);
        $second->start();
        usleep(150_000);

        expect($second->isRunning())
            ->toBeTrue()
            ->and(file_exists("{$directory}/second.ready"))
            ->toBeFalse();

        touch("{$directory}/first.release");
        $first->wait();
        $second->wait();

        expect($first->isSuccessful())
            ->toBeTrue()
            ->and($second->isSuccessful())
            ->toBeTrue()
            ->and(file_exists("{$directory}/second.ready"))
            ->toBeTrue();
    } finally {
        new Filesystem()->deleteDirectory($directory);
    }
});

it('refuses a stale add when removal accepts after initial resolution and before admission', function (): void {
    $instance = orb131_process_admission_instance();
    $runtime = new Orb131AdmissionRuntimeManager;
    $lock = new Orb131RemovalAcceptingProcessAdmissionLock(
        fn () => orb131_accept_process_removal($instance),
    );
    $action = new AddProcessAction(new ProcessTargetResolver, $runtime, $lock);
    $data = new AddProcessData(
        targetType: ProcessTargetType::AppInstance,
        targetId: $instance->id,
        name: 'queue',
        runtime: ProcessRuntime::Systemd,
        command: ['/usr/bin/php', 'artisan', 'queue:work'],
        image: null,
        workingDirectory: null,
        environment: [],
        ports: [],
        volumes: [],
        restartPolicy: 'always',
        start: false,
    );

    expect(fn () => $action->execute($data))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('process.target_inactive');
        });

    expect($instance->refresh()->status)
        ->toBe(AppInstanceState::Removing)
        ->and($runtime->converged)
        ->toBeEmpty()
        ->and(Process::query()->count())
        ->toBe(0);
});

it('finishes an admitted Process before removal acceptance includes it in cleanup', function (): void {
    $instance = orb131_process_admission_instance();
    $lock = new Orb131QueuedProcessAdmissionLock;
    $runtime = new Orb131AdmissionRuntimeManager;
    $runtime->duringConverge = fn () => $lock->run(
        [$instance->id],
        function () use ($instance): void {
            expect(Process::query()->sole()->status)->toBe(LifecycleStatus::Active);
            orb131_accept_process_removal($instance);
        },
    );
    $data = new AddProcessData(
        targetType: ProcessTargetType::AppInstance,
        targetId: $instance->id,
        name: 'queue',
        runtime: ProcessRuntime::Systemd,
        command: ['/usr/bin/php', 'artisan', 'queue:work'],
        image: null,
        workingDirectory: null,
        environment: [],
        ports: [],
        volumes: [],
        restartPolicy: 'always',
        start: false,
    );

    $result = new AddProcessAction(new ProcessTargetResolver, $runtime, $lock)->execute($data);

    expect($result['process']->status)
        ->toBe(LifecycleStatus::Active)
        ->and($instance->refresh()->status)
        ->toBe(AppInstanceState::Removing);
    $this->assertDatabaseHas('processes', ['id' => $result['process']->id]);

    new CascadeAppInstanceProcessesAction(new RemoveProcessAction($runtime, new ProcessTargetResolver))->execute(
        $instance->id,
    );

    $this->assertDatabaseMissing('processes', ['id' => $result['process']->id]);
});

function orb131_process_admission_instance(): AppInstance
{
    $app = OrbitApp::query()->create([
        'name' => 'Admission',
        'slug' => 'admission',
        'repository_url' => 'https://example.test/admission.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'admission',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.61',
        'wireguard_ip' => '10.44.0.61',
        'user' => 'orbit',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'default',
        'environment' => 'development',
        'source_layout' => AppInstanceSourceLayout::Checkout,
        'checkout_path' => '/srv/orbit/apps/admission',
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'hostname' => 'admission.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    return $instance->fresh(['app', 'node', 'routes']);
}

function orb131_accept_process_removal(AppInstance $instance): void
{
    DB::transaction(function () use ($instance): void {
        $locked = AppInstance::query()->lockForUpdate()->findOrFail($instance->id);
        $route = $locked->routes()->sole();
        $removal = AppInstanceRemoval::query()->create([
            'id' => (string) Str::uuid(),
            'requested_app_instance_id' => $locked->id,
            'requested_name' => $locked->name,
            'force' => false,
            'inventory_digest' => str_repeat('b', 64),
            'total' => 1,
            'status' => AppInstanceRemovalStatus::Removing,
            'current_step' => AppInstanceRemovalStep::SourcePreparation,
        ]);
        $removal->members()->create([
            'position' => 0,
            'app_instance_id' => $locked->id,
            'app_id' => $locked->app_id,
            'node_id' => $locked->node_id,
            'route_id' => $route->id,
            'name' => $locked->name,
            'environment' => $locked->environment,
            'source_layout' => $locked->source_layout,
            'repository_identity' => $locked->app->repository_identity,
            'checkout_path' => $locked->checkout_path,
            'root' => $locked->effectiveRoot(),
            'branch' => $locked->branch,
            'starting_commit' => $locked->starting_commit,
            'source_commit' => $locked->starting_commit,
            'common_repository_path' => $locked->checkout_path,
            'source_identity' => "test:{$locked->id}",
            'linked_worktree_paths' => [$locked->checkout_path],
            'source_digest' => str_repeat('c', 64),
        ]);
        $locked->update(['status' => AppInstanceState::Removing]);
    });
}

function orb131_wait_for_admission_file(string $path, SymfonyProcess $process): void
{
    $deadline = microtime(true) + 5;

    while (! file_exists($path) && $process->isRunning() && microtime(true) < $deadline) {
        usleep(1_000);
    }

    if (file_exists($path)) {
        return;
    }

    throw new RuntimeException(json_encode([
        'stdout' => $process->getOutput(),
        'stderr' => $process->getErrorOutput(),
    ], JSON_THROW_ON_ERROR));
}

function orb131_process_admission_worker(): string
{
    return <<<'PHP'
        <?php

        declare(strict_types=1);

        [$script, $base, $directory, $name, $encodedIds] = $argv;
        require "{$base}/vendor/autoload.php";

        $ids = json_decode($encodedIds, true, flags: JSON_THROW_ON_ERROR);
        $lock = new App\Infrastructure\Processes\NativeProcessAdmissionLock(
            $directory.'/locks',
            new App\Infrastructure\Processes\CommandDeadline,
        );
        $lock->run($ids, function () use ($directory, $name): void {
            touch("{$directory}/{$name}.ready");

            if ($name !== 'first') {
                return;
            }

            $deadline = microtime(true) + 5;
            while (! file_exists("{$directory}/first.release") && microtime(true) < $deadline) {
                usleep(1_000);
            }

            if (! file_exists("{$directory}/first.release")) {
                throw new RuntimeException('The first Process admission owner was not released.');
            }
        });
        PHP;
}

final readonly class Orb131RemovalAcceptingProcessAdmissionLock implements ProcessAdmissionLock
{
    public function __construct(private Closure $beforeAdmission) {}

    public function run(array $appInstanceIds, Closure $operation): mixed
    {
        ($this->beforeAdmission)();

        return $operation();
    }
}

final class Orb131QueuedProcessAdmissionLock implements ProcessAdmissionLock
{
    private bool $held = false;

    /** @var list<Closure(): mixed> */
    private array $pending = [];

    public function run(array $appInstanceIds, Closure $operation): mixed
    {
        if ($this->held) {
            $this->pending[] = $operation;

            return null;
        }

        $this->held = true;

        try {
            return $operation();
        } finally {
            $this->held = false;

            foreach ($this->pending as $pending) {
                $pending();
            }

            $this->pending = [];
        }
    }
}

final class Orb131AdmissionRuntimeManager implements ProcessRuntimeManager
{
    /** @var list<int> */
    public array $converged = [];

    /** @var (Closure(): mixed)|null */
    public ?Closure $duringConverge = null;

    public function converge(Process $process): void
    {
        $this->converged[] = $process->id;

        if ($this->duringConverge instanceof Closure) {
            ($this->duringConverge)();
        }
    }

    public function start(Process $process): void {}

    public function stop(Process $process): void {}

    public function restart(Process $process): void {}

    public function remove(Process $process): void {}

    public function status(Process $process): string
    {
        return 'stopped';
    }

    public function logs(Process $process, int $lines): string
    {
        return '';
    }
}
