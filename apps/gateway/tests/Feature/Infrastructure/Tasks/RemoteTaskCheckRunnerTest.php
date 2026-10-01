<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckProcess;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskDeliverableEvidence;
use App\Domain\Tasks\TaskDeliverableVerifier;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskCheckRunner;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\LocalShellSshExecutor;

function check_runner_checkout(string $check): string
{
    $checkout = test()->directory.'/'.bin2hex(random_bytes(6));
    (new Process(['git', 'init', '--quiet', $checkout]))->mustRun();
    file_put_contents($checkout.'/composer.json', json_encode(['scripts' => ['check' => $check]], JSON_THROW_ON_ERROR));
    file_put_contents($checkout.'/.gitignore', "ignored/\n");
    (new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '--allow-empty', '-m', 'start'], $checkout))->mustRun();
    file_put_contents($checkout.'/uncommitted.php', "<?php\n");

    return $checkout;
}

function check_runner_instance(string $checkout): Instance
{
    $project = Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'git@github.com:acme/shop.git', 'default_branch' => 'main']);
    $node = Node::query()->create(['name' => 'check-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '10.44.0.160', 'wireguard_ip' => '10.44.0.160', 'user' => 'orbit']);

    return Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-8', 'checkout_path' => $checkout, 'branch' => 'task-8', 'status' => 'source_resolved']);
}

function check_runner(SshExecutor $transport): RemoteTaskCheckRunner
{
    return new RemoteTaskCheckRunner(new DevelopmentSshExecutor(
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

function check_runner_wait(RemoteTaskCheckRunner $runner, Instance $instance, TaskCheckProcess $process): TaskCheckReading
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

/**
 * Run the real `check status` with a stubbed process lookup. The lookup answers whether the check is alive,
 * and first runs `$meanwhile`, so a test can place a result write at the moment status looks up the process.
 *
 * @return array<string, mixed>
 */
function check_runner_status(bool $alive, string $meanwhile = ''): array
{
    $script = test()->directory.'/script/check';
    File::ensureDirectoryExists(dirname($script));
    File::copy(resource_path('tasks/check'), $script);
    $status = new Process(['python3', '-c', <<<'PYTHON'
        import importlib.machinery, importlib.util, sys
        loader = importlib.machinery.SourceFileLoader('check', sys.argv[1])
        check = importlib.util.module_from_spec(importlib.util.spec_from_loader('check', loader))
        loader.exec_module(check)
        def process_started(pid):
            exec(sys.argv[3], {'check': check})
            return 'Wed Sep 23 12:00:00 2026' if sys.argv[2] == 'alive' else None
        check.process_started = process_started
        check.status(7, 'Wed Sep 23 12:00:00 2026')
        PYTHON, $script, $alive ? 'alive' : 'gone', $meanwhile]);

    return json_decode($status->mustRun()->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    // Each test owns one directory, so a concurrent run on the same machine keeps its checkouts.
    $this->directory = sys_get_temp_dir().'/orbit-task-check-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->directory);
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

it('runs composer check detached and reports running, then the exit code and output', function (string $check, int $exitCode, string $output): void {
    $checkout = check_runner_checkout($check);
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $status = (new Process(['git', 'status', '--porcelain'], $checkout))->mustRun()->getOutput();

    $process = $runner->start($instance, 'composer check');
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

    $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'composer check'));

    expect($reading->exitCode)->toBe(0)
        ->and($reading->changedPaths)->toBe(['written.txt']);
});

it('stops the check process group on cancel, which leaves the check without a result', function (): void {
    $checkout = check_runner_checkout('sleep 30');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $process = $runner->start($instance, 'composer check');

    $runner->cancel($instance, $process);

    expect(check_runner_wait($runner, $instance, $process)->state)->toBe('lost');
});

it('does not take a reused process ID for the check', function (): void {
    $checkout = check_runner_checkout('sleep 30');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $process = $runner->start($instance, 'composer check');

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

it('reads a finished status larger than the 64 KiB process default', function (): void {
    $cases = array_map(static fn (int $index): array => ['name' => 'case '.$index.' '.str_repeat('x', 150), 'status' => 'passed'], range(1, 400));
    $status = json_encode(['state' => 'finished', 'output' => str_repeat('o', 16_384), 'result' => [
        'exit_code' => 0,
        'head_after' => str_repeat('a', 40),
        'tree_after' => str_repeat('b', 40),
        'changed_paths' => [],
        'deliverables' => ['commands' => ['repro' => ['exit_code' => 0, 'output' => implode("\n", array_column($cases, 'name'))]]],
    ]], JSON_THROW_ON_ERROR);
    expect(strlen($status))->toBeGreaterThan(65_536);
    $transport = new class($status) implements SshExecutor
    {
        public function __construct(private string $status) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            return new CommandResult(0, substr($this->status, 0, $command->maxOutputBytes ?? 65_536), '', 1, false);
        }
    };

    $reading = check_runner($transport)->read(check_runner_instance('/srv/orbit/apps/shop/task-8'), new TaskCheckProcess(7, 'started', 'head', 'tree'));

    expect($reading->state)->toBe('finished')
        ->and($reading->exitCode)->toBe(0);
});

it('reads the same working tree as the check without touching the index', function (): void {
    $checkout = check_runner_checkout('echo ok');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $status = (new Process(['git', 'status', '--porcelain'], $checkout))->mustRun()->getOutput();

    $process = $runner->start($instance, 'echo ok');
    $runner->cancel($instance, $process);
    $snapshot = $runner->snapshot($instance);

    expect($snapshot->head)->toBe($process->head)
        ->and($snapshot->tree)->toBe($process->tree)
        ->and($snapshot->parent)->toBeNull()
        ->and($snapshot->commitTree)->toBe(trim((new Process(['git', 'rev-parse', 'HEAD^{tree}'], $checkout))->mustRun()->getOutput()))
        ->and((new Process(['git', 'status', '--porcelain'], $checkout))->mustRun()->getOutput())->toBe($status);

    file_put_contents($checkout.'/extra.txt', "extra\n");
    $written = (new Process(['git', 'status', '--porcelain'], $checkout))->mustRun()->getOutput();
    $changed = $runner->snapshot($instance);
    File::ensureDirectoryExists($checkout.'/ignored');
    file_put_contents($checkout.'/ignored/cache', "cache\n");
    $ignored = $runner->snapshot($instance);

    expect($changed->head)->toBe($snapshot->head)
        ->and($changed->tree)->not->toBe($snapshot->tree)
        ->and($ignored->head)->toBe($changed->head)
        ->and($ignored->tree)->toBe($changed->tree)
        ->and((new Process(['git', 'status', '--porcelain'], $checkout))->mustRun()->getOutput())->toBe($written);
});

it('reports an unreachable workspace when the review tree cannot be read', function (CommandResult $result, string $message): void {
    $runner = check_runner(new AppDevFakeSshExecutor([$result]));

    expect(fn () => $runner->snapshot(check_runner_instance('/srv/orbit/apps/shop/task-8')))
        ->toThrow(TaskCheckException::class, $message);
})->with([
    'unreachable' => [new CommandResult(255, '', 'Connection refused', 1, false), 'The task workspace could not be reached for the workspace tree.'],
    'invalid output' => [new CommandResult(0, 'not json', '', 1, false), 'The workspace tree could not be read.'],
]);

it('runs setup steps in order before composer check, and records the tree after setup', function (): void {
    $checkout = check_runner_checkout('test -f ignored/installed && echo checked');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);

    $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'composer check', [
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

    $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'composer check', [
        ['name' => 'Install', 'command' => 'echo cannot install && exit 5', 'timeout_seconds' => 60],
        ['name' => 'Never', 'command' => 'touch never-ran', 'timeout_seconds' => 60],
    ]));

    expect($reading->exitCode)->toBe(5)
        ->and($reading->failedStep)->toBe('Install')
        ->and($reading->output)->toContain('cannot install')
        ->and($reading->output)->not->toContain('composer check')
        ->and(file_exists($checkout.'/never-ran'))->toBeFalse();
});

it('reports a check that writes its result and exits while status looks it up as finished, not lost', function (): void {
    $status = check_runner_status(alive: false, meanwhile: 'with open(check.RESULT, "w") as result: result.write(\'{"exit_code": 5}\')');

    expect($status['state'])->toBe('finished')
        ->and($status['result']['exit_code'])->toBe(5);
});

it('reports a live check without a result as running, and a gone check without a result as lost', function (): void {
    expect(check_runner_status(alive: true)['state'])->toBe('running')
        ->and(check_runner_status(alive: false)['state'])->toBe('lost');
});

/**
 * A checkout with one committed project, then uncommitted work on top.
 *
 * @return array{0: string, 1: string} the checkout and its start commit
 */
function check_runner_deliverables_checkout(string $check = 'echo checks passed'): array
{
    $checkout = check_runner_checkout($check);
    file_put_contents($checkout.'/.gitignore', "ignored/\nvendor/\n");
    File::ensureDirectoryExists($checkout.'/app/tests');
    file_put_contents($checkout.'/app/tests/OldTest.php', "<?php\n");
    file_put_contents($checkout.'/README.md', "# Shop\n");
    (new Process(['git', 'add', '--all'], $checkout))->mustRun();
    (new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'project'], $checkout))->mustRun();
    $start = trim((new Process(['git', 'rev-parse', 'HEAD'], $checkout))->mustRun()->getOutput());
    file_put_contents($checkout.'/app/tests/ExportTest.php', "<?php\n");
    file_put_contents($checkout.'/README.md', "# Shop\n\nExports.\n");
    unlink($checkout.'/app/tests/OldTest.php');

    return [$checkout, $start];
}

describe('deliverable evidence', function (): void {
    it('fails on base command when the base tree does not satisfy the overlaid command paths', function (): void {
        $checkout = check_runner_checkout('echo checks passed');
        File::ensureDirectoryExists($checkout.'/app/tests');
        file_put_contents($checkout.'/app/state', "old\n");
        file_put_contents($checkout.'/app/tests/check.sh', "#!/usr/bin/env bash\necho base-script\ngrep -q old state\n");
        chmod($checkout.'/app/tests/check.sh', 0755);
        (new Process(['git', 'add', '--all'], $checkout))->mustRun();
        (new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'base'], $checkout))->mustRun();
        $start = trim((new Process(['git', 'rev-parse', 'HEAD'], $checkout))->mustRun()->getOutput());
        file_put_contents($checkout.'/app/state', "new\n");
        file_put_contents($checkout.'/app/tests/check.sh', "#!/usr/bin/env bash\necho overlay-script\ngrep -q new state\n");
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'echo checks passed', [], [
            'start' => $start,
            'commands' => [['id' => 'base-repro', 'command' => 'bash tests/check.sh', 'directory' => 'app', 'fails_on_base' => true, 'paths' => ['app/tests/check.sh']]],
        ]));

        expect($reading->deliverables['commands']['base-repro'])->toBe([
            'exit_code' => 0,
            'output' => "overlay-script\n",
            'base_started' => true,
            'base_exit_code' => 1,
            'base_output' => "overlay-script\n",
        ]);
    });

    it('treats a missing command in the base tree as unable to reproduce', function (): void {
        $checkout = check_runner_checkout('echo checks passed');
        file_put_contents($checkout.'/status', "missing\n");
        File::ensureDirectoryExists($checkout.'/tests');
        file_put_contents($checkout.'/tests/check.sh', "#!/usr/bin/env bash\ncd \"$(dirname \"$0\")/..\"\nif grep -q missing status; then command-that-does-not-exist; else exit 0; fi\n");
        (new Process(['git', 'add', '--all'], $checkout))->mustRun();
        (new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'base'], $checkout))->mustRun();
        $start = trim((new Process(['git', 'rev-parse', 'HEAD'], $checkout))->mustRun()->getOutput());
        file_put_contents($checkout.'/status', "available\n");
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'echo checks passed', [], [
            'start' => $start,
            'commands' => [['id' => 'missing-base-command', 'command' => 'bash tests/check.sh', 'directory' => '.', 'fails_on_base' => true, 'paths' => ['tests/check.sh']]],
        ]));

        expect($reading->deliverables['commands']['missing-base-command'])->toMatchArray([
            'exit_code' => 0,
            'base_started' => true,
            'base_exit_code' => 127,
        ]);
        $deliverable = TaskDeliverable::fromArray([
            'id' => 'missing-base-command', 'type' => 'command', 'description' => 'Run the check',
            'command' => 'bash tests/check.sh', 'directory' => '.', 'fails_on_base' => true, 'paths' => ['tests/check.sh'],
        ]);
        $evidence = TaskDeliverableEvidence::fromArray([
            'diff' => [],
            'commands' => ['missing-base-command' => $reading->deliverables['commands']['missing-base-command']],
        ]);
        expect(TaskDeliverableVerifier::failures([$deliverable], $evidence))->not->toBe([]);
    });

    it('does not substitute HEAD when the resolved start commit is unavailable', function (): void {
        [$checkout] = check_runner_deliverables_checkout();
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'echo checks passed', [], [
            'start' => str_repeat('f', 40),
            'commands' => [['id' => 'base-repro', 'command' => 'true', 'directory' => '.', 'fails_on_base' => true, 'paths' => ['README.md']]],
        ]));

        expect($reading->deliverables['commands']['base-repro'])->toBe([
            'exit_code' => 0,
            'output' => '',
            'base_started' => false,
            'base_exit_code' => 127,
            'base_output' => 'Could not extract the start commit.',
        ]);
    });

    it('kills a timed-out base process group and preserves its partial output', function (): void {
        $harness = $this->directory.'/base-timeout.py';
        file_put_contents($harness, <<<'PYTHON'
import importlib.machinery
import importlib.util
import json
import os
import sys
import time

loader = importlib.machinery.SourceFileLoader('task_check', sys.argv[1])
module = importlib.util.module_from_spec(importlib.util.spec_from_loader('task_check', loader))
loader.exec_module(module)
workdir = sys.argv[2]
group_file = os.path.join(workdir, 'process-group')
log_path = os.path.join(workdir, 'check.log')
command = f'echo $$ > {group_file}; trap "exit 0" TERM; (trap "" TERM; echo child-ready; exec sleep 30) & wait'
with open(log_path, 'wb') as log:
    code, output, timed_out = module.run_base(command, workdir, log, 0.5)
process_group = int(open(group_file).read())
deadline = time.monotonic() + 2
while module.process_group_exists(process_group) and time.monotonic() < deadline:
    time.sleep(0.05)
print(json.dumps({
    'code': code,
    'timed_out': timed_out,
    'output': output,
    'log': open(log_path, 'rb').read().decode(),
    'group_exists': module.process_group_exists(process_group),
}))
PYTHON);
        $probe = new Process(['python3', $harness, resource_path('tasks/check'), $this->directory], base_path());
        $result = json_decode($probe->mustRun()->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($result['code'])->toBeNull()
            ->and($result['timed_out'])->toBeTrue()
            ->and($result['output'])->toContain('child-ready')
            ->and($result['log'])->toContain('child-ready')
            ->and($result['log'])->toContain('Base command timed out after 0.5 seconds.')
            ->and($result['group_exists'])->toBeFalse();
    });

    it('records the diff and runs each command after a passing check', function (): void {
        [$checkout, $start] = check_runner_deliverables_checkout();
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'composer check', [], [
            'start' => $start,
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
            ->and($reading->deliverables['commands'])->toBe([
                'lint' => ['exit_code' => 3, 'output' => "linted\n"],
                'pwd' => ['exit_code' => 0, 'output' => basename($checkout)."\n"],
            ]);
    });

    it('records no evidence when composer check fails', function (): void {
        [$checkout, $start] = check_runner_deliverables_checkout('exit 1');
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'composer check', [], ['start' => $start, 'commands' => [['id' => 'lint', 'command' => 'touch ran', 'directory' => '.']]]));

        expect($reading->exitCode)->toBe(1)
            ->and($reading->deliverables)->toBeNull()
            ->and(file_exists($checkout.'/ran'))->toBeFalse();
    });

    it('refuses a command directory outside the workspace', function (): void {
        [$checkout, $start] = check_runner_deliverables_checkout();
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'composer check', [], ['start' => $start, 'commands' => [['id' => 'escape', 'command' => 'touch escaped', 'directory' => '../..']]]));

        expect($reading->failedStep)->toBe('invalid_deliverable')
            ->and($reading->deliverables)->toBeNull()
            ->and($reading->output)->toContain('Deliverable escape names a directory outside the checkout.');
    });

    it('rejects a missing base command overlay path before the project check runs', function (): void {
        [$checkout, $start] = check_runner_deliverables_checkout();
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'touch project-check-ran', [], [
            'start' => $start,
            'commands' => [['id' => 'repro', 'command' => 'true', 'directory' => '.', 'fails_on_base' => true, 'paths' => ['missing.sh']]],
        ]));

        expect($reading->state)->toBe('finished')
            ->and($reading->exitCode)->not->toBe(0)
            ->and($reading->failedStep)->toBe('invalid_deliverable')
            ->and($reading->changedPaths)->toBe([])
            ->and($reading->deliverables)->toBeNull()
            ->and($reading->output)->toContain('Deliverable repro names invalid overlay path missing.sh.')
            ->and(file_exists($checkout.'/project-check-ran'))->toBeFalse();
    });
});

