<?php

declare(strict_types=1);

use App\Actions\AppInstances\AppInstanceDeploymentConfigResolver;
use App\Actions\AppInstances\DeployAppInstanceAction;
use App\Actions\AppInstances\RollbackAppInstanceAction;
use App\Domain\AppInstances\Deployment\DeploymentCancellation;
use App\Domain\AppInstances\Deployment\DeploymentFailureBoundary;
use App\Domain\AppInstances\Deployment\DeploymentRelease;
use App\Domain\AppInstances\Deployment\DeploymentRequest;
use App\Domain\AppInstances\Deployment\DeploymentStep;
use App\Domain\AppInstances\Deployment\ProductionDeployment;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentResult;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentSynchronizer;
use App\Domain\AppInstances\ProductionPhpRuntimeManager;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceEnvironmentValue;
use App\Models\Node;
use Illuminate\Support\Facades\DB;

it('captures one configuration and preserves the complete deployment order', function (): void {
    $instance = orb219_deployment_instance([
        ['name' => 'prepare-one', 'phase' => 'before_activation', 'command' => 'old-one', 'timeout_seconds' => 30],
        ['name' => 'prepare-two', 'phase' => 'before_activation', 'command' => 'old-two', 'timeout_seconds' => 30],
        ['name' => 'finish', 'phase' => 'after_activation', 'command' => 'old-three', 'timeout_seconds' => 30],
    ], php: true);
    $instance->update(['deployment_branch' => 'release']);
    $trace = new Orb219DeploymentTrace;
    $environment = new Orb219EnvironmentSynchronizer($trace, function () use ($instance): void {
        $instance->update([
            'deployment_branch' => 'next-release',
            'deployment_steps' => [
                ['name' => 'replacement', 'phase' => 'before_activation', 'command' => 'new', 'timeout_seconds' => 30],
            ],
        ]);
    });
    [$deploy] = orb219_actions($trace, $environment);

    $result = $deploy->execute($instance);

    expect($result->succeeded)
        ->toBeTrue()
        ->and($trace->entries)
        ->toBe([
            'lock:1',
            'selected:initial',
            'prepare:release',
            'environment',
            'step:prepare-one:old-one',
            'step:prepare-two:old-two',
            'activate:fresh',
            'cache:fresh',
            'step:finish:old-three',
        ])
        ->and($result->release?->name)
        ->toBe('fresh')
        ->and($result->selectedRelease?->name)
        ->toBe('fresh')
        ->and($result->commands)
        ->toHaveCount(3)
        ->and($instance->refresh()->checkout_path)
        ->toBe('/home/orbit-app-1/releases/fresh')
        ->and(print_r($result, return: true))
        ->not->toContain('prepare-one-stdout', 'prepare-one-stderr')
        ->toContain('[OUTPUT]');
});

it('runs no application command or cache refresh for an empty non-PHP deployment', function (): void {
    $instance = orb219_deployment_instance([]);
    $trace = new Orb219DeploymentTrace;
    [$deploy] = orb219_actions($trace);

    $result = $deploy->execute($instance);

    expect($result->succeeded)
        ->toBeTrue()
        ->and($trace->entries)
        ->toBe([
            'lock:1',
            'selected:initial',
            'prepare:main',
            'environment',
            'activate:fresh',
        ])
        ->and($result->commands)
        ->toBe([]);
});

it('keeps the observed old selection when deployment fails before activation', function (): void {
    $instance = orb219_deployment_instance([
        ['name' => 'fail', 'phase' => 'before_activation', 'command' => 'false', 'timeout_seconds' => 30],
    ]);
    $trace = new Orb219DeploymentTrace(failAt: 'step:fail');
    [$deploy] = orb219_actions($trace);

    $result = $deploy->execute($instance);

    expect($result->succeeded)
        ->toBeFalse()
        ->and($result->failure?->boundary)
        ->toBe(DeploymentFailureBoundary::BeforeActivation)
        ->and($result->failure?->errorCode)
        ->toBe('deployment.step_failed')
        ->and($result->selectedRelease?->name)
        ->toBe('initial')
        ->and($instance->refresh()->checkout_path)
        ->toBe('/home/orbit-app-1/releases/initial')
        ->and($trace->entries)
        ->not->toContain('activate:fresh');
});

