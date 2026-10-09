<?php

declare(strict_types=1);

use App\Actions\Instances\RunInstanceSetupAction;
use App\Actions\Instances\SelectInstanceSeedAction;
use App\Data\Instances\InstanceData;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\Deployment\DevelopmentDeployment;
use App\Domain\Instances\InstanceState;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\LifecycleSshExecutor;

beforeEach(function (): void {
    $this->sandbox = sys_get_temp_dir().'/orbit-lifecycle-'.Str::uuid();
    mkdir($this->sandbox, 0700);
    $project = Project::query()->create(['name' => 'Lifecycle', 'slug' => 'lifecycle', 'repository_url' => 'https://example.test/lifecycle.git', 'apps' => fixture_apps(null)]);
    $node = Node::query()->create(['name' => 'lifecycle', 'public_ssh_host' => '192.0.2.8', 'wireguard_ip' => '192.0.2.8']);
    $this->instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'dev',
        'checkout_path' => $this->sandbox, 'status' => InstanceState::Active,
    ]);
    $this->steps = new ProjectLifecycleStepStore;
    $this->transport = new LifecycleSshExecutor(local: true);
    $this->runner = $this->transport->runner();
    app()->instance(ProjectLifecycleRunner::class, $this->runner);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->sandbox);
});

it('exports resolved VP_HOME to project-local vp in non-login lifecycle shells', function (string $home, LifecyclePhase $phase): void {
    mkdir($this->sandbox.'/node_modules/.bin', 0755, true);
    file_put_contents($this->sandbox.'/node_modules/.bin/vp', "#!/bin/sh\nprintf '%s' \"\$VP_HOME\" > vp-home\n");
    chmod($this->sandbox.'/node_modules/.bin/vp', 0755);
    $this->steps->create($this->instance->project, $phase, new LifecycleStep('vp-home', './node_modules/.bin/vp'), null, null);
    $runner = $this->transport->runner(null, $home);

    expect($runner->run($this->instance, $phase))->toBeTrue();
    expect(file_get_contents($this->sandbox.'/vp-home'))->toBe($home);
})->with([
    ['/opt/orbit/vite-plus', LifecyclePhase::Setup],
    ['/home/orbit/.local/share/vite-plus', LifecyclePhase::Setup],
    ['/home/orbit/.vite-plus', LifecyclePhase::Teardown],
]);

it('passes the default release seed to setup and keeps the selection on retry', function (): void {
    $default = Instance::query()->create([
        'project_id' => $this->instance->project_id, 'node_id' => $this->instance->node_id,
        'name' => 'default', 'checkout_path' => '/fast/apps/lifecycle/default',
        'development_release_layout' => true, 'seed_path' => '/fast/apps/lifecycle/default/releases/initial',
        'seed_commit' => str_repeat('a', 40), 'status' => InstanceState::Active,
    ]);
    app()->instance(DevelopmentDeployment::class, Mockery::mock(DevelopmentDeployment::class)->shouldReceive('selected')->andReturnUsing(fn (): DeploymentRelease => new DeploymentRelease('initial', $default->seed_path, $default->seed_commit))->getMock());
    $this->instance->update(['status' => InstanceState::Reserved]);
    app(SelectInstanceSeedAction::class)->execute($this->instance);
    $this->instance->update(['status' => InstanceState::SourceResolved]);
    $this->steps->create($this->instance->project, LifecyclePhase::Setup, new LifecycleStep('seed', 'printf "%s\\n%s" "$ORBIT_SEED_PATH" "$ORBIT_SEED_COMMIT" > seed'), null, null);
    $this->runner->run($this->instance, LifecyclePhase::Setup);
    expect(file_get_contents($this->sandbox.'/seed'))->toBe($default->seed_path."\n".$default->seed_commit);
    $default->update(['seed_commit' => str_repeat('b', 40)]);
    $this->instance->update(['seed_selected' => false]); // A legacy writer already recorded the snapshot.
    app(SelectInstanceSeedAction::class)->execute($this->instance);
    $this->runner->run($this->instance, LifecyclePhase::Setup);
    expect($this->instance->refresh()->seed_commit)->toBe(str_repeat('a', 40))
        ->and($this->instance->seed_selected)->toBeTrue();
    $data = InstanceData::fromModel($default->refresh())->toArray();
    expect($data['seed_path'])->toBe($default->seed_path)->and($data['seed_commit'])->toBe(str_repeat('b', 40));
});

