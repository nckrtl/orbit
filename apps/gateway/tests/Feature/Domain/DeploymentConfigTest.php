<?php

declare(strict_types=1);

use App\Actions\AppInstances\UpdateAppInstanceDeploymentConfigAction;
use App\Domain\AppInstances\Deployment\DeploymentConfig;
use App\Domain\AppInstances\Deployment\DeploymentPhase;
use App\Domain\AppInstances\Deployment\DeploymentStep;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;

it('normalizes valid ordered steps and the default timeout', function (): void {
    $config = new DeploymentConfig('release/next', [
        new DeploymentStep('warm-cache', DeploymentPhase::BeforeActivation, 'php artisan cache:warm'),
        new DeploymentStep('restart-workers', DeploymentPhase::AfterActivation, 'php artisan queue:restart', 90),
        new DeploymentStep('migrate', DeploymentPhase::BeforeActivation, 'php artisan migrate', 600),
    ]);

    expect($config->normalizedSteps())->toBe([
        [
            'name' => 'warm-cache',
            'phase' => 'before_activation',
            'command' => 'php artisan cache:warm',
            'timeout_seconds' => 300,
        ],
        [
            'name' => 'restart-workers',
            'phase' => 'after_activation',
            'command' => 'php artisan queue:restart',
            'timeout_seconds' => 90,
        ],
        [
            'name' => 'migrate',
            'phase' => 'before_activation',
            'command' => 'php artisan migrate',
            'timeout_seconds' => 600,
        ],
    ]);
});

it('rejects invalid branch step and command values without disclosing commands', function (
    Closure $make,
): void {
    $sentinel = 'secret-command-sentinel';

    try {
        $make($sentinel);
        $this->fail('Expected the deployment configuration to be rejected.');
    } catch (InvalidArgumentException $exception) {
        expect($exception->getMessage())->not->toContain($sentinel);
    }
})->with([
    'branch' => fn (): DeploymentConfig => new DeploymentConfig('bad..branch', []),
    'empty name' => fn (string $command): DeploymentStep => new DeploymentStep(
        '', DeploymentPhase::BeforeActivation, $command,
    ),
    'uppercase name' => fn (string $command): DeploymentStep => new DeploymentStep(
        'Warm', DeploymentPhase::BeforeActivation, $command,
    ),
    'long name' => fn (string $command): DeploymentStep => new DeploymentStep(
        str_repeat('a', 64), DeploymentPhase::BeforeActivation, $command,
    ),
    'empty command' => fn (): DeploymentStep => new DeploymentStep(
        'step', DeploymentPhase::BeforeActivation, '',
    ),
    'nul command' => fn (string $command): DeploymentStep => new DeploymentStep(
        'step', DeploymentPhase::BeforeActivation, $command."\0",
    ),
    'invalid utf8' => fn (): DeploymentStep => new DeploymentStep(
        'step', DeploymentPhase::BeforeActivation, "\xC3\x28",
    ),
    'long command' => fn (): DeploymentStep => new DeploymentStep(
        'step', DeploymentPhase::BeforeActivation, str_repeat('x', 16 * 1024 + 1),
    ),
    'zero timeout' => fn (string $command): DeploymentStep => new DeploymentStep(
        'step', DeploymentPhase::BeforeActivation, $command, 0,
    ),
    'large timeout' => fn (string $command): DeploymentStep => new DeploymentStep(
        'step', DeploymentPhase::BeforeActivation, $command, 901,
    ),
]);

it('enforces unique names and aggregate limits while accepting empty steps', function (): void {
    expect(new DeploymentConfig('main', [])->steps)->toBe([]);

    $duplicate = fn (): DeploymentConfig => new DeploymentConfig('main', [
        new DeploymentStep('same', DeploymentPhase::BeforeActivation, 'first'),
        new DeploymentStep('same', DeploymentPhase::AfterActivation, 'second'),
    ]);
    $tooMany = fn (): DeploymentConfig => new DeploymentConfig('main', array_map(
        fn (int $index): DeploymentStep => new DeploymentStep(
            "step-{$index}", DeploymentPhase::BeforeActivation, 'true', 1,
        ),
        range(1, 33),
    ));
    $tooLong = fn (): DeploymentConfig => new DeploymentConfig('main', [
        new DeploymentStep('one', DeploymentPhase::BeforeActivation, 'true', 900),
        new DeploymentStep('two', DeploymentPhase::BeforeActivation, 'true', 900),
        new DeploymentStep('three', DeploymentPhase::AfterActivation, 'true', 900),
        new DeploymentStep('four', DeploymentPhase::AfterActivation, 'true', 900),
        new DeploymentStep('five', DeploymentPhase::AfterActivation, 'true', 1),
    ]);

    expect($duplicate)->toThrow(InvalidArgumentException::class)
        ->and($tooMany)->toThrow(InvalidArgumentException::class)
        ->and($tooLong)->toThrow(InvalidArgumentException::class);
});

