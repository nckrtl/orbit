<?php

declare(strict_types=1);

use App\Actions\Instances\RunInstanceSetupAction;
use App\Actions\Instances\SelectInstanceSeedAction;
use App\Data\Instances\InstanceData;
use App\Domain\AppDev\RuntimeConvergenceException;
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
use Illuminate\Support\Str;
use Tests\Support\LifecycleSshExecutor;

beforeEach(function (): void {
    $this->sandbox = sys_get_temp_dir().'/orbit-lifecycle-'.Str::uuid();
    mkdir($this->sandbox, 0700);
    $project = Project::query()->create(['name' => 'Lifecycle', 'slug' => 'lifecycle', 'repository_url' => 'https://example.test/lifecycle.git']);
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

it('passes the default checkout seed to setup and keeps the selection on retry', function (): void {
    $default = Instance::query()->create([
        'project_id' => $this->instance->project_id, 'node_id' => $this->instance->node_id,
        'name' => 'default', 'checkout_path' => '/fast/apps/lifecycle/default',
        'seed_path' => '/fast/apps/lifecycle/default', 'seed_repository' => '/fast/apps/lifecycle/default',
        'seed_commit' => str_repeat('a', 40), 'status' => InstanceState::Active,
    ]);
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

it('reads the seed again for each step, so a moved seed reaches a running setup', function (): void {
    $this->instance->update(['seed_selected' => true, 'seed_path' => '/fast/apps/lifecycle/default/releases/initial', 'seed_commit' => str_repeat('a', 40)]);
    $this->steps->create($this->instance->project, LifecyclePhase::Setup, new LifecycleStep('first', 'true'), null, null);
    $this->steps->create($this->instance->project, LifecyclePhase::Setup, new LifecycleStep('second', 'printf "%s" "$ORBIT_SEED_PATH" > seed'), null, null);
    $transport = new LifecycleSshExecutor(function (array $payload): int {
        // Converting the default's old release layout moves the seed between two steps.
        Instance::query()->whereKey($this->instance->id)->update(['seed_path' => '/fast/apps/lifecycle/default']);

        return 0;
    });

    expect($transport->runner()->run($this->instance, LifecyclePhase::Setup))->toBeTrue()
        ->and($transport->inputs[0]['environment']['ORBIT_SEED_PATH'])->toBe('/fast/apps/lifecycle/default/releases/initial')
        ->and($transport->inputs[1]['environment']['ORBIT_SEED_PATH'])->toBe('/fast/apps/lifecycle/default');
});

it('gives the setup of a default no seed, because its seed is its own checkout', function (): void {
    $this->instance->update(['name' => 'default', 'seed_path' => $this->sandbox, 'seed_repository' => $this->sandbox, 'seed_commit' => str_repeat('a', 40)]);
    $this->steps->create($this->instance->project, LifecyclePhase::Setup, new LifecycleStep('cold', 'test -z "$ORBIT_SEED_PATH"; test -z "$ORBIT_SEED_COMMIT"; pwd > ran-here'), null, null);

    expect($this->runner->run($this->instance, LifecyclePhase::Setup))->toBeTrue()
        ->and(file_get_contents($this->sandbox.'/ran-here'))->toBe($this->sandbox."\n");
});

it('keeps an explicitly empty seed through setup and retries after a default release appears', function (): void {
    $this->instance->update(['status' => InstanceState::Reserved]);
    $selector = app(SelectInstanceSeedAction::class);
    $selector->execute($this->instance);
    $this->instance->update(['starting_commit' => str_repeat('a', 40), 'status' => InstanceState::SourceResolved]);
    Instance::query()->create([
        'project_id' => $this->instance->project_id, 'node_id' => $this->instance->node_id,
        'name' => 'default', 'checkout_path' => '/fast/apps/lifecycle/default',
        'seed_path' => '/fast/apps/lifecycle/default', 'seed_repository' => '/fast/apps/lifecycle/default',
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

it('runs the lists in the checkout', function (LifecyclePhase $phase): void {
    $this->steps->create($this->instance->project, $phase, new LifecycleStep('where', 'pwd > ran-here'), null, null);

    expect($this->runner->run($this->instance, $phase))->toBeTrue()
        ->and(file_get_contents($this->sandbox.'/ran-here'))->toBe($this->sandbox."\n")
        ->and($this->transport->inputs[0]['checkout'])->toBe($this->sandbox);
})->with([LifecyclePhase::Setup, LifecyclePhase::Teardown]);

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
