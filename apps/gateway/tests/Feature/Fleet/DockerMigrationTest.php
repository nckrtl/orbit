<?php

declare(strict_types=1);

use App\Actions\Fleet\MigrateDockerAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgram;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Fleet\NodeShell;
use App\Infrastructure\MachineSoftware\NodeRecipeProgram;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Nodes\NodeUpdateLock;
use App\Models\Node;
use App\Models\Process as OrbitProcess;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\Fleet\FleetTestSsh;
use Tests\Support\Fleet\ScriptedSshExecutor;
use Tests\Support\ProcessesApiFakeRuntimeManager;

it('requires explicit Docker migration consent before any SSH', function (): void {
    $ssh = new ScriptedSshExecutor;
    app()->instance(NodeShell::class, FleetTestSsh::shell($ssh));

    expect(Artisan::call('node:migrate-docker', ['node' => 'Beast']))->toBe(1)
        ->and(Artisan::output())->toContain('--yes is required')
        ->and($ssh->commands)->toBe([]);
});

it('refuses a Docker migration recipe mismatch before any SSH', function (): void {
    $ssh = new ScriptedSshExecutor;
    app()->instance(NodeShell::class, FleetTestSsh::shell($ssh));

    expect(Artisan::call('node:migrate-docker', ['node' => 'Beast', '--yes' => true, '--recipe' => 'sha256:other']))->toBe(1)
        ->and(Artisan::output())->toContain('differs from this checkout')
        ->and($ssh->commands)->toBe([]);
});