it('keeps an explicitly empty seed through setup and retries after a default release appears', function (): void {
    $this->instance->update(['status' => InstanceState::Reserved]);
    $deployment = Mockery::mock(DevelopmentDeployment::class);
    $deployment->shouldNotReceive('selected');
    app()->instance(DevelopmentDeployment::class, $deployment);
    $selector = app(SelectInstanceSeedAction::class);
    $selector->execute($this->instance);
    $this->instance->update(['starting_commit' => str_repeat('a', 40), 'status' => InstanceState::SourceResolved]);
    Instance::query()->create([
        'project_id' => $this->instance->project_id, 'node_id' => $this->instance->node_id,
        'name' => 'default', 'checkout_path' => '/fast/apps/lifecycle/default',
        'development_release_layout' => true, 'seed_path' => '/fast/apps/lifecycle/default/releases/later',
        'seed_commit' => str_repeat('b', 40), 'status' => InstanceState::Active,
    ]);
    $this->steps->create($this->instance->project, LifecyclePhase::Setup, new LifecycleStep('cold', 'test -z "$ORBIT_SEED_PATH"; test -z "$ORBIT_SEED_COMMIT"'), null, null);
    $this->runner->run($this->instance, LifecyclePhase::Setup);
    $selector->execute($this->instance);
    $this->runner->run($this->instance, LifecyclePhase::Setup);
    expect($this->instance->refresh()->seed_selected)->toBeTrue()
        ->and($this->instance->seed_path)->toBeNull()
        ->and($this->instance->seed_commit)->toBeNull()
        ->and($this->instance->starting_commit)->toBe(str_repeat('a', 40));
});

it('runs the lists in the active release of a default Instance with the release layout', function (LifecyclePhase $phase): void {
    $release = $this->sandbox.'/releases/20261008120000-active';
    mkdir($release, 0700, true);
    $this->instance->update(['name' => 'default', 'development_release_layout' => true, 'seed_path' => $release, 'seed_commit' => str_repeat('a', 40)]);
    $deployment = Mockery::mock(DevelopmentDeployment::class);
    $deployment->shouldReceive('selected')->once()->andReturn(new DeploymentRelease('20261008120000-active', $release, str_repeat('a', 40)));
    // The release is the default Instance's own seed, so setup gets no seed to copy from.
    $this->steps->create($this->instance->project, $phase, new LifecycleStep('where', 'pwd > ran-here; test -z "$ORBIT_SEED_PATH"; test -z "$ORBIT_SEED_COMMIT"'), null, null);

    expect($this->transport->runner(deployment: $deployment)->run($this->instance, $phase))->toBeTrue()
        ->and(file_get_contents($release.'/ran-here'))->toBe($release."\n")
        ->and(file_exists($this->sandbox.'/ran-here'))->toBeFalse()
        ->and($this->transport->inputs[0]['checkout'])->toBe($this->sandbox)
        ->and($this->transport->inputs[0]['directory'])->toBe($release)
        ->and($this->transport->inputs[0]['environment_directory'])->toBe($phase === LifecyclePhase::Setup ? '' : null);
})->with([LifecyclePhase::Setup, LifecyclePhase::Teardown]);

it('gives setup in an active release no seed even when the stored seed names an older release', function (): void {
    $release = $this->sandbox.'/releases/20261009120000-new';
    mkdir($release, 0700, true);
    // A deploy moved `current` after the seed was recorded.
    $this->instance->update(['name' => 'default', 'development_release_layout' => true, 'seed_path' => $this->sandbox.'/releases/initial', 'seed_commit' => str_repeat('a', 40)]);
    $deployment = Mockery::mock(DevelopmentDeployment::class);
    $deployment->shouldReceive('selected')->once()->andReturn(new DeploymentRelease('20261009120000-new', $release, str_repeat('b', 40)));
    $this->steps->create($this->instance->project, LifecyclePhase::Setup, new LifecycleStep('cold', 'test -z "$ORBIT_SEED_PATH"; test -z "$ORBIT_SEED_COMMIT"; touch installed'), null, null);

    expect($this->transport->runner(deployment: $deployment)->run($this->instance, LifecyclePhase::Setup))->toBeTrue()
        ->and(file_exists($release.'/installed'))->toBeTrue();
});