it('keeps the new selection and persistent effects when deployment fails after activation', function (): void {
    $instance = orb219_deployment_instance([
        ['name' => 'persist', 'phase' => 'before_activation', 'command' => 'touch marker', 'timeout_seconds' => 30],
        ['name' => 'fail', 'phase' => 'after_activation', 'command' => 'false', 'timeout_seconds' => 30],
        ['name' => 'later', 'phase' => 'after_activation', 'command' => 'must-not-run', 'timeout_seconds' => 30],
    ]);
    $trace = new Orb219DeploymentTrace(failAt: 'step:fail');
    [$deploy] = orb219_actions($trace);

    $result = $deploy->execute($instance);

    expect($result->succeeded)
        ->toBeFalse()
        ->and($result->failure?->boundary)
        ->toBe(DeploymentFailureBoundary::AfterActivation)
        ->and($result->selectedRelease?->name)
        ->toBe('fresh')
        ->and($instance->refresh()->checkout_path)
        ->toBe('/home/orbit-app-1/releases/fresh')
        ->and($trace->entries)
        ->toContain('step:persist:touch marker', 'activate:fresh', 'step:fail:false')
        ->not->toContain('step:later:must-not-run');
});

it('reports the observed selection when deployment activation switches before failing', function (): void {
    $instance = orb219_deployment_instance([]);
    $trace = new Orb219DeploymentTrace(
        failAt: 'activate:fresh',
        switchBeforeActivationFailure: true,
    );
    [$deploy] = orb219_actions($trace);

    $result = $deploy->execute($instance);

    expect($result->succeeded)
        ->toBeFalse()
        ->and($result->failure?->boundary)
        ->toBe(DeploymentFailureBoundary::Activation)
        ->and($result->failure?->errorCode)
        ->toBe('deployment.step_failed')
        ->and($result->selectedRelease?->name)
        ->toBe('fresh')
        ->and($instance->refresh()->checkout_path)
        ->toBe('/home/orbit-app-1/releases/fresh')
        ->and($trace->entries)
        ->toBe([
            'lock:1',
            'selected:initial',
            'prepare:main',
            'environment',
            'activate:fresh',
            'selected:fresh',
        ]);
});

it('reports the observed selection when rollback activation switches before failing', function (): void {
    $instance = orb219_deployment_instance([]);
    $trace = new Orb219DeploymentTrace(
        failAt: 'activate:retained',
        switchBeforeActivationFailure: true,
    );
    [, $rollback] = orb219_actions($trace);

    $result = $rollback->execute($instance, 'retained');

    expect($result->succeeded)
        ->toBeFalse()
        ->and($result->failure?->boundary)
        ->toBe(DeploymentFailureBoundary::Activation)
        ->and($result->failure?->errorCode)
        ->toBe('deployment.step_failed')
        ->and($result->selectedRelease?->name)
        ->toBe('retained')
        ->and($instance->refresh()->checkout_path)
        ->toBe('/home/orbit-app-1/releases/retained')
        ->and($trace->entries)
        ->toBe([
            'lock:1',
            'selected:initial',
            'retained:retained',
            'activate:retained',
            'selected:retained',
        ]);
});