it('writes a failed result when the check script hits an unexpected error', function (): void {
    $checkout = check_runner_checkout('echo ok');
    $script = test()->directory.'/script/check';
    File::ensureDirectoryExists(dirname($script));
    File::copy(resource_path('tasks/check'), $script);
    $deliverables = test()->directory.'/not-a-file';
    File::ensureDirectoryExists($deliverables);
    $ran = new Process(['python3', '-c', <<<'PYTHON'
        import importlib.machinery, importlib.util, sys
        loader = importlib.machinery.SourceFileLoader('check', sys.argv[1])
        check = importlib.util.module_from_spec(importlib.util.spec_from_loader('check', loader))
        loader.exec_module(check)
        checkout, deliverables = sys.argv[2], sys.argv[3]
        check.run(checkout, check.git(checkout, 'rev-parse', 'HEAD'), check.working_tree(checkout), '-', deliverables, 'touch project-check-ran')
        PYTHON, $script, $checkout, $deliverables]);

    $ran->mustRun();
    $result = json_decode((string) file_get_contents(dirname($script).'/check.json'), true, flags: JSON_THROW_ON_ERROR);
    $log = (string) file_get_contents(dirname($script).'/check.log');

    expect($result['exit_code'])->not->toBe(0)
        ->and($result['failed_step'])->toBe('check_error')
        ->and($result['deliverables'])->toBeNull()
        ->and($log)->toContain('Traceback (most recent call last):')
        ->and(trim($log))->toContain('IsADirectoryError')
        ->and(file_exists($checkout.'/project-check-ran'))->toBeFalse();
});