it('copies the synchronized environment files into the active release before setup', function (?string $root, string $application): void {
    $release = $this->sandbox.'/releases/20261009120000-active';
    $source = rtrim($this->sandbox.'/'.$application, '/');
    $target = rtrim($release.'/'.$application, '/');
    is_dir($source) || mkdir($source, 0700, true);
    mkdir($target, 0700, true);
    file_put_contents($source.'/.env', "DB_DATABASE=fresh\n");
    chmod($source.'/.env', 0600);
    file_put_contents($source.'/.env.testing', "DB_DATABASE=fresh_test\n");
    chmod($source.'/.env.testing', 0640);
    // The release still has the files its deploy copied.
    file_put_contents($target.'/.env', "DB_DATABASE=stale\n");
    $this->instance->update(['name' => 'default', 'development_release_layout' => true, 'source_is_laravel' => true, 'app_overrides' => fixture_app_overrides($root)]);
    $deployment = Mockery::mock(DevelopmentDeployment::class);
    $deployment->shouldReceive('selected')->once()->andReturn(new DeploymentRelease('20261009120000-active', $release, str_repeat('a', 40)));
    $directory = $application === '' ? '.' : $application;
    $this->steps->create($this->instance->project, LifecyclePhase::Setup, new LifecycleStep('migrate', "cat {$directory}/.env > migrated-with"), null, null);

    expect($this->transport->runner(deployment: $deployment)->run($this->instance, LifecyclePhase::Setup))->toBeTrue()
        ->and(file_get_contents($release.'/migrated-with'))->toBe("DB_DATABASE=fresh\n")
        ->and(file_get_contents($target.'/.env.testing'))->toBe("DB_DATABASE=fresh_test\n")
        ->and(fileperms($target.'/.env') & 0777)->toBe(0600)
        ->and(fileperms($target.'/.env.testing') & 0777)->toBe(0640)
        ->and(fileowner($target.'/.env'))->toBe(fileowner($source.'/.env'))
        ->and(glob($target.'/.orbit-environment-*', GLOB_NOSORT) ?: [])->toBe([])
        ->and($this->transport->inputs[0]['environment_directory'])->toBe($application);
})->with([
    'an application at the repository root' => [null, ''],
    'a nested application' => ['apps/site/public', 'apps/site'],
]);

it('copies no environment files for setup in the checkout', function (): void {
    file_put_contents($this->sandbox.'/.env', "DB_DATABASE=fresh\n");
    $this->steps->create($this->instance->project, LifecyclePhase::Setup, new LifecycleStep('install', 'true'), null, null);

    expect($this->runner->run($this->instance, LifecyclePhase::Setup))->toBeTrue()
        ->and($this->transport->inputs[0]['environment_directory'])->toBeNull();
});

it('runs teardown in the checkout when the active release cannot be read, so removal can finish', function (): void {
    $this->instance->update(['name' => 'default', 'development_release_layout' => true]);
    $deployment = Mockery::mock(DevelopmentDeployment::class);
    $deployment->shouldReceive('selected')->once()->andThrow(new ResourceOperationException('deployment.invalid_release', 'Development deployment returned an invalid release.', 409));
    $this->steps->create($this->instance->project, LifecyclePhase::Teardown, new LifecycleStep('where', 'pwd > ran-here'), null, null);
    Log::spy();

    expect($this->transport->runner(deployment: $deployment)->run($this->instance, LifecyclePhase::Teardown))->toBeTrue()
        ->and(file_get_contents($this->sandbox.'/ran-here'))->toBe($this->sandbox."\n")
        ->and($this->transport->inputs[0]['directory'])->toBe($this->sandbox)
        ->and($this->transport->inputs[0]['environment_directory'])->toBeNull();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context === ['instance_id' => $this->instance->id, 'error' => 'deployment.invalid_release']);
});

it('runs the lists in the checkout of an Instance without the release layout', function (): void {
    $deployment = Mockery::mock(DevelopmentDeployment::class);
    $deployment->shouldNotReceive('selected');
    $this->steps->create($this->instance->project, LifecyclePhase::Setup, new LifecycleStep('where', 'pwd > ran-here'), null, null);

    expect($this->transport->runner(deployment: $deployment)->run($this->instance, LifecyclePhase::Setup))->toBeTrue()
        ->and(file_get_contents($this->sandbox.'/ran-here'))->toBe($this->sandbox."\n")
        ->and($this->transport->inputs[0]['directory'])->toBe($this->sandbox);
});

