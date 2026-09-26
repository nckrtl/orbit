<?php

declare(strict_types=1);

use App\Actions\AppInstances\RunInstanceSetupAction;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\ProjectLifecycleStep;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Tests\Support\LifecycleSshExecutor;

beforeEach(function (): void {
    $this->sandbox = sys_get_temp_dir().'/orbit-lifecycle-'.Str::uuid();
    mkdir($this->sandbox, 0700);
    $project = OrbitApp::query()->create(['name' => 'Lifecycle', 'slug' => 'lifecycle', 'repository_url' => 'https://example.test/lifecycle.git']);
    $node = Node::query()->create(['name' => 'lifecycle', 'public_ssh_host' => '192.0.2.8', 'wireguard_ip' => '192.0.2.8']);
    $this->instance = AppInstance::query()->create([
        'app_id' => $project->id, 'node_id' => $node->id, 'name' => 'dev',
        'checkout_path' => $this->sandbox, 'status' => AppInstanceState::Active,
    ]);
    $this->steps = new ProjectLifecycleStepStore;
    $this->transport = new LifecycleSshExecutor(local: true);
    $this->runner = $this->transport->runner();
    app()->instance(ProjectLifecycleRunner::class, $this->runner);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->sandbox);
});

it('runs an ordered snapshot from the checkout and keeps commands out of argv', function (): void {
    $this->steps->create($this->instance->app, LifecyclePhase::Setup, new LifecycleStep('first', 'printf first > result'), null, null);
    $this->steps->create($this->instance->app, LifecyclePhase::Setup, new LifecycleStep('second', 'printf second >> result'), null, null);
    expect($this->runner->run($this->instance, LifecyclePhase::Setup))->toBeTrue()
        ->and(file_get_contents($this->sandbox.'/result'))->toBe('firstsecond')
        ->and(implode('', $this->transport->shells))->not->toContain('printf first', 'printf second');
});

it('stops after a failed step and preserves the instance on explicit setup', function (): void {
    $this->steps->create($this->instance->app, LifecyclePhase::Setup, new LifecycleStep('broken', 'echo secret-output; exit 41'), null, null);
    $this->steps->create($this->instance->app, LifecyclePhase::Setup, new LifecycleStep('later', 'touch later'), null, null);
    try {
        app(RunInstanceSetupAction::class)->execute($this->instance);
        $this->fail('Setup must fail.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('instance.setup_step_failed')
            ->and($exception->details)->toBe(['step' => 'broken', 'outcome' => 'failed'])
            ->and((string) $exception)->not->toContain('secret-output');
    }
    expect(AppInstance::query()->whereKey($this->instance->id)->exists())->toBeTrue()
        ->and(file_exists($this->sandbox.'/later'))->toBeFalse()
        ->and($this->transport->inputs)->toHaveCount(1);
});

it('kills the timed-out command group before returning failure', function (): void {
    $this->steps->create($this->instance->app, LifecyclePhase::Setup,
        new LifecycleStep('slow', "(sleep 2; touch escaped) &\nwait", 1), null, null);
    expect(fn () => $this->runner->run($this->instance, LifecyclePhase::Setup))->toThrow(ResourceOperationException::class);
    usleep(1_200_000);
    expect(file_exists($this->sandbox.'/escaped'))->toBeFalse();
});

it('refuses setup for an unfinished instance before executing commands', function (): void {
    $this->instance->update(['status' => AppInstanceState::SourceResolved]);
    expect(fn () => app(RunInstanceSetupAction::class)->execute($this->instance))->toThrow(ResourceOperationException::class)
        ->and($this->transport->inputs)->toBeEmpty();
});

it('does not mistake transport loss for a confirmed command failure', function (): void {
    $transport = new LifecycleSshExecutor(result: static fn (): int => 255);
    $this->steps->create($this->instance->app, LifecyclePhase::Setup, new LifecycleStep('install', 'true'), null, null);
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
    $this->steps->create($this->instance->app, LifecyclePhase::Setup, new LifecycleStep('install', 'true', 10), null, null);
    $transport->runner($deadline)->run($this->instance, LifecyclePhase::Setup);
    expect($transport->inputs[0]['timeout'])->toBe(3);
});

it('stops a stored list over the total limit cleanly when the request deadline runs out', function (): void {
    foreach (['first', 'second', 'third'] as $position => $name) {
        ProjectLifecycleStep::query()->create([
            'app_id' => $this->instance->app_id,
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
        ->toThrow(ResourceOperationException::class);

    // The first step gets the whole forward budget less the runner's margin; no step outlives the deadline.
    expect(array_column($transport->inputs, 'timeout'))->toBe([540, 245]);
});