it('accepts the exact step count command byte and timeout-total boundaries', function (): void {
    $steps = array_map(
        fn (int $index): DeploymentStep => new DeploymentStep(
            name: $index === 1 ? str_repeat('a', 63) : "step-{$index}",
            phase: $index % 2 === 0
                ? DeploymentPhase::AfterActivation
                : DeploymentPhase::BeforeActivation,
            command: $index === 1 ? str_repeat('x', 16 * 1024) : 'true',
            timeoutSeconds: $index <= 4 ? 893 : 1,
        ),
        range(1, 32),
    );
    $config = new DeploymentConfig('release/boundaries', $steps);

    expect($config->steps)->toHaveCount(32)
        ->and(array_sum(array_column($config->normalizedSteps(), 'timeout_seconds')))->toBe(3_600);
});

it('accepts the exact aggregate timeout boundary', function (): void {
    $config = new DeploymentConfig('release/boundary', [
        new DeploymentStep('one', DeploymentPhase::BeforeActivation, str_repeat('x', 16 * 1024), 900),
        new DeploymentStep('two', DeploymentPhase::AfterActivation, 'true', 900),
        new DeploymentStep('three', DeploymentPhase::BeforeActivation, 'true', 900),
        new DeploymentStep('four', DeploymentPhase::AfterActivation, 'true', 900),
    ]);

    expect($config->steps)->toHaveCount(4)
        ->and(array_sum(array_column($config->normalizedSteps(), 'timeout_seconds')))->toBe(3_600);
});

it('updates only stored deployment intent without changing source evidence or current', function (): void {
    [$app, $first, $second] = deployment_domain_fixture();
    app()->instance(SshExecutor::class, new class implements SshExecutor
    {
        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            throw new RuntimeException('Deployment configuration contacted SSH.');
        }
    });
    $sandbox = sys_get_temp_dir().'/orb-218-'.bin2hex(random_bytes(6));
    mkdir($sandbox);
    file_put_contents($sandbox.'/current', 'unchanged');

    try {
        $config = new DeploymentConfig('release/next', [
            new DeploymentStep('migrate', DeploymentPhase::BeforeActivation, 'php artisan migrate'),
        ]);

        app(UpdateAppInstanceDeploymentConfigAction::class)->execute($first, $config);
        $app->update(['default_branch' => 'future-default']);

        expect($first->fresh())
            ->deployment_branch->toBe('release/next')
            ->branch->toBe('main')
            ->branch_override->toBe('main')
            ->and($second->fresh())
            ->deployment_branch->toBe('stable')
            ->branch->toBe('stable')
            ->branch_override->toBeNull()
            ->and(file_get_contents($sandbox.'/current'))->toBe('unchanged');
    } finally {
        unlink($sandbox.'/current');
        rmdir($sandbox);
    }
});

/** @return array{OrbitApp, AppInstance, AppInstance} */
function deployment_domain_fixture(): array
{
    $app = OrbitApp::query()->create([
        'name' => 'Deployment domain',
        'slug' => 'deployment-domain',
        'repository_url' => 'https://example.test/deployment-domain.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $nodes = array_map(fn (int $number): Node => Node::query()->create([
        'name' => "deployment-node-{$number}",
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => "192.0.2.{$number}",
    ]), [121, 122]);
    $instances = [];

    foreach ([['first', 'main', 'main'], ['second', 'stable', null]] as $index => [$name, $branch, $override]) {
        $instances[] = AppInstance::query()->create([
            'app_id' => $app->id,
            'node_id' => $nodes[$index]->id,
            'name' => $name,
            'environment' => 'production',
            'checkout_path' => "/home/{$name}/releases/initial",
            'branch' => $branch,
            'branch_override' => $override,
            'deployment_branch' => $branch,
            'status' => 'source_resolved',
        ]);
    }

    return [$app, ...$instances];
}