it('starts no step when the active release cannot be read', function (): void {
    $this->instance->update(['name' => 'default', 'development_release_layout' => true]);
    $deployment = Mockery::mock(DevelopmentDeployment::class);
    $deployment->shouldReceive('selected')->andThrow(new ResourceOperationException('deployment.invalid_release', 'Development deployment returned an invalid release.', 409));
    $this->steps->create($this->instance->project, LifecyclePhase::Setup, new LifecycleStep('install', 'touch installed'), null, null);

    expect(fn () => $this->transport->runner(deployment: $deployment)->run($this->instance, LifecyclePhase::Setup))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.active_release_unavailable')
                ->and($exception->status)->toBe(409);
        });
    expect($this->transport->inputs)->toBeEmpty()
        ->and(file_exists($this->sandbox.'/installed'))->toBeFalse();
});

it('refuses a lifecycle directory outside the checkout releases', function (string $directory): void {
    mkdir($this->sandbox.'/releases/one', 0700, true);
    mkdir($this->sandbox.'-sibling', 0700);
    symlink($this->sandbox.'/releases/one', $this->sandbox.'/releases/link');
    $this->instance->update(['name' => 'default', 'development_release_layout' => true]);
    $path = str_replace('{checkout}', $this->sandbox, $directory);
    $deployment = Mockery::mock(DevelopmentDeployment::class);
    $deployment->shouldReceive('selected')->andReturn(new DeploymentRelease('one', $path, str_repeat('a', 40)));
    $this->steps->create($this->instance->project, LifecyclePhase::Setup, new LifecycleStep('install', 'touch installed'), null, null);

    try {
        expect(fn () => $this->transport->runner(deployment: $deployment)->run($this->instance, LifecyclePhase::Setup))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('instance.setup_step_failed')
                    ->and($exception->details)->toBe(['step' => 'install', 'outcome' => 'unconfirmed']);
            });
        expect(file_exists($path.'/installed'))->toBeFalse();
    } finally {
        (new Filesystem)->deleteDirectory($this->sandbox.'-sibling');
    }
})->with([
    'a sibling directory' => ['{checkout}-sibling'],
    'a link to a release' => ['{checkout}/releases/link'],
    'a parent reference' => ['{checkout}/releases/one/../one'],
]);

it('passes empty seed values when the Project has no release', function (): void {
    $this->steps->create($this->instance->project, LifecyclePhase::Setup, new LifecycleStep('cold', 'test -z "$ORBIT_SEED_PATH"; test -z "$ORBIT_SEED_COMMIT"; touch installed'), null, null);
    expect($this->runner->run($this->instance, LifecyclePhase::Setup))->toBeTrue()
        ->and(file_exists($this->sandbox.'/installed'))->toBeTrue();
});

it('reports a busy lifecycle lock as a busy result', function (): void {
    $this->steps->create($this->instance->project, LifecyclePhase::Setup, new LifecycleStep('install', 'true'), null, null);
    $transport = new LifecycleSshExecutor(result: static fn (): int => 75);

    expect(fn () => $transport->runner()->run($this->instance, LifecyclePhase::Setup))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.lifecycle_busy')
                ->and($exception->status)->toBe(409)
                ->and($exception->details)->toBe(['step' => 'install', 'outcome' => 'busy']);
        });
});

it('runs an ordered snapshot from the checkout and keeps commands out of argv', function (): void {
    $this->steps->create($this->instance->project, LifecyclePhase::Setup, new LifecycleStep('first', 'printf first > result'), null, null);
    $this->steps->create($this->instance->project, LifecyclePhase::Setup, new LifecycleStep('second', 'printf second >> result'), null, null);
    expect($this->runner->run($this->instance, LifecyclePhase::Setup))->toBeTrue()
        ->and(file_get_contents($this->sandbox.'/result'))->toBe('firstsecond')
        ->and(implode('', $this->transport->shells))->not->toContain('printf first', 'printf second');
});

