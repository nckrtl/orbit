<?php

declare(strict_types=1);

use App\Actions\Instances\DeployDefaultInstanceAction;
use App\Actions\Instances\DeployInstanceAction;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\Deployment\DeploymentEvent;
use App\Domain\Instances\Deployment\DeploymentFailureBoundary;
use App\Domain\Instances\Deployment\DeploymentOutputStream;
use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\Deployment\DeploymentReleaseState;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DevelopmentDeployment;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Projects\ProjectDevelopmentDeployStepStore;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;

beforeEach(function (): void {
    $this->remote = new Dev935Deployment;
    app()->instance(DevelopmentDeployment::class, $this->remote);
    $this->instance = dev935_instance();
});

describe('development default deployments', function (): void {
    it('uses Project steps and the default branch without changing the stable repository path', function (): void {
        $this->instance->update(['deployment_branch' => 'ignored-production-override']);
        dev935_steps($this->instance, [new DevelopmentDeployStep('install', 'incremental install'), new DevelopmentDeployStep('build', 'build')]);
        $result = app(DeployInstanceAction::class)->execute($this->instance);

        expect($result->succeeded)->toBeTrue()
            ->and($result->selectedRelease?->commit)->toBe(str_repeat('b', 40))
            ->and(array_column($result->commands, 'step'))->toBe(['install', 'build'])
            ->and($this->remote->trace)->toBe(['initialize', 'selected', 'target:main', 'prune:initial', 'prepare:'.str_repeat('b', 40), 'step:install', 'step:build', 'activate:release-1', 'prune:release-1'])
            ->and($this->instance->refresh()->checkout_path)->toBe('/fast/apps/dev935/default')
            ->and($this->instance->development_release_layout)->toBeTrue();
    });

    it('projects visitable defaults through current and resolves PHP roots after migration and switch', function (): void {
        $instance = $this->instance;
        $instance->update(['root' => 'public', 'selected_php_version' => '8.5', 'source_is_laravel' => true]);
        $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'domain' => 'dev935.example.test', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => 'active']);
        $projector = Mockery::mock(DevelopmentRouteProjector::class);
        $projector->shouldReceive('converge')->twice()->andReturnUsing(function (Instance $projected) use ($instance): void {
            $site = new DevelopmentSiteRepository()->forNode($projected->node)->sole();
            $configuration = new DevelopmentCaddyConfigRenderer()->render(collect([$site]));
            expect($site->checkoutPath)->toBe($instance->checkout_path.'/current')
                ->and($configuration)->toContain('root * '.$instance->checkout_path.'/current/public', 'resolve_root_symlink');
        });
        app()->instance(DevelopmentRouteProjector::class, $projector);

        expect(app(DeployDefaultInstanceAction::class)->execute($instance)?->succeeded)->toBeTrue();
    });

    it('retries an unconverged persisted release layout before skipping an unchanged scheduled tick', function (): void {
        $instance = $this->instance;
        $instance->update(['development_release_layout' => true, 'root' => 'public', 'selected_php_version' => '8.5', 'source_is_laravel' => true]);
        $this->remote->targetCommit = str_repeat('a', 40);
        $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'domain' => 'recover935.example.test', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => 'active']);
        $calls = 0;
        $projector = Mockery::mock(DevelopmentRouteProjector::class);
        $projector->shouldReceive('converge')->twice()->andReturnUsing(function (Instance $projected) use (&$calls): void {
            expect(new DevelopmentSiteRepository()->forNode($projected->node)->sole()->checkoutPath)->toBe($projected->checkout_path.'/current');
            if (++$calls === 1) {
                throw new RuntimeConvergenceException('projection', 'app-dev.source_access_failed', 'Lost migration convergence.');
            }
        });
        app()->instance(DevelopmentRouteProjector::class, $projector);
        $first = app(DeployDefaultInstanceAction::class)->execute($instance, onlyChanged: true, triggeredBy: 'schedule');
        expect($first?->succeeded)->toBeFalse()
            ->and($instance->fresh()->development_release_layout)->toBeTrue();
        $second = app(DeployDefaultInstanceAction::class)->execute($instance, onlyChanged: true, triggeredBy: 'schedule');
        expect($second)->toBeNull()
            ->and($calls)->toBe(2)
            ->and(InstanceDeployment::query()->count())->toBe(1)
            ->and($this->remote->trace)->not->toContain('activate:release-1');
    });

    it('skips the projection lock for an unchanged scheduled tick with a current projection', function (): void {
        $instance = $this->instance;
        $instance->update(['development_release_layout' => true, 'development_projection_pending' => false, 'root' => 'public', 'selected_php_version' => '8.5', 'source_is_laravel' => true]);
        $this->remote->targetCommit = str_repeat('a', 40);
        $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'domain' => 'idle935.example.test', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => 'active']);
        $projector = Mockery::mock(DevelopmentRouteProjector::class);
        $projector->shouldNotReceive('converge');
        app()->instance(DevelopmentRouteProjector::class, $projector);
        app()->instance(DevelopmentProjectionOperationLock::class, new class implements DevelopmentProjectionOperationLock
        {
            public function run(Closure $operation): mixed
            {
                throw new LogicException('An unchanged tick with a current projection took the projection lock.');
            }
        });

        $result = app(DeployDefaultInstanceAction::class)->execute($instance, onlyChanged: true, triggeredBy: 'schedule');

        expect($result)->toBeNull()
            ->and($instance->fresh()->development_projection_pending)->toBeFalse()
            ->and(InstanceDeployment::query()->count())->toBe(0);
    });

    it('marks the projection pending until a deployment converges it', function (): void {
        $instance = $this->instance;
        $instance->update(['development_release_layout' => true, 'development_projection_pending' => false, 'root' => 'public', 'selected_php_version' => '8.5', 'source_is_laravel' => true]);
        $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'domain' => 'switch935.example.test', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => 'active']);
        $projector = Mockery::mock(DevelopmentRouteProjector::class);
        $projector->shouldReceive('converge')->once()->andThrow(new RuntimeConvergenceException('projection', 'app-dev.source_access_failed', 'Lost switch convergence.'));
        app()->instance(DevelopmentRouteProjector::class, $projector);

        $result = app(DeployDefaultInstanceAction::class)->execute($instance, onlyChanged: true, triggeredBy: 'schedule');

        expect($result?->succeeded)->toBeFalse()
            ->and($this->remote->trace)->toContain('activate:release-1')
            ->and($instance->fresh()->development_projection_pending)->toBeTrue();
    });

    it('keeps current and reports the failed required step without running later steps', function (): void {
        dev935_steps($this->instance, [new DevelopmentDeployStep('install', 'false'), new DevelopmentDeployStep('build', 'never')]);
        $this->remote->failedStep = 'install';
        $result = app(DeployInstanceAction::class)->execute($this->instance);

        expect($result->succeeded)->toBeFalse()
            ->and($result->failure?->boundary)->toBe(DeploymentFailureBoundary::BeforeActivation)
            ->and($result->failure?->errorCode)->toBe('deployment.step_failed')
            ->and($result->selectedRelease?->name)->toBe('initial')
            ->and($result->commands[0]->result->exitCode)->toBe(42)
            ->and($this->remote->trace)->not->toContain('step:build', 'activate:release-1')
            ->and(end($this->remote->trace))->toBe('prune:initial');
    });

    it('switches after a best-effort failure and records its explicit warning and exit code', function (): void {
        dev935_steps($this->instance, [new DevelopmentDeployStep('warm', 'false', required: false), new DevelopmentDeployStep('build', 'true')]);
        $this->remote->failedStep = 'warm';
        $events = [];
        $result = app(DeployDefaultInstanceAction::class)->execute($this->instance, new DeploymentRequest(static function (DeploymentEvent $event) use (&$events): void {
            $events[] = $event;
        }), onlyChanged: true, triggeredBy: 'schedule');

        expect($result?->succeeded)->toBeTrue()
            ->and($result?->selectedRelease?->name)->toBe('release-1')
            ->and($result?->commands[0]->result->exitCode)->toBe(42)
            ->and($this->remote->trace)->toContain('step:build', 'activate:release-1')
            ->and($events[0]->step)->toBe('warm')
            ->and($events[0]->value)->toContain('Best-effort step failed (exit 42)')
            ->and(InstanceDeployment::query()->sole()->status)->toBe('succeeded')
            ->and(base64_decode(InstanceDeployment::query()->sole()->events[2]['value_base64']))->toContain('Best-effort step failed (exit 42)');
    });

    it('keeps best-effort failures visible in scheduled history even after warm-up output is truncated', function (): void {
        dev935_steps($this->instance, [new DevelopmentDeployStep('warm', 'false', required: false)]);
        $this->remote->failedStep = 'warm';
        $this->remote->noisyFailure = true;
        $result = app(DeployDefaultInstanceAction::class)->execute($this->instance, onlyChanged: true, triggeredBy: 'schedule');
        $events = InstanceDeployment::query()->sole()->events;
        $output = array_values(array_filter($events, static fn (array $event): bool => $event['type'] === 'output'));
        $values = array_map(static fn (array $event): string => base64_decode($event['value_base64']), $output);

        expect($result?->succeeded)->toBeTrue()
            ->and(array_is_list($events))->toBeTrue()
            ->and(array_column($events, 'type'))->toContain('output_truncated')
            ->and(implode('', $values))->toContain('Best-effort step failed (exit 42): warm')
            ->and(array_sum(array_map(strlen(...), $values)))->toBeLessThanOrEqual(131_072);
    });

    it('coalesces waiting pushes by resolving the newest commit only after acquiring the Instance lock', function (): void {
        $lock = Mockery::mock(InstanceEnvironmentOperationLock::class);
        $lock->shouldReceive('run')->once()->with([$this->instance->id], Mockery::type(Closure::class))
            ->andReturnUsing(function (array $ids, Closure $operation): mixed {
                // Simulate pushes arriving while this request waits for another deployment.
                $this->remote->targetCommit = str_repeat('c', 40);
                $this->remote->targetCommit = str_repeat('d', 40);

                return $operation();
            });
        app()->instance(InstanceEnvironmentOperationLock::class, $lock);
        $result = app(DeployDefaultInstanceAction::class)->execute($this->instance, onlyChanged: true, triggeredBy: 'schedule');

        expect($result?->release?->commit)->toBe(str_repeat('d', 40))
            ->and($this->remote->trace)->not->toContain('prepare:'.str_repeat('b', 40), 'prepare:'.str_repeat('c', 40))
            ->and(InstanceDeployment::query()->count())->toBe(1);
    });

    it('picks up a push during a deployment on the next tick and skips unchanged ticks without history', function (): void {
        $this->remote->pushDuringBuild = str_repeat('c', 40);
        $action = app(DeployDefaultInstanceAction::class);
        $first = $action->execute($this->instance, onlyChanged: true, triggeredBy: 'schedule');
        $next = $action->execute($this->instance, onlyChanged: true, triggeredBy: 'schedule');
        $unchanged = $action->execute($this->instance, onlyChanged: true, triggeredBy: 'schedule');

        expect($first?->release?->commit)->toBe(str_repeat('b', 40))
            ->and($next?->release?->commit)->toBe(str_repeat('c', 40))
            ->and($unchanged)->toBeNull()
            ->and(InstanceDeployment::query()->count())->toBe(2);
    });

    it('records failures and retries them on the next scheduled tick', function (): void {
        dev935_steps($this->instance, [new DevelopmentDeployStep('build', 'false')]);
        $this->remote->failedStep = 'build';
        $action = app(DeployDefaultInstanceAction::class);
        $action->execute($this->instance, onlyChanged: true, triggeredBy: 'schedule');
        $this->remote->failedStep = null;
        $result = $action->execute($this->instance, onlyChanged: true, triggeredBy: 'schedule');

        expect(InstanceDeployment::query()->orderBy('id')->pluck('status')->all())->toBe(['failed', 'succeeded'])
            ->and($result?->succeeded)->toBeTrue();
    });

    it('reports the actual selection after an activation receipt failure', function (): void {
        $this->remote->failAfterSwitch = true;
        $result = app(DeployInstanceAction::class)->execute($this->instance);

        expect($result->succeeded)->toBeFalse()
            ->and($result->selectedRelease?->name)->toBe('release-1')
            ->and($result->failure?->boundary)->toBe(DeploymentFailureBoundary::Activation);
    });

    it('refuses other development Instances and unsafe source states before any remote work', function (array $attributes): void {
        $this->instance->update($attributes);
        $result = app(DeployDefaultInstanceAction::class)->execute($this->instance);

        expect($result?->failure?->errorCode)->toBe('deployment_config.unavailable')
            ->and($this->remote->trace)->toBe([]);
    })->with([
        'task workspace' => [['name' => 'task-935']],
        'linked registered worktree' => [['source_layout' => 'linked_worktree']],
        'source resolved only' => [['status' => 'source_resolved']],
    ]);

    it('deploys every app-dev default on a scheduled tick and leaves other Instances alone', function (): void {
        $otherDefault = dev935_instance('second-default', 'default');
        dev935_instance('ordinary', 'task-935');
        dev935_instance('production', 'default', 'app-prod');

        $this->artisan('orbit:deploy-development-defaults')->assertSuccessful();

        expect(InstanceDeployment::query()->orderBy('instance_id')->pluck('instance_id')->all())
            ->toBe([$this->instance->id, $otherDefault->id])
            ->and(InstanceDeployment::query()->pluck('triggered_by')->unique()->all())->toBe(['schedule']);
    });
});

