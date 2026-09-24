<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskDeliverable;
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
        ->and(json_decode((string) file_get_contents($checkout.'/.git/orbit/turn.json'), true))->toBe(['role' => 'implementer', 'final' => false, 'deliverables' => []])
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
    run_receipt_script($checkout, ['--outcome=blocked', '--summary=The API key is missing.', '--question=Where do I find the API key?']);
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
    'an empty summary' => [TaskThreadRole::Implementer, ['--outcome=blocked', '--summary=  '], '--summary cannot be empty.'],
    'a missing summary' => [TaskThreadRole::Implementer, ['--outcome=blocked'], '--summary is required.'],
    'pull request fields before the last subtask' => [TaskThreadRole::Reviewer, ['--outcome=approved', '--summary=Good.', '--pr-summary=S', '--pr-change=C', '--pr-breaking=none'], '--pr-summary, --pr-change, and --pr-breaking are only for approving the last subtask.'],
    'a missing summary value' => [TaskThreadRole::Implementer, ['--outcome=blocked', '--summary'], '--summary needs a value.'],
    'a repeated flag' => [TaskThreadRole::Implementer, ['--outcome=blocked', '--outcome=blocked', '--summary=No.'], 'pass --outcome once.'],
    'an unknown flag' => [TaskThreadRole::Implementer, ['--outcome=blocked', '--summary=No.', '--force'], 'unknown argument --force.'],
    'a blocked implementer turn without a question' => [TaskThreadRole::Implementer, ['--outcome=blocked', '--summary=Gateway implementation not completed.'], 'blocked needs --question with one specific question the operator can answer. If you can decide or find the answer yourself, keep working instead.'],
    'a blocked reviewer turn without a question' => [TaskThreadRole::Reviewer, ['--outcome=blocked', '--summary=The brief is unclear.'], 'blocked needs --question with one specific question the operator can answer. If you can decide or find the answer yourself, keep working instead.'],
    'an empty question' => [TaskThreadRole::Implementer, ['--outcome=blocked', '--summary=No.', '--question= '], '--question cannot be empty.'],
    'a question on another outcome' => [TaskThreadRole::Implementer, ['--outcome=ready_for_review', '--summary=Done.', '--question=Is this fine?'], '--question is only for --outcome=blocked.'],
]);

it('records the question of a blocked turn', function (): void {
    $checkout = run_receipt_checkout();
    $instance = run_receipt_instance($checkout);
    $receipts = run_receipts(new LocalShellSshExecutor);
    $receipts->prepare($instance, TaskThreadRole::Implementer);

    $process = run_receipt_script($checkout, ['--outcome=blocked', '--summary=Installing intl needs sudo.', '--question', ' May I run sudo apt-get install php8.5-intl? ']);
    $receipt = $receipts->read($instance);

    expect($process->getExitCode())->toBe(0)
        ->and($receipt?->outcome)->toBe(TaskRunOutcome::Blocked)
        ->and($receipt?->question)->toBe('May I run sudo apt-get install php8.5-intl?')
        ->and($receipt?->body())->toBe("Installing intl needs sudo.\n\nQuestion: May I run sudo apt-get install php8.5-intl?");
});

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
    'blocked without a question' => ['{"outcome":"blocked","summary":"Gateway implementation not completed."}'],
    'blocked with an empty question' => ['{"outcome":"blocked","summary":"Stuck.","question":" "}'],
]);

it('reports an unreachable workspace instead of a missing receipt', function (): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult(255, '', 'ssh: connect to host 10.44.0.143 port 22: Connection refused', 1, false)]);

    run_receipts($transport)->read(run_receipt_instance('/srv/orbit/apps/orbit/task-13'));
})->throws(TaskRunReceiptException::class, 'The task workspace could not be reached for the run receipt.');

it('requires the pull request fields when the reviewer approves the last subtask', function (array $arguments, string $error): void {
    $checkout = run_receipt_checkout();
    $instance = run_receipt_instance($checkout);
    $receipts = run_receipts(new LocalShellSshExecutor);
    $receipts->prepare($instance, TaskThreadRole::Reviewer, true);

    $process = run_receipt_script($checkout, ['--outcome=approved', '--summary=Checked the feature.', ...$arguments]);

    expect($process->getErrorOutput())->toBe("orbit run: {$error}\n")
        ->and($receipts->read($instance))->toBeNull();
})->with([
    'no fields' => [[], 'approving the last subtask needs --pr-summary.'],
    'no change' => [['--pr-summary=Adds exports.', '--pr-breaking=none'], 'approving the last subtask needs at least one --pr-change.'],
    'no breaking answer' => [['--pr-summary=Adds exports.', '--pr-change=Exports orders.'], 'approving the last subtask needs at least one --pr-breaking. Use --pr-breaking=none when nothing breaks.'],
    'none with a breaking change' => [['--pr-summary=S', '--pr-change=C', '--pr-breaking=none', '--pr-breaking=Renames a command.'], '--pr-breaking=none cannot be combined with other breaking changes.'],
    'two summaries' => [['--pr-summary=S', '--pr-summary=T', '--pr-change=C', '--pr-breaking=none'], 'pass --pr-summary once.'],
    'an empty change' => [['--pr-summary=S', '--pr-change=', '--pr-breaking=none'], '--pr-change cannot be empty.'],
]);

