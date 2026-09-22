<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskSessionClassificationException;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Tasks\RemoteTaskCheckRunner;
use App\Models\AppInstance;
use App\Models\Node;
use Tests\Support\AppDevFakeSshExecutor;

function task_check_instance(): AppInstance
{
    return new AppInstance(['checkout_path' => '/srv/orbit/task-check'])->setRelation('node',
        new Node(['wireguard_ip' => '10.44.0.130', 'user' => 'orbit']));
}

function task_check_ssh(AppDevFakeSshExecutor $transport): AppDevSshExecutor
{
    app()->instance(SshExecutor::class, $transport);

    return app(AppDevSshExecutor::class);
}

it('sends the owned runner through pinned SSH with a fixed command and timeout', function (): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult(0, json_encode(['digest' => str_repeat('a', 64)]), '', 1, false)]);
    $instance = task_check_instance();

    $digest = new RemoteTaskCheckRunner(task_check_ssh($transport))->fingerprint($instance);

    expect($digest)->toBe(str_repeat('a', 64));
    expect($transport->commands[0]->arguments)->toBe(['python3', '-', $instance->checkout_path, 'identity', '']);
    expect($transport->commands[0]->input)->toBe(file_get_contents(resource_path('tasks/check.py')));
    expect($transport->commands[0]->timeout)->toBe(20.0);
    expect($transport->connections[0]->host)->toBe('10.44.0.130');
    expect($transport->connections[0]->knownHostsFile)->not->toBe('');
});

it('preserves a failed check with bounded execution and structured references', function (): void {
    $report = ['identity' => ['digest' => str_repeat('a', 64)], 'profile' => 'orbit-composer-v1',
        'passed' => false, 'checks' => [['project' => 'apps/cli', 'command' => ['composer', 'check'], 'exit_code' => 1]],
        'evidence' => [], 'seconds' => 1.0];
    $transport = new AppDevFakeSshExecutor([new CommandResult(0, json_encode($report), '', 1, false)]);
    $references = [['criterion_id' => 'proof', 'project' => 'apps/cli', 'path' => 'tests/Feature/ExampleTest.php', 'test' => 'it works']];

    $result = new RemoteTaskCheckRunner(task_check_ssh($transport))->run(task_check_instance(), $references);

    expect($result->passed)->toBeFalse();
    expect($result->checks[0]['exit_code'])->toBe(1);
    expect($transport->commands[0]->timeout)->toBe(880.0);
    expect(json_decode(base64_decode($transport->commands[0]->arguments[4]), true))->toBe($references);
});

it('refuses incomplete or invalid runner output', function (string $output, bool $truncated, int $exit): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult($exit, $output, 'private transport detail', 1, $truncated)]);

    expect(fn () => new RemoteTaskCheckRunner(task_check_ssh($transport))->run(task_check_instance(), []))
        ->toThrow(TaskSessionClassificationException::class);
})->with([
    'truncated' => ['{}', true, 0],
    'malformed' => ['not json', false, 0],
    'transport failure' => ['private transport detail', false, 1],
    'wrong profile' => [json_encode(['identity' => ['digest' => str_repeat('a', 64)], 'profile' => 'another', 'passed' => true, 'checks' => [], 'evidence' => [], 'seconds' => 1]), false, 0],
    'missing required commands' => [json_encode(['identity' => ['digest' => str_repeat('a', 64)], 'profile' => 'orbit-composer-v1', 'passed' => true, 'checks' => [], 'evidence' => [], 'seconds' => 1]), false, 0],
    'invalid fingerprint' => [json_encode(['identity' => ['digest' => 'fake'], 'profile' => 'orbit-composer-v1', 'passed' => false, 'checks' => [], 'evidence' => [], 'seconds' => 1]), false, 0],
]);