describe('fails_on_base command deliverables', function (): void {
    it('fails verification when the command also passes on the start commit', function (): void {
        $deliverable = TaskDeliverable::fromArray([
            'id' => 'base-repro', 'type' => 'command', 'description' => 'The behavior fails on base',
            'command' => 'bash tests/check.sh', 'directory' => 'app', 'fails_on_base' => true, 'paths' => ['app/tests/check.sh'],
        ]);
        $evidence = TaskDeliverableEvidence::fromArray([
            'diff' => [],
            'commands' => ['base-repro' => ['base_started' => true, 'base_exit_code' => 0, 'base_output' => '', 'exit_code' => 0, 'output' => '']],
        ]);

        expect(TaskDeliverableVerifier::failures([$deliverable], $evidence))->toBe([
            'base-repro (command): `bash tests/check.sh` in app also exited 0 on the start commit, so it does not reproduce the failure.',
        ]);
    });
});

it('cleans stale base archive directories without registering worktrees or removing unrelated files', function (): void {
    $checkout = check_runner_checkout('echo ok');
    $gitDir = trim((new Process(['git', 'rev-parse', '--absolute-git-dir'], $checkout))->mustRun()->getOutput());
    File::ensureDirectoryExists($gitDir.'/orbit/bases/orbit-base-leftover');
    file_put_contents($gitDir.'/orbit/bases/orbit-base-leftover/marker', "x\n");
    File::ensureDirectoryExists($gitDir.'/orbit/bases/notes');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);

    $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'echo ok'));

    expect($reading->exitCode)->toBe(0)
        ->and(is_dir($gitDir.'/orbit/bases/orbit-base-leftover'))->toBeFalse()
        ->and(is_dir($gitDir.'/orbit/bases/notes'))->toBeTrue()
        ->and(substr_count((string) (new Process(['git', 'worktree', 'list'], $checkout))->mustRun()->getOutput(), "\n"))->toBe(1);
});