it('records the pull request fields with the approval of the last subtask, with no limit on changes', function (): void {
    $checkout = run_receipt_checkout();
    $instance = run_receipt_instance($checkout);
    $receipts = run_receipts(new LocalShellSshExecutor);
    $receipts->prepare($instance, TaskThreadRole::Reviewer, true);
    $changes = array_map(static fn (int $number): string => '--pr-change=Change '.$number, range(1, 40));

    $process = run_receipt_script($checkout, ['--outcome=approved', '--summary=Checked the feature.', '--pr-summary=Adds exports.', ...$changes, '--pr-breaking=None']);
    $receipt = $receipts->read($instance);

    expect($process->getExitCode())->toBe(0)
        ->and(json_decode((string) file_get_contents($checkout.'/.git/orbit/turn.json'), true))->toBe(['role' => 'reviewer', 'final' => true, 'deliverables' => []])
        ->and($receipt?->outcome)->toBe(TaskRunOutcome::Approved)
        ->and($receipt?->pullRequest?->summary)->toBe('Adds exports.')
        ->and($receipt?->pullRequest?->changes)->toHaveCount(40)
        ->and($receipt?->pullRequest?->breaking)->toBe([]);
});

it('lets a reviewer request changes on the last subtask without the pull request fields', function (): void {
    $checkout = run_receipt_checkout();
    $instance = run_receipt_instance($checkout);
    $receipts = run_receipts(new LocalShellSshExecutor);
    $receipts->prepare($instance, TaskThreadRole::Reviewer, true);

    $process = run_receipt_script($checkout, ['--outcome=changes_requested', '--summary=Add the missing test.']);

    expect($process->getExitCode())->toBe(0)
        ->and($receipts->read($instance)?->outcome)->toBe(TaskRunOutcome::ChangesRequested);
});

/** @return list<TaskDeliverable> */
function run_receipt_deliverables(): array
{
    return [
        TaskDeliverable::fromArray(['id' => 'reference-page', 'type' => 'file', 'description' => 'Document the export', 'path' => 'docs/reference/tasks.md', 'change' => 'modified']),
        TaskDeliverable::fromArray(['id' => 'export-test', 'type' => 'test', 'description' => 'Test the export', 'project' => 'apps/gateway', 'file' => 'tests/Feature/ExportTest.php', 'name' => 'exports']),
        TaskDeliverable::fromArray(['id' => 'error-copy', 'type' => 'review', 'description' => 'Errors name the subtask']),
    ];
}

