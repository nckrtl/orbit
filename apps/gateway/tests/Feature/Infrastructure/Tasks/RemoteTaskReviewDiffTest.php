<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskReviewDiff;
use App\Domain\Tasks\TaskReviewDiffException;
use App\Domain\Tasks\TaskReviewPacketBuilder;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceMcp;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskReviewDiff;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Symfony\Component\Process\Process;
use Tests\Support\AcceptingTaskWorkspaceMcp;
use Tests\Support\FakeAgentDriver;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\TestOrbitHome;

beforeEach(function (): void {
    app()->instance(TaskWorkspaceMcp::class, new AcceptingTaskWorkspaceMcp);
});

afterEach(function (): void {
    TestOrbitHome::clearScratch();
});

it('reads tracked and untracked review diff without updating the index', function (): void {
    $checkout = TestOrbitHome::scratch('orbit-review-diff');
    (new Process(['git', 'init', '--quiet', $checkout]))->mustRun();
    (new Process(['git', '-C', $checkout, 'config', 'user.email', 'test@example.com']))->mustRun();
    (new Process(['git', '-C', $checkout, 'config', 'user.name', 'Test']))->mustRun();
    file_put_contents($checkout.'/tracked.php', "<?php\nreturn 1;\n");
    (new Process(['git', '-C', $checkout, 'add', 'tracked.php']))->mustRun();
    (new Process(['git', '-C', $checkout, 'commit', '--quiet', '-m', 'start']))->mustRun();
    $start = trim((new Process(['git', '-C', $checkout, 'rev-parse', 'HEAD']))->mustRun()->getOutput());
    file_put_contents($checkout.'/tracked.php', "<?php\nreturn 2;\n");
    file_put_contents($checkout.'/untracked.php', "<?php\nreturn 'new';\n");
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'git@example.test:orbit.git', 'default_branch' => 'main']);
    $node = Node::query()->create(['name' => 'review-diff-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '10.44.0.144', 'wireguard_ip' => '10.44.0.144', 'user' => 'orbit']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-14', 'checkout_path' => $checkout, 'branch' => 'task-14', 'status' => 'source_resolved']);
    $reader = new RemoteTaskReviewDiff(new DevelopmentSshExecutor(
        new LocalShellSshExecutor,
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

    $diff = $reader->read($instance, $start);
    $cached = new Process(['git', '-C', $checkout, 'diff', '--cached', '--name-only']);
    $cached->run();

    expect($cached->getExitCode())->toBe(0)
        ->and(trim($cached->getOutput()))->toBe('')
        ->and($diff['files'])->toHaveCount(2)
        ->and($diff['files_complete'])->toBeTrue()
        ->and($diff['diff_available'])->toBeTrue()
        ->and($diff['summary'])->toBe(['files' => 2, 'insertions' => 3, 'deletions' => 1])
        ->and(array_column($diff['files'], 'path'))->toContain('tracked.php', 'untracked.php')
        ->and($diff['diff'])->toContain('return 2;')
        ->and($diff['diff'])->toContain("return 'new';");
});

it('refuses a missing checkout, a missing base, and output that is not a diff', function (string $case): void {
    $checkout = review_diff_checkout();
    $start = trim((new Process(['git', '-C', $checkout, 'rev-parse', 'HEAD']))->mustRun()->getOutput());
    $instance = review_diff_instance($checkout);
    $reader = match ($case) {
        'checkout' => review_diff_reader(new LocalShellSshExecutor),
        'base' => review_diff_reader(new LocalShellSshExecutor),
        'garbage' => review_diff_reader(review_diff_result(new CommandResult(0, "not a diff\n", '', 1, false))),
        'truncated' => review_diff_reader(review_diff_result(new CommandResult(0, "1\t0\tlate.php\n", '', 1, true))),
        default => throw new InvalidArgumentException($case),
    };
    if ($case === 'checkout') {
        $instance->update(['checkout_path' => '']);
    }
    $commit = match ($case) {
        'base' => str_repeat('c', 40),
        'checkout' => 'short',
        default => $start,
    };

    expect(fn () => $reader->read($instance->fresh() ?? $instance, $commit))->toThrow(TaskReviewDiffException::class);
})->with(['checkout', 'base', 'garbage', 'truncated']);

it('keeps the full counts when the captured output is only the tail', function (): void {
    $checkout = review_diff_checkout();
    $start = trim((new Process(['git', '-C', $checkout, 'rev-parse', 'HEAD']))->mustRun()->getOutput());
    file_put_contents($checkout.'/tracked.php', "<?php\n".str_repeat("return 2;\n", 40));
    $reader = review_diff_reader(new class implements SshExecutor
    {
        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            return (new NativeProcessRunner)->run(new ProcessInvocation(
                arguments: $command->arguments,
                input: $command->input,
                timeout: 30,
                maxOutputBytes: 180,
            ));
        }
    });

    $diff = $reader->read(review_diff_instance($checkout), $start);

    expect($diff['files'])->toBe([])
        ->and($diff['diff'])->toBe('')
        ->and($diff['files_complete'])->toBeFalse()
        ->and($diff['diff_available'])->toBeFalse()
        ->and($diff['summary']['files'])->toBe(1)
        ->and($diff['summary']['insertions'])->toBeGreaterThan(1)
        ->and($diff['summary']['deletions'])->toBeGreaterThan(0);
});