/**
 * @return array<string, mixed>
 */
function omitted_command_check_result(string $resultPath): array
{
    for ($attempt = 0; $attempt < 100; $attempt++) {
        if (is_file($resultPath)) {
            $decoded = json_decode((string) file_get_contents($resultPath), true);
            if (is_array($decoded) && array_key_exists('finished_at', $decoded)) {
                return $decoded;
            }
        }
        usleep(50_000);
    }

    throw new RuntimeException('The check did not finish.');
}

it('runs no project command when start and run omit the command file, and still checks the tree and deliverables', function (): void {
    $checkout = check_runner_checkout('touch composer-check-ran');
    $script = test()->directory.'/helper/check';
    File::ensureDirectoryExists(dirname($script));
    File::copy(resource_path('tasks/check'), $script);
    $deliverables = test()->directory.'/deliverables.json';
    file_put_contents($deliverables, json_encode([
        'start' => null,
        'commands' => [['id' => 'hello', 'command' => 'touch deliverable-ran', 'directory' => '.']],
    ], JSON_THROW_ON_ERROR));
    $resultPath = dirname($script).'/check.json';
    $logPath = dirname($script).'/check.log';
    $snapshot = json_decode((new Process(['python3', $script, 'snapshot', $checkout]))->mustRun()->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($snapshot)->toBeArray();

    (new Process(['python3', $script, 'run', $checkout, $snapshot['head'], $snapshot['tree'], '-', $deliverables]))->mustRun();
    $ran = json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR);
    $ranLog = (string) file_get_contents($logPath);

    expect($ran['exit_code'])->toBe(0)
        ->and($ran['failed_step'])->toBeNull()
        ->and($ran['head_before'])->toBe($snapshot['head'])
        ->and($ran['tree_before'])->toBe($snapshot['tree'])
        ->and($ran['tree_after'])->not->toBe($snapshot['tree'])
        ->and($ran['changed_paths'])->toBe(['deliverable-ran'])
        ->and($ran['deliverables']['commands']['hello']['exit_code'])->toBe(0)
        ->and($ranLog)->not->toContain('composer check')
        ->and(is_file($checkout.'/composer-check-ran'))->toBeFalse()
        ->and(is_file($checkout.'/deliverable-ran'))->toBeTrue();

    File::delete([$resultPath, $logPath, $checkout.'/deliverable-ran']);
    (new Process(['python3', $script, 'start', $checkout, '-', $deliverables]))->mustRun();
    $started = omitted_command_check_result($resultPath);
    $startedLog = (string) file_get_contents($logPath);

    expect($started['exit_code'])->toBe(0)
        ->and($started['failed_step'])->toBeNull()
        ->and($started['head_before'])->not->toBe('')
        ->and($started['tree_before'])->not->toBe('')
        ->and($started['tree_after'])->not->toBe($started['tree_before'])
        ->and($started['changed_paths'])->toBe(['deliverable-ran'])
        ->and($started['deliverables']['commands']['hello']['exit_code'])->toBe(0)
        ->and($startedLog)->not->toContain('composer check')
        ->and(is_file($checkout.'/composer-check-ran'))->toBeFalse()
        ->and(is_file($checkout.'/deliverable-ran'))->toBeTrue();
});