it('stops after a failed step and preserves the instance on explicit setup', function (): void {
    $this->steps->create($this->instance->project, LifecyclePhase::Setup, new LifecycleStep('broken', 'echo secret-output; exit 41'), null, null);
    $this->steps->create($this->instance->project, LifecyclePhase::Setup, new LifecycleStep('later', 'touch later'), null, null);
    try {
        app(RunInstanceSetupAction::class)->execute($this->instance);
        $this->fail('Setup must fail.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('instance.setup_step_failed')
            ->and($exception->details)->toBe(['step' => 'broken', 'outcome' => 'failed'])
            ->and((string) $exception)->not->toContain('secret-output');
    }
    expect(Instance::query()->whereKey($this->instance->id)->exists())->toBeTrue()
        ->and(file_exists($this->sandbox.'/later'))->toBeFalse()
        ->and($this->transport->inputs)->toHaveCount(1);
});

it('reports a teardown step whose command is missing as unavailable', function (): void {
    $this->steps->create($this->instance->project, LifecyclePhase::Teardown,
        new LifecycleStep('task-e2e-bridge', '/nonexistent/orbit/e2e-task-cleanup'), null, null);

    expect(fn () => $this->runner->run($this->instance, LifecyclePhase::Teardown))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.teardown_step_unavailable')
                ->and($exception->details)->toBe(['step' => 'task-e2e-bridge', 'outcome' => 'missing'])
                ->and($exception->getMessage())->toContain('task-e2e-bridge', 'not found', 'lifecycle', '127');
            $previous = $exception->getPrevious();
            $result = $previous instanceof RuntimeConvergenceException ? $previous->result : null;
            expect($result?->exitCode)->toBe(127)
                ->and($result?->stderr)->toContain('/nonexistent/orbit/e2e-task-cleanup');
        });
});

it('reports non-executable lifecycle commands as unavailable with a bounded stderr tail', function (LifecyclePhase $phase): void {
    file_put_contents($this->sandbox.'/cleanup', '#!/usr/bin/bash');
    chmod($this->sandbox.'/cleanup', 0600);
    $this->steps->create($this->instance->project, $phase,
        new LifecycleStep('cleanup', 'printf "%1048576s" "" >&2; printf diagnostic-tail >&2; ./cleanup'), null, null);
    $this->steps->create($this->instance->project, $phase,
        new LifecycleStep('later', 'touch later'), null, null);

    expect(fn () => $this->runner->run($this->instance, $phase))
        ->toThrow(function (ResourceOperationException $exception) use ($phase): void {
            expect($exception->errorCode)->toBe('instance.'.$phase->value.'_step_unavailable')
                ->and($exception->details)->toBe(['step' => 'cleanup', 'outcome' => 'missing'])
                ->and($exception->getMessage())->toContain('cleanup', 'not found', '126')
                ->and($exception->getMessage())->not->toContain('diagnostic-tail');
            $previous = $exception->getPrevious();
            $result = $previous instanceof RuntimeConvergenceException ? $previous->result : null;
            expect($result?->exitCode)->toBe(126)
                ->and(strlen($result?->stderr ?? ''))->toBe(1024)
                ->and($result?->stderr)->toContain('diagnostic-tail', 'Permission denied');
        });
    expect(file_exists($this->sandbox.'/later'))->toBeFalse();
})->with([LifecyclePhase::Setup, LifecyclePhase::Teardown]);

it('reports a setup step whose command is missing as unavailable', function (): void {
    $this->steps->create($this->instance->project, LifecyclePhase::Setup,
        new LifecycleStep('install', '/nonexistent/orbit/install'), null, null);

    expect(fn () => $this->runner->run($this->instance, LifecyclePhase::Setup))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.setup_step_unavailable')
                ->and($exception->details)->toBe(['step' => 'install', 'outcome' => 'missing'])
                ->and($exception->getMessage())->toContain('install', 'not found');
        });
});

it('reports other teardown command failures as failed', function (): void {
    $this->steps->create($this->instance->project, LifecyclePhase::Teardown,
        new LifecycleStep('cleanup', 'exit 41'), null, null);

    expect(fn () => $this->runner->run($this->instance, LifecyclePhase::Teardown))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.teardown_step_failed')
                ->and($exception->details)->toBe(['step' => 'cleanup', 'outcome' => 'failed']);
        });
});

it('kills the timed-out command group before returning failure', function (): void {
    $this->steps->create($this->instance->project, LifecyclePhase::Setup,
        new LifecycleStep('slow', "(sleep 2; touch escaped) &\nwait", 1), null, null);
    expect(fn () => $this->runner->run($this->instance, LifecyclePhase::Setup))->toThrow(ResourceOperationException::class);
    usleep(1_200_000);
    expect(file_exists($this->sandbox.'/escaped'))->toBeFalse();
});

