<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Tasks\RemoteTaskWorkspacePreparer;
use App\Infrastructure\Tasks\TaskMainCache;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Support\Facades\File;
use Tests\Support\AppDevFakeSshExecutor;

function preparation_instance(): AppInstance
{
    return new AppInstance(['checkout_path' => '/srv/orbit/task with spaces', 'branch' => 'task-1', 'starting_commit' => str_repeat('c', 40)])
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
    $response = ['prepared' => true, 'checkout' => $instance->checkout_path, 'commit' => $instance->starting_commit];
    $transport = new AppDevFakeSshExecutor([new CommandResult(0, json_encode($response), '', 1, false)]);
    app()->instance(SshExecutor::class, $transport);

    $preparer = new RemoteTaskWorkspacePreparer(app(AppDevSshExecutor::class), preparation_cache($directory));
    $preparer->prepare($instance);

    $command = $transport->commands[0];
    expect($command->arguments)->toBe(['python3', '-', $instance->checkout_path, 'task-1', str_repeat('c', 40)]);
    expect($command->timeout)->toBe(880.0);
    $encoded = explode("'", explode("\n", $command->input)[0])[1];
    expect(json_decode(gzdecode(base64_decode($encoded)), true))->toBe(['published/apps-cli.json' => '{"main":"published"}']);
    expect($transport->connections[0]->knownHostsFile)->not->toBe('');
});

it('refuses failed truncated or mismatched setup without exposing remote output', function (array $response, bool $truncated, int $exit): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult($exit, json_encode($response), 'private-output-sentinel', 1, $truncated)]);
    app()->instance(SshExecutor::class, $transport);
    $preparer = new RemoteTaskWorkspacePreparer(app(AppDevSshExecutor::class), preparation_cache('/no-such-main-cache'));

    try {
        $preparer->prepare(preparation_instance());
        test()->fail('Failed setup was accepted.');
    } catch (ResourceOperationException $exception) {
        expect($exception->getMessage())->toContain('bootstrap failed')->not->toContain('private-output-sentinel');
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