function dev935_instance(string $slug = 'dev935', string $name = 'default', string $role = 'app-dev'): Instance
{
    $node = Node::query()->create(['name' => $slug, 'platform' => 'linux', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.35', 'wireguard_ip' => '10.44.0.'.(Node::query()->count() + 35)]);
    $node->roles()->create(['role' => $role, 'status' => 'active']);
    $project = Project::query()->create(['name' => $slug, 'slug' => $slug, 'type' => 'monorepo', 'repository_url' => 'https://example.test/'.$slug.'.git', 'default_branch' => 'main']);

    return Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name, 'checkout_path' => '/fast/apps/'.$slug.'/'.$name, 'source_layout' => 'checkout', 'branch' => 'main', 'status' => 'active']);
}

/** @param list<DevelopmentDeployStep> $steps */
function dev935_steps(Instance $instance, array $steps): void
{
    foreach ($steps as $step) {
        app(ProjectDevelopmentDeployStepStore::class)->create($instance->project, $step, null, null);
    }
}

final class Dev935Deployment implements DevelopmentDeployment
{
    /** @var list<string> */
    public array $trace = [];

    public string $targetCommit;

    public ?string $pushDuringBuild = null;

    public ?string $failedStep = null;

    public bool $failAfterSwitch = false;

    public bool $noisyFailure = false;