describe('deliverable confirmations', function (): void {
    it('writes the deliverables into the turn', function (): void {
        $checkout = run_receipt_checkout();
        $receipts = run_receipts(new LocalShellSshExecutor);

        $receipts->prepare(run_receipt_instance($checkout), TaskThreadRole::Implementer, deliverables: run_receipt_deliverables());

        expect(json_decode((string) file_get_contents($checkout.'/.git/orbit/turn.json'), true)['deliverables'])->toBe([
            ['id' => 'reference-page', 'type' => 'file', 'description' => 'Document the export'],
            ['id' => 'export-test', 'type' => 'test', 'description' => 'Test the export'],
            ['id' => 'error-copy', 'type' => 'review', 'description' => 'Errors name the subtask'],
        ]);
    });

    it('records the confirmation of every deliverable in a ready_for_review receipt', function (): void {
        $checkout = run_receipt_checkout();
        $instance = run_receipt_instance($checkout);
        $receipts = run_receipts(new LocalShellSshExecutor);
        $receipts->prepare($instance, TaskThreadRole::Implementer, deliverables: run_receipt_deliverables());

        $process = run_receipt_script($checkout, [
            '--outcome=ready_for_review', '--summary=Added the export.',
            '--deliverable=reference-page=Export section in docs/reference/tasks.md',
            '--deliverable', 'export-test= tests/Feature/ExportTest.php covers it ',
            '--deliverable=error-copy=The error names the subtask ID',
        ]);

        expect($process->getExitCode())->toBe(0)
            ->and($receipts->read($instance)?->deliverables)->toBe([
                'reference-page' => 'Export section in docs/reference/tasks.md',
                'export-test' => 'tests/Feature/ExportTest.php covers it',
                'error-copy' => 'The error names the subtask ID',
            ]);
    });

    it('records the review confirmations of an approval', function (): void {
        $checkout = run_receipt_checkout();
        $instance = run_receipt_instance($checkout);
        $receipts = run_receipts(new LocalShellSshExecutor);
        $receipts->prepare($instance, TaskThreadRole::Reviewer, deliverables: run_receipt_deliverables());

        $process = run_receipt_script($checkout, ['--outcome=approved', '--summary=Checked.', '--deliverable=error-copy=Read each message in ExportController']);

        expect($process->getExitCode())->toBe(0)
            ->and($receipts->read($instance)?->deliverables)->toBe(['error-copy' => 'Read each message in ExportController']);
    });

    it('refuses confirmations that do not fit the turn', function (TaskThreadRole $role, array $arguments, string $error): void {
        $checkout = run_receipt_checkout();
        $instance = run_receipt_instance($checkout);
        $receipts = run_receipts(new LocalShellSshExecutor);
        $receipts->prepare($instance, $role, deliverables: run_receipt_deliverables());

        $process = run_receipt_script($checkout, $arguments);

        expect($process->getExitCode())->toBe(2)
            ->and($process->getErrorOutput())->toBe("orbit run: {$error}\n")
            ->and($receipts->read($instance))->toBeNull();
    })->with([
        'a handoff without confirmations' => [TaskThreadRole::Implementer, ['--outcome=ready_for_review', '--summary=Done.'], 'ready_for_review needs --deliverable=ID=evidence for: reference-page, export-test, error-copy. Say where or how each one is met.'],
        'a handoff that misses one' => [TaskThreadRole::Implementer, ['--outcome=ready_for_review', '--summary=Done.', '--deliverable=reference-page=Updated', '--deliverable=error-copy=Done'], 'ready_for_review needs --deliverable=ID=evidence for: export-test. Say where or how each one is met.'],
        'an unknown deliverable' => [TaskThreadRole::Implementer, ['--outcome=ready_for_review', '--summary=Done.', '--deliverable=changelog=Added'], 'unknown deliverable changelog. This subtask\'s deliverables are: reference-page, export-test, error-copy.'],
        'a deliverable confirmed twice' => [TaskThreadRole::Implementer, ['--outcome=ready_for_review', '--summary=Done.', '--deliverable=error-copy=A', '--deliverable=error-copy=B'], 'confirm deliverable error-copy once.'],
        'a confirmation without evidence' => [TaskThreadRole::Implementer, ['--outcome=ready_for_review', '--summary=Done.', '--deliverable=error-copy= '], '--deliverable=error-copy needs evidence after the equals sign.'],
        'a confirmation without an ID' => [TaskThreadRole::Implementer, ['--outcome=ready_for_review', '--summary=Done.', '--deliverable=Everything is done'], '--deliverable needs the form ID=evidence, such as --deliverable=export-test="tests/Feature/ExportTest.php".'],
        'a confirmation on a blocked turn' => [TaskThreadRole::Implementer, ['--outcome=blocked', '--summary=Stuck.', '--question=Which API?', '--deliverable=error-copy=Done'], '--deliverable is only for --outcome=ready_for_review.'],
        'an approval without the review confirmation' => [TaskThreadRole::Reviewer, ['--outcome=approved', '--summary=Good.', '--deliverable=reference-page=Read it'], 'approved needs --deliverable=ID=evidence for: error-copy. Say where or how each one is met.'],
        'confirmations on requested changes' => [TaskThreadRole::Reviewer, ['--outcome=changes_requested', '--summary=Fix it.', '--deliverable=error-copy=Missing'], '--deliverable is only for --outcome=approved.'],
    ]);

    it('refuses any confirmation for a subtask without deliverables', function (): void {
        $checkout = run_receipt_checkout();
        $instance = run_receipt_instance($checkout);
        run_receipts(new LocalShellSshExecutor)->prepare($instance, TaskThreadRole::Implementer);

        $process = run_receipt_script($checkout, ['--outcome=ready_for_review', '--summary=Done.', '--deliverable=docs=Added']);

        expect($process->getExitCode())->toBe(2)
            ->and($process->getErrorOutput())->toBe("orbit run: unknown deliverable docs. This subtask's deliverables are: none.\n");
    });
});