it('translates the Docker CE recipe refusal to a typed reason with the explicit migration command', function (): void {
    $node = FleetFixtures::node('ce-provider', [RoleName::AppProd]);
    $ssh = new ScriptedSshExecutor;
    $ssh->on('/^python3 - inspect /', ScriptedSshExecutor::fail(stdout: 'recipe-stage:docker-provider:docker-ce'));
    app()->instance(NodeShell::class, FleetTestSsh::shell($ssh));
    $recipe = new RecipeLoader()->load();
    $program = app(NodeRecipeProgram::class);

    try {
        $program->inspect($node, $recipe, $program->tools($node, $recipe));
        $this->fail('Docker CE must refuse recipe convergence.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('node.recipe_docker_provider')
            ->and($exception->getMessage())->toContain('docker-provider:docker-ce', 'node:migrate-docker '.$node->id.' --dry-run');
    }
    expect($ssh->commands)->toHaveCount(1)
        ->and($node->refresh()->settings['machine_recipe'] ?? null)->toBeNull();
});

it('runs the Docker plan as an ADR 0211 program with stdin input and recipe containerd', function (): void {
    $node = FleetFixtures::node('docker-plan', [RoleName::AppProd]);
    $runner = Mockery::mock(RemoteProgramRunner::class);
    $runner->shouldReceive('run')->once()->withArgs(function (Node $target, RemoteProgram $program, array $input) use ($node): bool {
        $recipe = new RecipeLoader()->load();
        expect($target->id)->toBe($node->id)
            ->and($program->name)->toBe('docker.migrate')
            ->and($program->user)->toBe('root')
            ->and($program->timeoutSeconds)->toBe(3600)
            ->and($program->outcomes)->toBe(['planned', 'prepared', 'reported', 'failed'])
            ->and($program->source)->toContain('def snapshot_options(', 'def retire_sources(', 'def transient_prepare(')
            ->and($input['docker_version'])->toBe($recipe->tools['docker.io']->version)
            ->and($input['containerd_version'])->toBe($recipe->tools['containerd']->version)
            ->and($input['mode'])->toBe('plan')
            ->and($input['fallback'])->toBeTrue()
            ->and($input['process_ids'])->toBe([]);

        return true;
    })->andReturn(new ProgramResult('planned', ['containers' => ['mailpit']], ''));
    app()->instance(RemoteProgramRunner::class, $runner);

    expect(Artisan::call('node:migrate-docker', ['node' => $node->name, '--dry-run' => true, '--fallback' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('mailpit');
});

it('reports final states after reconciling stored specs even when a Process fails', function (bool $failConverge): void {
    $node = FleetFixtures::node('docker-specs', [RoleName::AppProd]);
    $other = FleetFixtures::node('docker-other', [RoleName::AppProd]);
    $attributes = ['owner_type' => $node->getMorphClass(), 'owner_id' => $node->id, 'name' => 'mysql',
        'runtime' => 'docker', 'runtime_config' => ['image' => 'mysql:9', 'environment' => ['PASSWORD' => 'private']],
        'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active'];
    $process = OrbitProcess::query()->create($attributes);
    OrbitProcess::query()->create([...$attributes, 'owner_id' => $other->id]);
    $runtime = new ProcessesApiFakeRuntimeManager;
    $runtime->failConverge = $failConverge;
    app()->instance(ProcessRuntimeManager::class, $runtime);
    $runner = Mockery::mock(RemoteProgramRunner::class);
    $runner->shouldReceive('run')->once()->withArgs(function (Node $node, RemoteProgram $program, array $input) use ($process): bool {
        expect($input['mode'])->toBe('prepare')
            ->and($input['process_ids'])->toBe([(string) $process->id])
            ->and($input['process_states']->{(string) $process->id})->toBe('running')
            ->and(json_encode($input))->not->toContain('private');

        return true;
    })->andReturn(new ProgramResult('prepared', ['fallback' => true, 'process_ids' => [(string) $process->id]], ''));
    $runner->shouldReceive('run')->once()->withArgs(function (Node $node, RemoteProgram $program, array $input) use ($runtime, $process): bool {
        expect($input['mode'])->toBe('report')->and($runtime->convergedProcessIds)->toBe([$process->id]);

        return true;
    })->andReturn(new ProgramResult('reported', ['healthy' => true, 'containers' => [['name' => 'mysql', 'state' => 'running']]], ''));
    app()->instance(RemoteProgramRunner::class, $runner);
    app()->instance(NodeUpdateLock::class, new NodeUpdateLock(FleetTestSsh::shell(new ScriptedSshExecutor)));

    $report = app(MigrateDockerAction::class)->execute($node, new RecipeLoader()->load(), false);

    expect($report['healthy'])->toBe(! $failConverge)->and($runtime->convergedProcessIds)->toBe([$process->id]);
    if ($failConverge) {
        expect($report['failed_process_ids'])->toBe([$process->id]);
    }
})->with(['success' => false, 'Process failure' => true]);

it('reports final states on remote failure without reconciling Processes or exposing logs', function (): void {
    $node = FleetFixtures::node('docker-failure', [RoleName::AppProd]);
    $runner = Mockery::mock(RemoteProgramRunner::class);
    $runner->shouldReceive('run')->once()->andReturn(new ProgramResult('failed', [
        'failure' => 'docker-migration:swapping', 'containers' => [['name' => 'mailpit', 'state' => 'unreadable']],
    ], 'PASSWORD=private'));
    app()->instance(RemoteProgramRunner::class, $runner);
    app()->instance(NodeUpdateLock::class, new NodeUpdateLock(FleetTestSsh::shell(new ScriptedSshExecutor)));
    $runtime = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $runtime);

    expect(Artisan::call('node:migrate-docker', ['node' => $node->name, '--yes' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('docker-migration:swapping', 'Resume', 'mailpit', 'unreadable')
        ->not->toContain('PASSWORD=private')
        ->and($runtime->convergedProcessIds)->toBe([]);
});

it('executes the Docker inventory swap fallback preservation and resume regression programs', function (): void {
    $process = new Process(['python3', base_path('tests/Programs/test_docker_migration.py'), '-v']);
    $process->mustRun();

    expect($process->getErrorOutput())->toMatch('/Ran [1-9][0-9]* tests/')->toContain('OK');
});