it('refuses setup for an unfinished instance before executing commands', function (): void {
    $this->instance->update(['status' => InstanceState::SourceResolved]);
    expect(fn () => app(RunInstanceSetupAction::class)->execute($this->instance))->toThrow(ResourceOperationException::class)
        ->and($this->transport->inputs)->toBeEmpty();
});

it('does not mistake transport loss for a confirmed command failure', function (): void {
    $transport = new LifecycleSshExecutor(result: static fn (): int => 255);
    $this->steps->create($this->instance->project, LifecyclePhase::Setup, new LifecycleStep('install', 'true'), null, null);
    try {
        $transport->runner()->run($this->instance, LifecyclePhase::Setup);
        $this->fail('Transport must fail.');
    } catch (ResourceOperationException $exception) {
        expect($exception->details)->toBe(['step' => 'install', 'outcome' => 'unconfirmed']);
    }
});

it('keeps remote cleanup time inside the remaining request deadline', function (): void {
    $deadline = new CommandDeadline(static fn (): float => 10.0);
    $deadline->start(8.0);
    $transport = new LifecycleSshExecutor;
    $this->steps->create($this->instance->project, LifecyclePhase::Setup, new LifecycleStep('install', 'true', 10), null, null);
    $transport->runner($deadline)->run($this->instance, LifecyclePhase::Setup);
    expect($transport->inputs[0]['timeout'])->toBe(3);
});

it('stops a stored list over the total limit cleanly when the request deadline runs out', function (): void {
    foreach (['first', 'second', 'third'] as $position => $name) {
        ProjectLifecycleStep::query()->create([
            'project_id' => $this->instance->project_id,
            'phase' => 'setup',
            'name' => $name,
            'command' => 'true',
            'timeout_seconds' => 540,
            'position' => $position,
        ]);
    }

    $now = 0.0;
    $deadline = new CommandDeadline(static function () use (&$now): float {
        return $now;
    });
    $deadline->start(570.0, CommandDeadline::CleanupReserveSeconds);
    $transport = new LifecycleSshExecutor(result: static function () use (&$now): int {
        $now += 300.0;

        return 0;
    });

    expect(fn () => $transport->runner($deadline)->run($this->instance, LifecyclePhase::Setup))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('command.deadline_exceeded')
                ->and($exception->status)->toBe(504)
                ->and($exception->details)->toBe(['step' => 'third', 'outcome' => 'deadline'])
                ->and($exception->getMessage())->toBe(
                    'Setup step [third] did not start: the request deadline has no time left for it. '
                    ."Lower the list's step timeouts so the whole list fits one request.",
                );
        });

    // The first step gets the whole forward budget less the runner's margin; no step outlives the deadline.
    expect(array_column($transport->inputs, 'timeout'))->toBe([540, 245]);
});

it('reports a step the request deadline stopped mid-run apart from a step that timed out on its own', function (int $stepTimeout, string $code, array $details, string $message): void {
    foreach (['first', 'second'] as $position => $name) {
        ProjectLifecycleStep::query()->create([
            'project_id' => $this->instance->project_id,
            'phase' => 'teardown',
            'name' => $name,
            'command' => 'sleep 1000',
            'timeout_seconds' => $stepTimeout,
            'position' => $position,
        ]);
    }

    $now = 0.0;
    $deadline = new CommandDeadline(static function () use (&$now): float {
        return $now;
    });
    $deadline->start(570.0, CommandDeadline::CleanupReserveSeconds);
    $transport = new LifecycleSshExecutor(result: static function (array $payload) use (&$now): int {
        // Each command runs until its timeout, as a remote `sleep` would.
        $now += $payload['timeout'];

        return 124;
    });
    $now = 250.0;

    expect(fn () => $transport->runner($deadline)->run($this->instance, LifecyclePhase::Teardown))
        ->toThrow(function (ResourceOperationException $exception) use ($code, $details, $message): void {
            expect($exception->errorCode)->toBe($code)
                ->and($exception->details)->toBe($details)
                ->and($exception->getMessage())->toBe($message);
        });
})->with([
    'cut by the deadline' => [540, 'command.deadline_exceeded', ['step' => 'first', 'outcome' => 'deadline'],
        "Teardown step [first] was stopped by the request deadline after 295 seconds, before its own 540-second timeout. Lower the list's step timeouts so the whole list fits one request."],
    'its own timeout' => [60, 'instance.teardown_step_failed', ['step' => 'first', 'outcome' => 'failed'], 'Teardown step failed.'],
]);