it('preserves the activation failure and last known selection when reinspection fails', function (): void {
    $instance = orb219_deployment_instance([]);
    $trace = new Orb219DeploymentTrace(
        failAt: 'activate:fresh',
        switchBeforeActivationFailure: true,
        failActivationReinspection: true,
    );
    [$deploy] = orb219_actions($trace);

    $result = $deploy->execute($instance);

    expect($result->succeeded)
        ->toBeFalse()
        ->and($result->failure?->boundary)
        ->toBe(DeploymentFailureBoundary::Activation)
        ->and($result->failure?->errorCode)
        ->toBe('deployment.step_failed')
        ->and($result->selectedRelease?->name)
        ->toBe('initial')
        ->and($instance->refresh()->checkout_path)
        ->toBe('/home/orbit-app-1/releases/initial')
        ->and($trace->entries)
        ->toBe([
            'lock:1',
            'selected:initial',
            'prepare:main',
            'environment',
            'activate:fresh',
            'selected:failed',
        ]);
});

it('reports each infrastructure failure boundary with its observed selection', function (
    string $failure,
    DeploymentFailureBoundary $boundary,
    string $selected,
): void {
    $instance = orb219_deployment_instance([], php: str_starts_with($failure, 'cache:'));
    $trace = new Orb219DeploymentTrace(failAt: $failure);
    [$deploy] = orb219_actions($trace);

    $result = $deploy->execute($instance);

    expect($result->succeeded)
        ->toBeFalse()
        ->and($result->failure?->boundary)
        ->toBe($boundary)
        ->and($result->selectedRelease?->name)
        ->toBe($selected);
})->with([
    'release preparation' => ['prepare:main', DeploymentFailureBoundary::Preparation, 'initial'],
    'environment synchronization' => ['environment', DeploymentFailureBoundary::Environment, 'initial'],
    'atomic activation' => ['activate:fresh', DeploymentFailureBoundary::Activation, 'initial'],
    'PHP cache refresh' => ['cache:fresh', DeploymentFailureBoundary::CacheRefresh, 'fresh'],
]);

it('stops before later steps when cancellation is requested', function (): void {
    $instance = orb219_deployment_instance([
        ['name' => 'first', 'phase' => 'before_activation', 'command' => 'first', 'timeout_seconds' => 30],
        ['name' => 'later', 'phase' => 'before_activation', 'command' => 'later', 'timeout_seconds' => 30],
    ]);
    $trace = new Orb219DeploymentTrace(cancelAfterStep: 'first');
    [$deploy] = orb219_actions($trace);
    $request = new DeploymentRequest(cancellation: new DeploymentCancellation(
        static fn (): bool => $trace->cancelled,
    ));

    $result = $deploy->execute($instance, $request);

    expect($result->succeeded)
        ->toBeFalse()
        ->and($result->failure?->errorCode)
        ->toBe('deployment.cancelled')
        ->and($trace->entries)
        ->toContain('step:first:first')
        ->not->toContain('step:later:later', 'activate:fresh');
});

it('selects retained code without fetching, synchronizing, running steps, or changing data', function (): void {
    $instance = orb219_deployment_instance([
        ['name' => 'ignored', 'phase' => 'before_activation', 'command' => 'ignored', 'timeout_seconds' => 30],
    ], php: true);
    $trace = new Orb219DeploymentTrace;
    [, $rollback] = orb219_actions($trace);
    $environment = $instance->environment;
    $instance->environmentValues()->create(['env_key' => 'DATABASE_URL', 'env_value' => 'kept']);

    $result = $rollback->execute($instance, 'retained');

    expect($result->succeeded)
        ->toBeTrue()
        ->and($result->release?->name)
        ->toBe('retained')
        ->and($trace->entries)
        ->toBe([
            'lock:1',
            'selected:initial',
            'retained:retained',
            'activate:retained',
            'cache:retained',
        ])
        ->and($instance->refresh()->checkout_path)
        ->toBe('/home/orbit-app-1/releases/retained')
        ->and($instance->environment)
        ->toBe($environment)
        ->and(AppInstanceEnvironmentValue::query()->sole()->env_value)
        ->toBe('kept');
});

