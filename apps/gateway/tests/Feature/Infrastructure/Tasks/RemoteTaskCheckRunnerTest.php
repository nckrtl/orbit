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

/**
 * A checkout with one committed project, then uncommitted work on top. Its `vendor/bin/pest` stands in for Pest:
 * it records its arguments and writes a JUnit file with the cases in CASES, so the test sees what the check runs.
 *
 * @return array{0: string, 1: string} the checkout and its start commit
 */
function check_runner_deliverables_checkout(string $cases, string $check = 'echo checks passed'): array
{
    $checkout = check_runner_checkout($check);
    file_put_contents($checkout.'/.gitignore', "ignored/\nvendor/\n");
    File::ensureDirectoryExists($checkout.'/app/tests');
    File::ensureDirectoryExists($checkout.'/app/vendor/bin');
    file_put_contents($checkout.'/app/tests/OldTest.php', "<?php\n");
    file_put_contents($checkout.'/README.md', "# Shop\n");
    file_put_contents($checkout.'/app/vendor/bin/pest', <<<BASH
        #!/usr/bin/env bash
        mkdir -p ../ignored
        printf '%s\\n' "\$@" > ../ignored/pest-arguments
        junit="\${2#--log-junit=}"
        printf '<?xml version="1.0"?><testsuites><testsuite name="t">%s</testsuite></testsuites>' '{$cases}' > "\$junit"
        BASH);
    chmod($checkout.'/app/vendor/bin/pest', 0755);
    (new Process(['git', 'add', '--all'], $checkout))->mustRun();
    (new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'project'], $checkout))->mustRun();
    $start = trim((new Process(['git', 'rev-parse', 'HEAD'], $checkout))->mustRun()->getOutput());
    file_put_contents($checkout.'/app/tests/ExportTest.php', "<?php\n");
    file_put_contents($checkout.'/README.md', "# Shop\n\nExports.\n");
    unlink($checkout.'/app/tests/OldTest.php');

    return [$checkout, $start];
}

describe('deliverable evidence', function (): void {
    it('records the diff, runs each test file by its path, and runs each command after a passing check', function (): void {
        [$checkout, $start] = check_runner_deliverables_checkout('<testcase name="it exports every subtask"/><testcase name="it refuses a draft"><failure>no</failure></testcase><testcase name="it skips"><skipped/></testcase>');
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, [], [
            'start' => $start,
            'tests' => [['id' => 'export-test', 'project' => 'app', 'file' => 'tests/ExportTest.php']],
            'commands' => [
                ['id' => 'lint', 'command' => 'echo linted && exit 3', 'directory' => 'app'],
                ['id' => 'pwd', 'command' => 'basename "$PWD"', 'directory' => '.'],
            ],
        ]));

        expect($reading->exitCode)->toBe(0)
            ->and($reading->changedPaths)->toBe([])
            ->and($reading->deliverables['diff'])->toEqualCanonicalizing([
                ['status' => 'M', 'path' => 'README.md'],
                ['status' => 'A', 'path' => 'app/tests/ExportTest.php'],
                ['status' => 'D', 'path' => 'app/tests/OldTest.php'],
            ])
            ->and($reading->deliverables['tests'])->toBe(['export-test' => ['exit_code' => 0, 'cases' => [
                ['name' => 'it exports every subtask', 'status' => 'passed'],
                ['name' => 'it refuses a draft', 'status' => 'failed'],
                ['name' => 'it skips', 'status' => 'skipped'],
            ]]])
            ->and(file($checkout.'/ignored/pest-arguments', FILE_IGNORE_NEW_LINES))->toBe(['tests/ExportTest.php', '--log-junit='.realpath($checkout).'/.git/orbit/tests/export-test.xml'])
            ->and($reading->deliverables['commands'])->toBe([
                'lint' => ['exit_code' => 3, 'output' => "linted\n"],
                'pwd' => ['exit_code' => 0, 'output' => basename($checkout)."\n"],
            ]);
    });

    it('records no evidence when composer check fails', function (): void {
        [$checkout, $start] = check_runner_deliverables_checkout('', 'exit 1');
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, [], ['start' => $start, 'tests' => [], 'commands' => [['id' => 'lint', 'command' => 'touch ran', 'directory' => '.']]]));

        expect($reading->exitCode)->toBe(1)
            ->and($reading->deliverables)->toBeNull()
            ->and(file_exists($checkout.'/ran'))->toBeFalse();
    });

    it('refuses a command directory outside the workspace', function (): void {
        [$checkout, $start] = check_runner_deliverables_checkout('');
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, [], ['start' => $start, 'tests' => [], 'commands' => [['id' => 'escape', 'command' => 'touch escaped', 'directory' => '../..']]]));

        expect($reading->deliverables['commands'])->toBe(['escape' => ['exit_code' => 127, 'output' => 'The directory is outside the workspace.']]);
    });
});
