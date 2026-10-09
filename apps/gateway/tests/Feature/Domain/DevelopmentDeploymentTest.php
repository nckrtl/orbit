<?php

declare(strict_types=1);

use App\Actions\Instances\DeployDefaultInstanceAction;
use App\Actions\Instances\DeployInstanceAction;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\Deployment\DeploymentEvent;
use App\Domain\Instances\Deployment\DeploymentFailureBoundary;
use App\Domain\Instances\Deployment\DeploymentOutputStream;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DevelopmentDeployment;
use App\Domain\Instances\Deployment\DevelopmentTarget;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Projects\ProjectDevelopmentDeployStepStore;
use App\Domain\Shared\ResourceOperationException;
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
    it('checks out the default branch in place and runs the Project steps there', function (): void {
        $this->instance->update(['deployment_branch' => 'ignored-production-override']);
        dev935_steps($this->instance, [new DevelopmentDeployStep('install', 'incremental install'), new DevelopmentDeployStep('build', 'build')]);
        $result = app(DeployInstanceAction::class)->execute($this->instance);
        $instance = $this->instance->refresh();

        expect($result->succeeded)->toBeTrue()
            ->and($result->commit)->toBe(str_repeat('b', 40))
            ->and($result->release)->toBeNull()
            ->and($result->selectedRelease)->toBeNull()
            ->and(array_column($result->commands, 'step'))->toBe(['install', 'build'])
            ->and($this->remote->trace)->toBe(['target:main', 'checkout:'.str_repeat('b', 40), 'step:install', 'step:build'])
            ->and($instance->checkout_path)->toBe('/fast/apps/dev935/default')
            ->and($instance->seed_path)->toBe('/fast/apps/dev935/default')
            ->and($instance->seed_repository)->toBe('/fast/apps/dev935/default')
            ->and($instance->seed_commit)->toBe(str_repeat('b', 40))
            ->and($instance->development_release_layout)->toBeFalse();
    });

    it('serves a visitable default from its checkout', function (): void {
        $instance = $this->instance;
        $instance->update(['root' => 'public', 'selected_php_version' => '8.5', 'source_is_laravel' => true]);
        $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'domain' => 'dev935.example.test', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => 'active']);
        $projector = Mockery::mock(DevelopmentRouteProjector::class);
        $projector->shouldReceive('converge')->once()->andReturnUsing(function (Instance $projected) use ($instance): void {
            $site = new DevelopmentSiteRepository()->forNode($projected->node)->sole();
            $configuration = new DevelopmentCaddyConfigRenderer()->render(collect([$site]));
            expect($site->checkoutPath)->toBe($instance->checkout_path)
                ->and($configuration)->toContain('root * '.$instance->checkout_path.'/public')
                ->and($configuration)->not->toContain('resolve_root_symlink');
        });
        app()->instance(DevelopmentRouteProjector::class, $projector);

        expect(app(DeployDefaultInstanceAction::class)->execute($instance)?->succeeded)->toBeTrue()
            ->and($instance->fresh()->development_projection_pending)->toBeFalse();
        // A deployment in place does not move the Route, so it does not converge it again.
        expect(app(DeployDefaultInstanceAction::class)->execute($instance)?->succeeded)->toBeTrue();
    });

    it('converts an old release layout once, serves the checkout, and moves seeds off the releases', function (): void {
        $instance = $this->instance;
        $release = $instance->checkout_path.'/releases/20261005112105-a200f174bf2565c7';
        $instance->update(['development_release_layout' => true, 'development_projection_pending' => false, 'root' => 'public', 'selected_php_version' => '8.5', 'source_is_laravel' => true,
            'seed_path' => $instance->checkout_path.'/releases/20261009164518-56c418b8ebd18e71', 'seed_commit' => str_repeat('a', 40), 'seed_repository' => $instance->checkout_path]);
        $worktree = Instance::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'name' => 't3code-36e980ba', 'checkout_path' => '/fast/apps/dev935/t3code-36e980ba',
            'source_layout' => 'worktree', 'status' => 'active', 'seed_selected' => true, 'seed_path' => $release, 'seed_commit' => str_repeat('f', 40), 'seed_repository' => $instance->checkout_path]);
        $elsewhere = Instance::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'name' => 'cold', 'checkout_path' => '/fast/apps/dev935/cold',
            'source_layout' => 'checkout', 'status' => 'active', 'seed_selected' => true]);
        $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'domain' => 'convert935.example.test', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => 'active']);
        $this->remote->convertedCommit = str_repeat('a', 40);
        $this->remote->releasesRemain = true;
        $this->remote->targetCommit = str_repeat('a', 40);
        $projector = Mockery::mock(DevelopmentRouteProjector::class);
        $projector->shouldReceive('converge')->once()->andReturnUsing(function (Instance $projected): void {
            expect(new DevelopmentSiteRepository()->forNode($projected->node)->sole()->checkoutPath)->toBe($projected->checkout_path)
                ->and($this->remote->trace)->toBe(['convert']);
        });
        app()->instance(DevelopmentRouteProjector::class, $projector);

        $first = app(DeployDefaultInstanceAction::class)->execute($instance, onlyChanged: true, triggeredBy: 'schedule');
        $instance->refresh();

        expect($first)->toBeNull()
            ->and($this->remote->trace)->toBe(['convert', 'target:main', 'remove-releases:/fast/apps/dev935/t3code-36e980ba'])
            ->and($instance->development_release_layout)->toBeFalse()
            ->and($instance->development_projection_pending)->toBeFalse()
            ->and($instance->seed_path)->toBe($instance->checkout_path)
            ->and($instance->seed_commit)->toBe(str_repeat('a', 40))
            ->and($worktree->fresh()->seed_path)->toBe($instance->checkout_path)
            ->and($worktree->fresh()->seed_commit)->toBe(str_repeat('f', 40))
            ->and($elsewhere->fresh()->seed_path)->toBeNull()
            ->and(InstanceDeployment::query()->count())->toBe(0);

        $this->remote->trace = [];
        $this->remote->releasesRemain = false;
        expect(app(DeployDefaultInstanceAction::class)->execute($instance, onlyChanged: true, triggeredBy: 'schedule'))->toBeNull()
            ->and($this->remote->trace)->toBe(['target:main']);
    });

    it('keeps deploying when the old releases cannot be removed yet', function (): void {
        $this->instance->update(['seed_path' => $this->instance->checkout_path, 'seed_commit' => str_repeat('a', 40), 'seed_repository' => $this->instance->checkout_path]);
        $this->remote->releasesRemain = true;
        $this->remote->removalFailure = new ResourceOperationException('instance.lifecycle_busy', 'A setup or teardown step is running in the checkout.', 409);
        $events = [];
        $result = app(DeployDefaultInstanceAction::class)->execute($this->instance, new DeploymentRequest(static function (DeploymentEvent $event) use (&$events): void {
            $events[] = $event;
        }));

        expect($result?->succeeded)->toBeTrue()
            ->and($this->remote->trace)->toBe(['target:main', 'remove-releases:', 'checkout:'.str_repeat('b', 40)])
            ->and($events[0]->step)->toBe('cleanup')
            ->and($events[0]->value)->toContain('later deployment retries');
    });

    it('fails without changing the seed when a conversion fails', function (): void {
        $this->instance->update(['development_release_layout' => true, 'seed_path' => $this->instance->checkout_path.'/releases/initial', 'seed_commit' => str_repeat('a', 40), 'seed_repository' => $this->instance->checkout_path]);
        $this->remote->convertFailure = new ResourceOperationException('deployment.checkout_dirty', 'The checkout has uncommitted changes to tracked files.', 409);

        $result = app(DeployDefaultInstanceAction::class)->execute($this->instance, onlyChanged: true, triggeredBy: 'schedule');

        expect($result?->failure?->errorCode)->toBe('deployment.checkout_dirty')
            ->and($result?->failure?->boundary)->toBe(DeploymentFailureBoundary::Preparation)
            ->and($this->remote->trace)->toBe(['convert'])
            ->and($this->instance->fresh()->development_release_layout)->toBeTrue()
            ->and($this->instance->fresh()->seed_path)->toBe($this->instance->checkout_path.'/releases/initial')
            ->and(InstanceDeployment::query()->sole()->status)->toBe('failed');
    });

    it('retries a failed route projection before skipping an unchanged scheduled tick', function (): void {
        $instance = $this->instance;
        $instance->update(['root' => 'public', 'selected_php_version' => '8.5', 'source_is_laravel' => true, 'seed_commit' => str_repeat('a', 40)]);
        $this->remote->targetCommit = str_repeat('a', 40);
        $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'domain' => 'recover935.example.test', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => 'active']);
        $calls = 0;
        $projector = Mockery::mock(DevelopmentRouteProjector::class);
        $projector->shouldReceive('converge')->twice()->andReturnUsing(function () use (&$calls): void {
            if (++$calls === 1) {
                throw new RuntimeConvergenceException('projection', 'app-dev.source_access_failed', 'Lost convergence.');
            }
        });
        app()->instance(DevelopmentRouteProjector::class, $projector);
        $first = app(DeployDefaultInstanceAction::class)->execute($instance, onlyChanged: true, triggeredBy: 'schedule');
        expect($first?->succeeded)->toBeFalse()
            ->and($instance->fresh()->development_projection_pending)->toBeTrue();
        $second = app(DeployDefaultInstanceAction::class)->execute($instance, onlyChanged: true, triggeredBy: 'schedule');
        expect($second)->toBeNull()
            ->and($calls)->toBe(2)
            ->and($instance->fresh()->development_projection_pending)->toBeFalse()
            ->and(InstanceDeployment::query()->count())->toBe(1);
    });

    it('skips the projection lock for an unchanged scheduled tick with a current projection', function (): void {
        $instance = $this->instance;
        $instance->update(['development_projection_pending' => false, 'root' => 'public', 'selected_php_version' => '8.5', 'source_is_laravel' => true, 'seed_commit' => str_repeat('a', 40)]);
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

    it('leaves the checkout at the new commit and keeps the seed when a required step fails', function (): void {
        $this->instance->update(['seed_path' => $this->instance->checkout_path, 'seed_commit' => str_repeat('a', 40), 'seed_repository' => $this->instance->checkout_path]);
        dev935_steps($this->instance, [new DevelopmentDeployStep('install', 'false'), new DevelopmentDeployStep('build', 'never')]);
        $this->remote->failedStep = 'install';
        $result = app(DeployInstanceAction::class)->execute($this->instance);

        expect($result->succeeded)->toBeFalse()
            ->and($result->failure?->boundary)->toBe(DeploymentFailureBoundary::BeforeActivation)
            ->and($result->failure?->errorCode)->toBe('deployment.step_failed')
            ->and($result->commit)->toBe(str_repeat('b', 40))
            ->and($result->selectedRelease)->toBeNull()
            ->and($result->commands[0]->result->exitCode)->toBe(42)
            ->and($this->remote->trace)->toBe(['target:main', 'checkout:'.str_repeat('b', 40), 'step:install'])
            ->and($this->instance->fresh()->seed_commit)->toBe(str_repeat('a', 40));
    });

    it('records a dirty checkout as a failed preparation', function (): void {
        $this->remote->checkoutFailure = new ResourceOperationException('deployment.checkout_dirty', 'The checkout has uncommitted changes to tracked files.', 409);
        $result = app(DeployDefaultInstanceAction::class)->execute($this->instance, onlyChanged: true, triggeredBy: 'schedule');
        $record = InstanceDeployment::query()->sole();

        expect($result?->failure?->errorCode)->toBe('deployment.checkout_dirty')
            ->and($result?->failure?->boundary)->toBe(DeploymentFailureBoundary::Preparation)
            ->and($record->status)->toBe('failed')
            ->and($record->commit)->toBe(str_repeat('b', 40))
            ->and($record->release)->toBeNull()
            ->and($this->instance->fresh()->seed_commit)->toBeNull();
    });

    it('continues after a best-effort failure and records its explicit warning and exit code', function (): void {
        dev935_steps($this->instance, [new DevelopmentDeployStep('warm', 'false', required: false), new DevelopmentDeployStep('build', 'true')]);
        $this->remote->failedStep = 'warm';
        $events = [];
        $result = app(DeployDefaultInstanceAction::class)->execute($this->instance, new DeploymentRequest(static function (DeploymentEvent $event) use (&$events): void {
            $events[] = $event;
        }), onlyChanged: true, triggeredBy: 'schedule');

        expect($result?->succeeded)->toBeTrue()
            ->and($result?->commands[0]->result->exitCode)->toBe(42)
            ->and($this->remote->trace)->toContain('step:build')
            ->and($events[0]->step)->toBe('warm')
            ->and($events[0]->value)->toContain('Best-effort step failed (exit 42)')
            ->and(InstanceDeployment::query()->sole()->status)->toBe('succeeded')
            ->and(InstanceDeployment::query()->sole()->commit)->toBe(str_repeat('b', 40))
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

        expect($result?->commit)->toBe(str_repeat('d', 40))
            ->and($this->remote->trace)->toBe(['target:main', 'checkout:'.str_repeat('d', 40)])
            ->and(InstanceDeployment::query()->count())->toBe(1);
    });

    it('picks up a push during a deployment on the next tick and skips unchanged ticks without history', function (): void {
        $this->remote->pushDuringCheckout = str_repeat('c', 40);
        $action = app(DeployDefaultInstanceAction::class);
        $first = $action->execute($this->instance, onlyChanged: true, triggeredBy: 'schedule');
        $next = $action->execute($this->instance, onlyChanged: true, triggeredBy: 'schedule');
        $unchanged = $action->execute($this->instance, onlyChanged: true, triggeredBy: 'schedule');

        expect($first?->commit)->toBe(str_repeat('b', 40))
            ->and($next?->commit)->toBe(str_repeat('c', 40))
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
            ->and($result?->succeeded)->toBeTrue()
            ->and($this->instance->fresh()->seed_commit)->toBe(str_repeat('b', 40));
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

    public string $convertedCommit;

    public bool $releasesRemain = false;

    public ?string $pushDuringCheckout = null;

    public ?string $failedStep = null;

    public bool $noisyFailure = false;

    public ?Throwable $convertFailure = null;

    public ?Throwable $checkoutFailure = null;

    public ?Throwable $removalFailure = null;

    public function __construct()
    {
        $this->targetCommit = str_repeat('b', 40);
        $this->convertedCommit = str_repeat('a', 40);
    }

    public function convert(Instance $instance): string
    {
        $this->trace[] = 'convert';
        if ($this->convertFailure !== null) {
            throw $this->convertFailure;
        }

        return $this->convertedCommit;
    }

    public function target(Instance $instance): DevelopmentTarget
    {
        $this->trace[] = 'target:'.$instance->project->default_branch;

        return new DevelopmentTarget($this->targetCommit, $this->releasesRemain);
    }

    public function checkout(Instance $instance, string $commit, DeploymentRequest $request): void
    {
        $this->trace[] = 'checkout:'.$commit;
        if ($this->checkoutFailure !== null) {
            throw $this->checkoutFailure;
        }
        if ($this->pushDuringCheckout !== null) {
            $this->targetCommit = $this->pushDuringCheckout;
            $this->pushDuringCheckout = null;
        }
    }

    public function executeStep(Instance $instance, DevelopmentDeployStep $step, DeploymentRequest $request): CommandResult
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

    public function removeReleases(Instance $instance, array $consumers): void
    {
        $this->trace[] = 'remove-releases:'.implode(',', $consumers);
        if ($this->removalFailure !== null) {
            throw $this->removalFailure;
        }
        $this->releasesRemain = false;
    }
}
