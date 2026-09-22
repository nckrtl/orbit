<?php

declare(strict_types=1);

use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Tasks\RemoteTaskWorkspacePreparer;
use App\Infrastructure\Tasks\TaskMainCache;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Support\Facades\File;
use Tests\Support\AppDevFakeSshExecutor;

function preparation_instance(bool $configured = true): AppInstance
{
    $project = OrbitApp::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://example.test/orbit.git']);
    if ($configured) {
        app(ProjectLifecycleStepStore::class)->create($project, LifecyclePhase::Setup,
            new LifecycleStep('bootstrap', 'bin/bootstrap', 845), null, null);
    }

    return new AppInstance(['checkout_path' => '/srv/orbit/task with spaces', 'branch' => 'task-1', 'starting_commit' => str_repeat('c', 40)])
        ->setRelation('app', $project)
        ->setRelation('node', new Node(['wireguard_ip' => '10.44.0.130', 'user' => 'orbit']));
}

function preparation_cache(string $directory): TaskMainCache
{
    return new TaskMainCache(new class($directory) implements ProcessRunner
    {
        public function __construct(private string $directory) {}

        public function run(ProcessInvocation $invocation): CommandResult
        {
            expect($invocation->arguments[0])->toBe('git');

            return new CommandResult(0, $this->directory, '', 1, false);
        }
    });
}

it('transfers only main publications through stdin and consumes successful setup', function (): void {
    $directory = sys_get_temp_dir().'/orbit-main-publications-'.bin2hex(random_bytes(8));
    mkdir($directory.'/orbit-tia/v1/published', 0o700, true);
    $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));
    file_put_contents($directory.'/orbit-tia/v1/published/apps-cli.json', '{"main":"published"}');
    file_put_contents($directory.'/orbit-tia/v1/private-graph.json', 'mutable feature data');
    symlink($directory.'/orbit-tia/v1/private-graph.json', $directory.'/orbit-tia/v1/published/apps-docs.json');
    $instance = preparation_instance();
    app(ProjectLifecycleStepStore::class)->create($instance->app, LifecyclePhase::Setup,
        new LifecycleStep('verify', 'printf secret-step-sentinel', 5), null, null);
    $response = ['prepared' => true, 'checkout' => $instance->checkout_path, 'commit' => $instance->starting_commit];
    $transport = new class($response) implements SshExecutor
    {
        /** @param array<string, mixed> $response */
        public function __construct(private array $response) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            expect($command->arguments)->toBe(['python3', '-c', file_get_contents(resource_path('tasks/prepare.py')), '/srv/orbit/task with spaces', 'task-1', str_repeat('c', 40)]);
            expect($command->timeout)->toBe(880.0);
            expect($command->input)->toBeNull();
            expect($command->shellCommand())->not->toContain('bin/bootstrap');
            $payload = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
            expect(json_decode(gzdecode(base64_decode($payload['publications'])), true))->toBe(['published/apps-cli.json' => '{"main":"published"}']);
            expect($payload['steps'])->toBe([
                ['name' => 'bootstrap', 'command' => 'bin/bootstrap', 'timeout_seconds' => 845],
                ['name' => 'verify', 'command' => 'printf secret-step-sentinel', 'timeout_seconds' => 5],
            ]);
            expect($command->shellCommand())->not->toContain('secret-step-sentinel');
            expect($payload['lifecycle'])->toBe(file_get_contents(resource_path('instances/lifecycle.py')));
            expect($connection->knownHostsFile)->not->toBe('');

            return new CommandResult(0, json_encode($this->response), '', 1, false);
        }
    };
    app()->instance(SshExecutor::class, $transport);

    $preparer = new RemoteTaskWorkspacePreparer(app(AppDevSshExecutor::class), preparation_cache($directory), app(ProjectLifecycleStepStore::class));
    $preparer->prepare($instance);
});

it('refuses failed truncated or mismatched setup without exposing remote output', function (array $response, bool $truncated, int $exit): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult($exit, json_encode($response), 'private-output-sentinel', 1, $truncated)]);
    app()->instance(SshExecutor::class, $transport);
    $preparer = new RemoteTaskWorkspacePreparer(app(AppDevSshExecutor::class), preparation_cache('/no-such-main-cache'), app(ProjectLifecycleStepStore::class));

    try {
        $preparer->prepare(preparation_instance());
        test()->fail('Failed setup was accepted.');
    } catch (ResourceOperationException $exception) {
        expect($exception->getMessage())->toContain('setup failed')->not->toContain('private-output-sentinel');
    }
})->with([
    'exit failure' => [[], false, 1],
    'truncated' => [[], true, 0],
    'missing result' => [[], false, 0],
    'wrong checkout' => [['prepared' => true, 'checkout' => '/another', 'commit' => str_repeat('c', 40)], false, 0],
    'wrong commit' => [['prepared' => true, 'checkout' => '/srv/orbit/task with spaces', 'commit' => str_repeat('d', 40)], false, 0],
]);

it('treats absent main publications as an empty cache source', function (): void {
    expect(preparation_cache('/no-such-main-cache')->publications())->toBe([]);
});

it('skips publications that exceed the shared memory budget and keeps smaller remaining files', function (): void {
    $directory = sys_get_temp_dir().'/orbit-main-publications-'.bin2hex(random_bytes(8));
    mkdir($directory.'/orbit-tia/v1/published', 0o700, true);
    $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));
    foreach (['apps-cli', 'apps-docs'] as $project) {
        file_put_contents($directory.'/orbit-tia/v1/published/'.$project.'.json', str_repeat('x', 20_000_000));
    }
    file_put_contents($directory.'/orbit-tia/v1/published/apps-e2e.json', '{}');

    $publications = preparation_cache($directory)->publications();

    expect(array_keys($publications))->toBe(['published/apps-cli.json', 'published/apps-e2e.json']);
});

it('refuses an unconfigured Orbit project before sending setup', function (): void {
    $transport = new AppDevFakeSshExecutor;
    app()->instance(SshExecutor::class, $transport);
    $preparer = new RemoteTaskWorkspacePreparer(app(AppDevSshExecutor::class), preparation_cache('/no-such-main-cache'), app(ProjectLifecycleStepStore::class));

    expect(fn () => $preparer->prepare(preparation_instance(configured: false)))
        ->toThrow(ResourceOperationException::class, 'Configure the Orbit Project setup list');
    expect($transport->commands)->toBeEmpty();
});