it('does not send a review when git cannot produce the stat, the body, or the file list', function (string $failure): void {
    $checkout = review_diff_checkout();
    $start = trim((new Process(['git', '-C', $checkout, 'rev-parse', 'HEAD']))->mustRun()->getOutput());
    file_put_contents($checkout.'/tracked.php', "<?php\nreturn 2;\n");
    $restore = [];
    $path = getenv('PATH') ?: '';
    if ($failure === 'stat') {
        \chmod($checkout.'/tracked.php', 0000);
        $restore[] = $checkout.'/tracked.php';
    } elseif ($failure === 'body') {
        $helper = TestOrbitHome::scratch('fail-helper.sh');
        file_put_contents($helper, "#!/bin/sh\nexit 3\n");
        \chmod($helper, 0755);
        (new Process(['git', '-C', $checkout, 'config', 'diff.external', $helper]))->mustRun();
    } elseif ($failure === 'untracked') {
        file_put_contents($checkout.'/secret.php', "hidden\n");
        \chmod($checkout.'/secret.php', 0000);
        $restore[] = $checkout.'/secret.php';
    } else {
        $bin = TestOrbitHome::scratch('git-bin');
        mkdir($bin);
        $git = trim((string) shell_exec('command -v git'));
        file_put_contents($bin.'/git', "#!/bin/sh\nfor argument in \"\$@\"; do if [ \"\$argument\" = ls-files ]; then echo ls-files-failed >&2; exit 1; fi; done\nexec ".escapeshellarg($git)." \"\$@\"\n");
        \chmod($bin.'/git', 0755);
        putenv('PATH='.$bin.':'.$path);
    }
    $instance = review_diff_instance($checkout);
    $group = Task::topLevel()->create([
        'project_id' => $instance->project_id,
        'title' => 'Unread diff',
        'brief' => 'Git cannot produce the diff.',
        'status' => TaskGroupStatus::Running,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Review',
        'brief' => 'Review it.',
        'status' => TaskStatus::Running,
        'subtask_start_commit' => $start,
    ]);
    $driver = new FakeAgentDriver('pi');
    app()->instance(TaskReviewDiff::class, review_diff_reader(new LocalShellSshExecutor));
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app()->forgetInstance(AgentSpawner::class);
    app()->forgetInstance(TaskReviewPacketBuilder::class);

    try {
        app(TaskScheduler::class)->settleImplementer($task);
    } finally {
        foreach ($restore as $pathToRestore) {
            \chmod($pathToRestore, 0644);
        }
        putenv('PATH='.$path);
    }

    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($task->fresh()?->review_notified_attempt)->toBeNull()
        ->and($task->fresh()?->communication_failures)->toBe(1)
        ->and($driver->calls)->toBe([]);
})->with(['stat', 'body', 'untracked', 'ls-files']);

function review_diff_checkout(): string
{
    $checkout = TestOrbitHome::scratch('orbit-review-diff-'.bin2hex(random_bytes(4)));
    (new Process(['git', 'init', '--quiet', $checkout]))->mustRun();
    (new Process(['git', '-C', $checkout, 'config', 'user.email', 'test@example.com']))->mustRun();
    (new Process(['git', '-C', $checkout, 'config', 'user.name', 'Test']))->mustRun();
    file_put_contents($checkout.'/tracked.php', "<?php\nreturn 1;\n");
    (new Process(['git', '-C', $checkout, 'add', 'tracked.php']))->mustRun();
    (new Process(['git', '-C', $checkout, 'commit', '--quiet', '-m', 'start']))->mustRun();

    return $checkout;
}

function review_diff_instance(string $checkout): Instance
{
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit-'.bin2hex(random_bytes(3)), 'repository_url' => 'git@example.test:orbit.git', 'default_branch' => 'main']);
    $node = Node::query()->create(['name' => 'review-diff-'.bin2hex(random_bytes(3)), 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '10.44.0.144', 'wireguard_ip' => '10.44.0.144', 'user' => 'orbit']);

    return Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-14', 'checkout_path' => $checkout, 'branch' => 'task-14', 'status' => 'source_resolved']);
}

function review_diff_reader(SshExecutor $ssh): RemoteTaskReviewDiff
{
    return new RemoteTaskReviewDiff(new DevelopmentSshExecutor(
        $ssh,
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

function review_diff_result(CommandResult $result): SshExecutor
{
    return new class($result) implements SshExecutor
    {
        public function __construct(private CommandResult $result) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            return $this->result;
        }
    };
}