it('refuses an unsafe rollback name before any remote mutation', function (): void {
    $instance = orb219_deployment_instance([]);
    $trace = new Orb219DeploymentTrace;
    [, $rollback] = orb219_actions($trace);

    $result = $rollback->execute($instance, '../foreign');

    expect($result->succeeded)
        ->toBeFalse()
        ->and($result->failure?->boundary)
        ->toBe(DeploymentFailureBoundary::RollbackSelection)
        ->and($result->failure?->errorCode)
        ->toBe('rollback.release_invalid')
        ->and($trace->entries)
        ->toBe(['lock:1', 'selected:initial']);
});

it('uses the same action for a first deployment and adds no deployment history store', function (): void {
    $instance = orb219_deployment_instance([]);
    $trace = new Orb219DeploymentTrace(firstDeployment: true);
    [$deploy] = orb219_actions($trace);

    $result = $deploy->execute($instance);
    $tables = collect(DB::select("select name from sqlite_master where type = 'table'"))->pluck('name');

    expect($result->succeeded)
        ->toBeTrue()
        ->and($result->selectedRelease?->name)
        ->toBe('fresh')
        ->and($trace->entries)
        ->toContain('selected:none', 'activate:fresh')
        ->and($tables->contains(
            static fn (string $name): bool => str_contains($name, 'deployment_run')
                || str_contains($name, 'deployment_output')
                || str_contains($name, 'deployment_snapshot'),
        ))
        ->toBeFalse();
});

/** @return array{DeployAppInstanceAction, RollbackAppInstanceAction} */
function orb219_actions(
    Orb219DeploymentTrace $trace,
    ?AppInstanceEnvironmentSynchronizer $environment = null,
): array {
    $lock = new Orb219DeploymentLock($trace);
    $remote = new Orb219ProductionDeployment($trace);
    $runtime = new Orb219PhpRuntime($trace);
    $deadline = new CommandDeadline;
    $resolver = new AppInstanceDeploymentConfigResolver;
    $environment ??= new Orb219EnvironmentSynchronizer($trace);

    return [
        new DeployAppInstanceAction($resolver, $lock, $environment, $remote, $runtime, $deadline),
        new RollbackAppInstanceAction($resolver, $lock, $remote, $runtime, $deadline),
    ];
}

