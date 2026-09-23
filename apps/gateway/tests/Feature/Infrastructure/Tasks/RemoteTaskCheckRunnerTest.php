<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckProcess;
use App\Domain\Tasks\TaskCheckReading;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskCheckRunner;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\LocalShellSshExecutor;

function check_runner_checkout(string $check): string
{
    $checkout = sys_get_temp_dir().'/orbit-task-check-'.bin2hex(random_bytes(6));
    (new Process(['git', 'init', '--quiet', $checkout]))->mustRun();
    file_put_contents($checkout.'/composer.json', json_encode(['scripts' => ['check' => $check]], JSON_THROW_ON_ERROR));
    file_put_contents($checkout.'/.gitignore', "ignored/\n");
    (new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '--allow-empty', '-m', 'start'], $checkout))->mustRun();
    file_put_contents($checkout.'/uncommitted.php', "<?php\n");

    return $checkout;
}

function check_runner_instance(string $checkout): AppInstance
{
    $app = OrbitApp::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'git@github.com:acme/shop.git', 'default_branch' => 'main']);
    $node = Node::query()->create(['name' => 'check-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '10.44.0.160', 'wireguard_ip' => '10.44.0.160', 'user' => 'orbit']);

    return AppInstance::query()->create(['app_id' => $app->id, 'node_id' => $node->id, 'name' => 'task-8', 'checkout_path' => $checkout, 'branch' => 'task-8', 'status' => 'source_resolved']);
}

function check_runner(SshExecutor $transport): RemoteTaskCheckRunner
{
    return new RemoteTaskCheckRunner(new AppDevSshExecutor(
        $transport,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/home/orbit/.orbit/ssh/id_ed25519';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 AAAA';
            }
        },
        new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/home/orbit/.orbit/ssh/known_hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    ));
}

function check_runner_wait(RemoteTaskCheckRunner $runner, AppInstance $instance, TaskCheckProcess $process): TaskCheckReading
{
    for ($attempt = 0; $attempt < 150; $attempt++) {
        $reading = $runner->read($instance, $process);
        if ($reading->state !== 'running') {
            return $reading;
        }
        usleep(100_000);
    }

    throw new RuntimeException('The check did not finish.');
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/orbit-task-check-*') ?: [] as $directory) {
        File::deleteDirectory($directory);
    }
});

it('runs composer check detached and reports running, then the exit code and output', function (string $check, int $exitCode, string $output): void {
    $checkout = check_runner_checkout($check);
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $status = (new Process(['git', 'status', '--porcelain'], $checkout))->mustRun()->getOutput();

    $process = $runner->start($instance);
    $first = $runner->read($instance, $process);
    $reading = check_runner_wait($runner, $instance, $process);

    expect($process->pid)->toBeGreaterThan(1)
        ->and($process->head)->toBe(trim((new Process(['git', 'rev-parse', 'HEAD'], $checkout))->mustRun()->getOutput()))
        ->and($first->state)->toBe('running')
        ->and($reading->state)->toBe('finished')
        ->and($reading->exitCode)->toBe($exitCode)
        ->and($reading->output)->toContain($output)
        ->and($reading->treeAfter)->toBe($process->tree)
        ->and($reading->changedPaths)->toBe([])
        ->and($reading->finishedAt)->toBeFloat()
        ->and($reading->finishedAt)->toBeLessThanOrEqual(microtime(true))
        ->and((new Process(['git', 'status', '--porcelain'], $checkout))->mustRun()->getOutput())->toBe($status);
})->with([
    'passing' => ['sleep 1 && echo all checks passed', 0, 'all checks passed'],
    'failing' => ['sleep 1 && echo PHPStan found 2 errors && exit 2', 2, 'PHPStan found 2 errors'],
]);

it('reports the paths a check changed in the working tree, but not ignored files', function (): void {
    $checkout = check_runner_checkout('sleep 1 && mkdir -p ignored && echo x > ignored/cache && echo y > written.txt');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);

    $reading = check_runner_wait($runner, $instance, $runner->start($instance));

    expect($reading->exitCode)->toBe(0)
        ->and($reading->changedPaths)->toBe(['written.txt']);
});

it('stops the check process group on cancel, which leaves the check without a result', function (): void {
    $checkout = check_runner_checkout('sleep 30');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $process = $runner->start($instance);

    $runner->cancel($instance, $process);

    expect(check_runner_wait($runner, $instance, $process)->state)->toBe('lost');
});

it('does not take a reused process ID for the check', function (): void {
    $checkout = check_runner_checkout('sleep 30');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $process = $runner->start($instance);

    $reading = $runner->read($instance, new TaskCheckProcess($process->pid, 'Thu Jan  1 00:00:00 1970', $process->head, $process->tree));
    $runner->cancel($instance, $process);

    expect($reading->state)->toBe('lost');
});

it('reports an unreachable workspace and invalid output as check failures', function (CommandResult $result, string $message): void {
    $runner = check_runner(new AppDevFakeSshExecutor([$result]));

    expect(fn () => $runner->read(check_runner_instance('/srv/orbit/apps/shop/task-8'), new TaskCheckProcess(7, 'started', 'head', 'tree')))
        ->toThrow(TaskCheckException::class, $message);
})->with([
    'unreachable' => [new CommandResult(255, '', 'Connection refused', 1, false), 'The task workspace could not be reached for the check.'],
    'invalid output' => [new CommandResult(0, 'not json', '', 1, false), 'The check answered with invalid output.'],
]);

it('runs setup steps in order before composer check, and records the tree after setup', function (): void {
    $checkout = check_runner_checkout('test -f ignored/installed && echo checked');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);

    $reading = check_runner_wait($runner, $instance, $runner->start($instance, [
        ['name' => 'Install', 'command' => 'mkdir -p ignored && touch ignored/installed', 'timeout_seconds' => 60],
        ['name' => 'Notes', 'command' => 'echo notes > notes.txt', 'timeout_seconds' => 60],
    ]));

    expect($reading->exitCode)->toBe(0)
        ->and($reading->failedStep)->toBeNull()
        ->and($reading->output)->toContain("$ mkdir -p ignored && touch ignored/installed\n$ echo notes > notes.txt\n$ composer check\n")
        ->and($reading->output)->toContain('checked')
        ->and($reading->treeBefore)->toBe($reading->treeAfter)
        ->and($reading->changedPaths)->toBe([]);
});

it('stops at the first failing setup step without running composer check', function (): void {
    $checkout = check_runner_checkout('echo checked');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);

    $reading = check_runner_wait($runner, $instance, $runner->start($instance, [
        ['name' => 'Install', 'command' => 'echo cannot install && exit 5', 'timeout_seconds' => 60],
        ['name' => 'Never', 'command' => 'touch never-ran', 'timeout_seconds' => 60],
    ]));

    expect($reading->exitCode)->toBe(5)
        ->and($reading->failedStep)->toBe('Install')
        ->and($reading->output)->toContain('cannot install')
        ->and($reading->output)->not->toContain('composer check')
        ->and(file_exists($checkout.'/never-ran'))->toBeFalse();
});
