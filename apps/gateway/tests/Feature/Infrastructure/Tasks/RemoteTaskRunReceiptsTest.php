<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskRunOutcome;
use App\Domain\Tasks\TaskRunReceiptException;
use App\Domain\Tasks\TaskThreadRole;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskRunReceipts;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\LocalShellSshExecutor;

function run_receipt_checkout(): string
{
    $checkout = sys_get_temp_dir().'/orbit-run-receipt-'.bin2hex(random_bytes(6));
    (new Process(['git', 'init', '--quiet', $checkout]))->mustRun();

    return $checkout;
}

function run_receipt_instance(string $checkout): AppInstance
{
    $app = OrbitApp::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'git@github.com:nckrtl/orbit.git', 'default_branch' => 'main']);
    $node = Node::query()->create(['name' => 'receipt-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '10.44.0.143', 'wireguard_ip' => '10.44.0.143', 'user' => 'orbit']);

    return AppInstance::query()->create(['app_id' => $app->id, 'node_id' => $node->id, 'name' => 'task-13', 'checkout_path' => $checkout, 'branch' => 'task-13', 'status' => 'source_resolved']);
}

function run_receipts(SshExecutor $transport): RemoteTaskRunReceipts
{
    return new RemoteTaskRunReceipts(new AppDevSshExecutor(
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

/** @param list<string> $arguments */
function run_receipt_script(string $checkout, array $arguments): Process
{
    $process = new Process([$checkout.'/.git/orbit/run', ...$arguments], $checkout);
    $process->run();

    return $process;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/orbit-run-receipt-*') ?: [] as $directory) {
        File::deleteDirectory($directory);
    }
});

it('installs the run script outside the tracked tree and reads the receipt it writes', function (): void {
    $checkout = run_receipt_checkout();
    $instance = run_receipt_instance($checkout);
    $receipts = run_receipts(new LocalShellSshExecutor);

    $receipts->prepare($instance, TaskThreadRole::Implementer);
    $written = run_receipt_script($checkout, ['--outcome=ready_for_review', '--summary', ' Added the export. ']);
    $receipt = $receipts->read($instance);
    $status = (new Process(['git', 'status', '--porcelain', '--untracked-files=all'], $checkout))->mustRun()->getOutput();

    expect($written->getExitCode())->toBe(0)
        ->and(is_executable($checkout.'/.git/orbit/run'))->toBeTrue()
        ->and(json_decode((string) file_get_contents($checkout.'/.git/orbit/turn.json'), true))->toBe(['role' => 'implementer'])
        ->and($receipt?->outcome)->toBe(TaskRunOutcome::ReadyForReview)
        ->and($receipt?->summary)->toBe('Added the export.')
        ->and($receipt?->hash)->toBe(hash_file('sha256', $checkout.'/.git/orbit/run.json'))
        ->and($status)->toBe('');
});

it('removes a receipt only while its content is unchanged', function (): void {
    $checkout = run_receipt_checkout();
    $instance = run_receipt_instance($checkout);
    $receipts = run_receipts(new LocalShellSshExecutor);
    $receipts->prepare($instance, TaskThreadRole::Implementer);
    run_receipt_script($checkout, ['--outcome=blocked', '--summary=The API key is missing.']);
    $first = $receipts->read($instance);
    run_receipt_script($checkout, ['--outcome=ready_for_review', '--summary=Done.']);

    $receipts->clear($instance, $first ?? throw new RuntimeException('No receipt.'));
    $second = $receipts->read($instance);
    $receipts->clear($instance, $second ?? throw new RuntimeException('No receipt.'));

    expect($second->outcome)->toBe(TaskRunOutcome::ReadyForReview)
        ->and($receipts->read($instance))->toBeNull();
});

it('removes an earlier receipt when a turn starts', function (): void {
    $checkout = run_receipt_checkout();
    $instance = run_receipt_instance($checkout);
    $receipts = run_receipts(new LocalShellSshExecutor);
    $receipts->prepare($instance, TaskThreadRole::Implementer);
    run_receipt_script($checkout, ['--outcome=ready_for_review', '--summary=Done.']);

    $receipts->prepare($instance, TaskThreadRole::Implementer);

    expect($receipts->read($instance))->toBeNull();
});

it('refuses input that does not fit the turn', function (TaskThreadRole $role, array $arguments, string $error): void {
    $checkout = run_receipt_checkout();
    $instance = run_receipt_instance($checkout);
    $receipts = run_receipts(new LocalShellSshExecutor);
    $receipts->prepare($instance, $role);

    $process = run_receipt_script($checkout, $arguments);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toBe("orbit run: {$error}\n")
        ->and($receipts->read($instance))->toBeNull();
})->with([
    'a reviewer outcome in an implementer turn' => [TaskThreadRole::Implementer, ['--outcome=approved', '--summary=Looks good.'], '--outcome must be one of: ready_for_review, blocked.'],
    'an implementer outcome in a reviewer turn' => [TaskThreadRole::Reviewer, ['--outcome=ready_for_review', '--summary=Done.'], '--outcome must be one of: approved, changes_requested, blocked.'],
    'an unknown outcome' => [TaskThreadRole::Implementer, ['--outcome=done', '--summary=Done.'], '--outcome must be one of: ready_for_review, blocked.'],
    'a missing outcome' => [TaskThreadRole::Implementer, ['--summary=Done.'], '--outcome must be one of: ready_for_review, blocked.'],
    'an empty summary' => [TaskThreadRole::Implementer, ['--outcome=blocked', '--summary=  '], '--summary is required.'],
    'a missing summary value' => [TaskThreadRole::Implementer, ['--outcome=blocked', '--summary'], '--summary needs a value.'],
    'a repeated flag' => [TaskThreadRole::Implementer, ['--outcome=blocked', '--outcome=blocked', '--summary=No.'], 'pass --outcome once.'],
    'an unknown flag' => [TaskThreadRole::Implementer, ['--outcome=blocked', '--summary=No.', '--force'], 'unknown argument --force.'],
]);

it('refuses to write a receipt before Orbit starts a turn', function (): void {
    $checkout = run_receipt_checkout();
    File::ensureDirectoryExists($checkout.'/.git/orbit');
    copy(resource_path('tasks/run'), $checkout.'/.git/orbit/run');
    chmod($checkout.'/.git/orbit/run', 0755);

    $process = run_receipt_script($checkout, ['--outcome=blocked', '--summary=No.']);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toBe("orbit run: Orbit has not started a turn in this workspace.\n");
});

it('treats a hand-written receipt without an outcome and summary as invalid', function (string $contents): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult(0, "receipt\n".$contents, '', 1, false)]);

    $receipt = run_receipts($transport)->read(run_receipt_instance('/srv/orbit/apps/orbit/task-13'));

    expect($receipt?->outcome)->toBeNull()
        ->and($receipt?->hash)->toBe(hash('sha256', $contents));
})->with([
    'invalid json' => ['{"outcome":'],
    'unknown outcome' => ['{"outcome":"done","summary":"Done."}'],
    'empty summary' => ['{"outcome":"blocked","summary":" "}'],
]);

it('reports an unreachable workspace instead of a missing receipt', function (): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult(255, '', 'ssh: connect to host 10.44.0.143 port 22: Connection refused', 1, false)]);

    run_receipts($transport)->read(run_receipt_instance('/srv/orbit/apps/orbit/task-13'));
})->throws(TaskRunReceiptException::class, 'The task workspace could not be reached for the run receipt.');