    /** @var array<int, DeploymentRelease> */
    private array $selections = [];

    private int $sequence = 0;

    public function __construct()
    {
        $this->targetCommit = str_repeat('b', 40);
    }

    public function initialize(Instance $instance): void
    {
        $this->trace[] = 'initialize';
        $this->selections[$instance->id] ??= new DeploymentRelease('initial', $instance->checkout_path.'/releases/initial', str_repeat('a', 40));
    }

    public function target(Instance $instance): string
    {
        $this->trace[] = 'target:'.$instance->project->default_branch;

        return $this->targetCommit;
    }

    public function selected(Instance $instance): DeploymentRelease
    {
        $this->trace[] = 'selected';

        return $this->selections[$instance->id];
    }

    public function releases(Instance $instance): DeploymentReleaseState
    {
        return new DeploymentReleaseState([$this->selections[$instance->id]->name], $this->selections[$instance->id]->name);
    }

    public function prepare(Instance $instance, string $commit): DeploymentRelease
    {
        $this->trace[] = 'prepare:'.$commit;
        if ($this->pushDuringBuild !== null) {
            $this->targetCommit = $this->pushDuringBuild;
            $this->pushDuringBuild = null;
        }
        $name = 'release-'.++$this->sequence;

        return new DeploymentRelease($name, $instance->checkout_path.'/releases/'.$name, $commit);
    }

    public function executeStep(Instance $instance, DeploymentRelease $release, DevelopmentDeployStep $step, DeploymentRequest $request): CommandResult
    {
        $this->trace[] = 'step:'.$step->name;
        if ($step->name === $this->failedStep) {
            if ($this->noisyFailure) {
                for ($i = 0; $i < 12; $i++) {
                    $request->emit(new DeploymentEvent($step->name, DeploymentOutputStream::Stderr, str_repeat('x', 16_384)));
                }
            }
            throw new RuntimeConvergenceException('deployment-step-'.$step->name, 'deployment.step_failed', 'Failed step.', result: new CommandResult(42, '', 'failure', 1, false));
        }

        return new CommandResult(0, '', '', 1, false);
    }

    public function activate(Instance $instance, DeploymentRelease $release): DeploymentRelease
    {
        $this->trace[] = 'activate:'.$release->name;
        $this->selections[$instance->id] = $release;
        if ($this->failAfterSwitch) {
            throw new RuntimeConvergenceException('activate', 'deployment.activate_failed', 'Lost receipt.');
        }

        return $release;
    }

    public function prune(Instance $instance, DeploymentRelease $selected): void
    {
        $this->trace[] = 'prune:'.$selected->name;
    }
}