/** @param list<array{name: string, phase: string, command: string, timeout_seconds: int}> $steps */
function orb219_deployment_instance(array $steps, bool $php = false): AppInstance
{
    $node = Node::query()->create([
        'name' => 'deployment-node',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.219',
        'wireguard_ip' => '10.44.0.219',
        'user' => 'orbit',
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Deployment',
        'slug' => 'deployment',
        'repository_url' => 'https://example.test/deployment.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);

    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'source_layout' => 'release',
        'checkout_path' => '/home/orbit-app-1/releases/initial',
        'production_user' => 'orbit-app-1',
        'production_home' => '/home/orbit-app-1',
        'root' => 'public',
        'branch' => 'main',
        'deployment_steps' => $steps,
        'selected_php_version' => $php ? '8.5' : null,
        'source_is_laravel' => $php,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
}

final class Orb219DeploymentTrace
{
    /** @var list<string> */
    public array $entries = [];

    public bool $cancelled = false;

    public ?string $remoteSelection = null;

    public bool $activationFailed = false;

    public function __construct(
        public ?string $failAt = null,
        public ?string $cancelAfterStep = null,
        public bool $firstDeployment = false,
        public bool $switchBeforeActivationFailure = false,
        public bool $failActivationReinspection = false,
    ) {}
}

final readonly class Orb219DeploymentLock implements AppInstanceEnvironmentOperationLock
{
    public function __construct(private Orb219DeploymentTrace $trace) {}

    public function run(array $appInstanceIds, Closure $operation): mixed
    {
        $this->trace->entries[] = 'lock:'.implode(',', $appInstanceIds);

        return $operation();
    }
}

final readonly class Orb219EnvironmentSynchronizer implements AppInstanceEnvironmentSynchronizer
{
    public function __construct(
        private Orb219DeploymentTrace $trace,
        private ?Closure $after = null,
    ) {}

    public function execute(AppInstance $instance): AppInstanceEnvironmentResult
    {
        $this->trace->entries[] = 'environment';

        if ($this->trace->failAt === 'environment') {
            throw new ResourceOperationException('env.sync_failed', 'Injected environment failure.', 409);
        }

        if ($this->after !== null) {
            ($this->after)();
        }

        return new AppInstanceEnvironmentResult($instance->id, 'sync', false, 0);
    }
}

final readonly class Orb219ProductionDeployment implements ProductionDeployment
{
    public function __construct(private Orb219DeploymentTrace $trace) {}

    public function prepare(AppInstance $appInstance, string $branch): DeploymentRelease
    {
        $this->record("prepare:{$branch}");

        return $this->release('fresh');
    }

    public function executeStep(
        AppInstance $appInstance,
        DeploymentRelease $release,
        DeploymentStep $step,
        DeploymentRequest $request,
    ): CommandResult {
        $this->record("step:{$step->name}:{$step->command}", "step:{$step->name}");

        if ($this->trace->cancelAfterStep === $step->name) {
            $this->trace->cancelled = true;
        }

        return new CommandResult(0, "{$step->name}-stdout", "{$step->name}-stderr", 1, false);
    }

    public function activate(AppInstance $appInstance, DeploymentRelease $release): DeploymentRelease
    {
        $entry = "activate:{$release->name}";
        $this->trace->entries[] = $entry;

        if ($this->trace->failAt === $entry) {
            $this->trace->activationFailed = true;

            if ($this->trace->switchBeforeActivationFailure) {
                $this->trace->remoteSelection = $release->name;
            }

            throw new ResourceOperationException('deployment.step_failed', 'Injected deployment failure.', 409);
        }

        return $release;
    }

    public function selected(AppInstance $appInstance): ?DeploymentRelease
    {
        if ($this->trace->activationFailed && $this->trace->failActivationReinspection) {
            $this->trace->entries[] = 'selected:failed';

            throw new ResourceOperationException('deployment.selection_inspection_failed', 'Injected selection inspection failure.', 409);
        }

        $name = $this->trace->firstDeployment
            ? 'none'
            : ($this->trace->remoteSelection ?? basename($appInstance->checkout_path));
        $this->record("selected:{$name}");

        return $this->trace->firstDeployment ? null : $this->release($name);
    }

    public function retained(AppInstance $appInstance, string $name): DeploymentRelease
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $name) !== 1) {
            throw new ResourceOperationException('rollback.release_invalid', 'Invalid retained release.', 422);
        }

        $this->record("retained:{$name}");

        return $this->release($name);
    }

    private function record(string $entry, ?string $failureKey = null): void
    {
        $this->trace->entries[] = $entry;

        if ($this->trace->failAt === ($failureKey ?? $entry)) {
            throw new ResourceOperationException('deployment.step_failed', 'Injected deployment failure.', 409);
        }
    }

    private function release(string $name): DeploymentRelease
    {
        return new DeploymentRelease($name, "/home/orbit-app-1/releases/{$name}", str_repeat('a', 40));
    }
}

final readonly class Orb219PhpRuntime implements ProductionPhpRuntimeManager
{
    public function __construct(private Orb219DeploymentTrace $trace) {}

    public function converge(AppInstance $appInstance): void {}

    public function refreshCache(AppInstance $appInstance): void
    {
        $entry = 'cache:'.basename($appInstance->checkout_path);
        $this->trace->entries[] = $entry;

        if ($this->trace->failAt === $entry) {
            throw new ResourceOperationException('app-prod.php_cache_refresh_failed', 'Injected cache failure.', 409);
        }
    }

    public function remove(AppInstance $appInstance): void {}
}
