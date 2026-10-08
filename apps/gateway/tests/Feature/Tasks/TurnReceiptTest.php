<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\QuestionCause;
use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskPromptGroup;
use App\Domain\Tasks\TaskPromptRenderer;
use App\Domain\Tasks\TaskPromptSubtask;
use App\Domain\Tasks\TaskRubricItem;
use App\Domain\Tasks\TaskRubricReminder;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskTurnInstructions;
use App\Domain\Tasks\TaskTurnMode;
use App\Domain\Tasks\TaskTurnOutcome;
use App\Domain\Tasks\TaskTurnReceipt;
use App\Domain\Tasks\TaskTurnReceiptException;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Compute\SandboxFleetIdentity;
use App\Infrastructure\Compute\TaskSandboxDrivers;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\IncusSandboxHost;
use App\Infrastructure\Tasks\RemoteTaskTurnReceipts;
use App\Infrastructure\Tasks\TaskWorkspaceExecutor;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\TestOrbitHome;

function turn_receipt_checkout(): string
{
    $checkout = TestOrbitHome::scratch('orbit-turn-receipt');
    (new Process(['git', 'init', '--quiet', $checkout]))->mustRun();

    return $checkout;
}

function turn_receipt_instance(string $checkout): Instance
{
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'git@github.com:nckrtl/orbit.git', 'default_branch' => 'main']);
    $node = Node::query()->create(['name' => 'receipt-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '10.44.0.143', 'wireguard_ip' => '10.44.0.143', 'user' => 'orbit']);

    return Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-13', 'checkout_path' => $checkout, 'branch' => 'task-13', 'status' => 'source_resolved']);
}

function turn_receipts(SshExecutor $transport): RemoteTaskTurnReceipts
{
    return new RemoteTaskTurnReceipts(new TaskWorkspaceExecutor(new DevelopmentSshExecutor(
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
    ), app(IncusSandboxHost::class), app(TaskSandboxDrivers::class), app(SandboxFleetIdentity::class)));
}

/** @param list<string> $arguments */
function turn_receipt_script(string $checkout, array $arguments): Process
{
    $process = new Process([$checkout.'/.git/orbit/turn', ...$arguments], $checkout);
    $process->run();

    return $process;
}

afterEach(function (): void {
    TestOrbitHome::clearScratch();
});

describe('TaskGitHardening', function (): void {
    it('publishes turn metadata without writing through planted final or temporary links', function (string $name): void {
        $checkout = turn_receipt_checkout();
        File::ensureDirectoryExists($checkout.'/.git/orbit');
        $target = TestOrbitHome::scratch('private-turn-target');
        file_put_contents($target, 'private Node file');
        chmod($target, 0600);
        symlink($target, $checkout.'/.git/orbit/'.$name);

        turn_receipts(new LocalShellSshExecutor)->prepare(turn_receipt_instance($checkout), TaskThreadRole::Implementer, threadId: 17, context: 'Task context');

        expect(file_get_contents($target))->toBe('private Node file')
            ->and(file_get_contents($checkout.'/.git/orbit/context.md'))->toBe('Task context');
    })->with(['turn.new', 'turn.json.new', 'context.md.new', 'turn', 'turn.json', 'context.md', 'receipt.json']);

    it('refuses to read a turn or receipt link into a private Node file', function (string $name): void {
        $checkout = turn_receipt_checkout();
        $instance = turn_receipt_instance($checkout);
        $receipts = turn_receipts(new LocalShellSshExecutor);
        $receipts->prepare($instance, TaskThreadRole::Implementer);
        file_put_contents($checkout.'/.git/orbit/receipt.json', '{}');
        $target = TestOrbitHome::scratch('private-read-target');
        file_put_contents($target, 'private Node file');
        unlink($checkout.'/.git/orbit/'.$name);
        symlink($target, $checkout.'/.git/orbit/'.$name);

        expect(fn () => $receipts->read($instance))->toThrow(TaskTurnReceiptException::class)
            ->and(file_get_contents($target))->toBe('private Node file');
    })->with(['turn.json', 'receipt.json']);
});

it('installs the turn command outside the tracked tree and reads the receipt it writes', function (): void {
    $checkout = turn_receipt_checkout();
    $instance = turn_receipt_instance($checkout);
    $receipts = turn_receipts(new LocalShellSshExecutor);

    $receipts->prepare($instance, TaskThreadRole::Implementer);
    $written = turn_receipt_script($checkout, ['--outcome=ready_for_review', '--summary', ' Added the export. ']);
    $receipt = $receipts->read($instance);
    $status = (new Process(['git', 'status', '--porcelain', '--untracked-files=all'], $checkout))->mustRun()->getOutput();

    expect($written->getExitCode())->toBe(0)
        ->and($written->getOutput())->toBe("Orbit recorded the turn receipt (ready_for_review). End your turn now.\n")
        ->and(is_executable($checkout.'/.git/orbit/turn'))->toBeTrue()
        ->and(fileperms($checkout.'/.git/orbit') & 0777)->toBe(0775)
        ->and(is_file($checkout.'/.git/orbit/run'))->toBeFalse()
        ->and(is_file($checkout.'/.git/orbit/run.json'))->toBeFalse()
        ->and(json_decode((string) file_get_contents($checkout.'/.git/orbit/turn.json'), true))->toBe(['role' => 'implementer', 'final' => false, 'deliverables' => []])
        ->and($receipt?->outcome)->toBe(TaskTurnOutcome::ReadyForReview)
        ->and($receipt?->summary)->toBe('Added the export.')
        ->and($receipt?->hash)->toBe(hash_file('sha256', $checkout.'/.git/orbit/receipt.json'))
        ->and($status)->toBe('');
});

it('removes the old run command and receipt when it installs turn', function (): void {
    $checkout = turn_receipt_checkout();
    $instance = turn_receipt_instance($checkout);
    $receipts = turn_receipts(new LocalShellSshExecutor);
    $orbit = $checkout.'/.git/orbit';
    mkdir($orbit, 0755, true);
    file_put_contents($orbit.'/run', "#!/usr/bin/env python3\n");
    chmod($orbit.'/run', 0755);
    file_put_contents($orbit.'/run.json', "{\"role\":\"implementer\"}\n");

    $receipts->prepare($instance, TaskThreadRole::Implementer, threadId: 17);

    expect(is_file($orbit.'/run'))->toBeFalse()
        ->and(is_file($orbit.'/run.json'))->toBeFalse()
        ->and(is_executable($orbit.'/turn'))->toBeTrue()
        ->and(is_file($orbit.'/turn.json'))->toBeTrue();
});

it('does not read a receipt left at the old run path', function (): void {
    $checkout = turn_receipt_checkout();
    $instance = turn_receipt_instance($checkout);
    $receipts = turn_receipts(new LocalShellSshExecutor);
    $receipts->prepare($instance, TaskThreadRole::Implementer, threadId: 17);
    $orbit = $checkout.'/.git/orbit';
    file_put_contents($orbit.'/run', "#!/usr/bin/env python3\n");
    chmod($orbit.'/run', 0755);
    file_put_contents($orbit.'/run.json', json_encode([
        'outcome' => 'ready_for_review',
        'summary' => 'Used the old path.',
        'thread' => 17,
        'nonce' => 'old',
    ], JSON_THROW_ON_ERROR)."\n");

    expect(is_file($orbit.'/run'))->toBeTrue()
        ->and($receipts->read($instance, 17))->toBeNull()
        ->and(is_file($orbit.'/receipt.json'))->toBeFalse();
});

it('does not apply a turn receipt written by the other reviewer', function (): void {
    $checkout = turn_receipt_checkout();
    $instance = turn_receipt_instance($checkout);
    $receipts = turn_receipts(new LocalShellSshExecutor);
    $receipts->prepare($instance, TaskThreadRole::Reviewer);
    $turnPath = $checkout.'/.git/orbit/turn.json';
    $turn = json_decode((string) file_get_contents($turnPath), true, 512, JSON_THROW_ON_ERROR);
    $turn['thread'] = 42;
    file_put_contents($turnPath, json_encode($turn, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");

    $written = turn_receipt_script($checkout, ['--outcome=approved', '--summary=Approved the earlier subtask.']);

    expect($written->getExitCode())->toBe(0)
        ->and($written->getErrorOutput())->toBe('')
        ->and(is_file($checkout.'/.git/orbit/receipt.json'))->toBeTrue()
        ->and($receipts->read($instance))->toBeNull();

    $other = turn_receipt_script($checkout, ['--thread=7', '--outcome=approved', '--summary=Approved the earlier subtask.']);

    expect($other->getExitCode())->toBe(0)
        ->and((string) file_get_contents($checkout.'/.git/orbit/receipt.json'))->toContain('"thread": 7')
        ->and($receipts->read($instance))->toBeNull();
});

it('applies the acting thread receipt for the reviewer and the implementer', function (): void {
    $checkout = turn_receipt_checkout();
    $instance = turn_receipt_instance($checkout);
    $receipts = turn_receipts(new LocalShellSshExecutor);

    $receipts->prepare($instance, TaskThreadRole::Reviewer, threadId: 42);
    $approved = turn_receipt_script($checkout, ['--thread=42', '--outcome=approved', '--summary=Checked this subtask.']);
    $receipt = $receipts->read($instance);

    expect($approved->getExitCode())->toBe(0)
        ->and($receipt?->outcome)->toBe(TaskTurnOutcome::Approved)
        ->and($receipt?->threadId)->toBe(42);

    $receipts->clear($instance, $receipt ?? throw new RuntimeException('No receipt.'));
    $receipts->prepare($instance, TaskThreadRole::Implementer, threadId: 9);
    $handed = turn_receipt_script($checkout, ['--thread=9', '--outcome=ready_for_review', '--summary=Added the export.']);
    $implementer = $receipts->read($instance);

    expect($handed->getExitCode())->toBe(0)
        ->and($implementer?->outcome)->toBe(TaskTurnOutcome::ReadyForReview)
        ->and($implementer?->threadId)->toBe(9);

    $receipts->clear($instance, $implementer ?? throw new RuntimeException('No receipt.'));
    turn_receipt_script($checkout, ['--thread=4', '--outcome=ready_for_review', '--summary=From another implementer.']);

    expect($receipts->read($instance))->toBeNull();
});

it('applies the acting thread receipt when the turn file names another reviewer', function (): void {
    $checkout = turn_receipt_checkout();
    $instance = turn_receipt_instance($checkout);
    $receipts = turn_receipts(new LocalShellSshExecutor);
    $receipts->prepare($instance, TaskThreadRole::Reviewer, threadId: 42);
    $current = turn_receipt_script($checkout, ['--thread=9', '--outcome=approved', '--summary=Approved this subtask.']);
    $applied = $receipts->read($instance, 9);

    expect($current->getExitCode())->toBe(0)
        ->and($receipts->read($instance))->toBeNull()
        ->and($receipts->read($instance, 42))->toBeNull()
        ->and($applied?->threadId)->toBe(9)
        ->and($applied?->outcome)->toBe(TaskTurnOutcome::Approved);

    $stale = turn_receipt_script($checkout, ['--thread=42', '--outcome=approved', '--summary=Approved the earlier subtask.']);

    expect($stale->getExitCode())->toBe(0)
        ->and($receipts->read($instance)?->threadId)->toBe(42)
        ->and($receipts->read($instance, 9))->toBeNull();
});

it('does not apply an unbound receipt from a legacy turn when the acting reviewer is known', function (): void {
    $checkout = turn_receipt_checkout();
    $instance = turn_receipt_instance($checkout);
    $receipts = turn_receipts(new LocalShellSshExecutor);
    $receipts->prepare($instance, TaskThreadRole::Reviewer);
    $legacy = turn_receipt_script($checkout, ['--outcome=approved', '--summary=Approved the earlier subtask.']);

    expect($legacy->getExitCode())->toBe(0)
        ->and($receipts->hasLegacyTurn($instance))->toBeTrue()
        ->and($receipts->read($instance, 42))->toBeNull();

    $receipts->prepare($instance, TaskThreadRole::Reviewer, threadId: 42);
    $stillUnbound = turn_receipt_script($checkout, ['--outcome=approved', '--summary=Approved the earlier subtask.']);

    expect($stillUnbound->getExitCode())->toBe(0)
        ->and($receipts->hasLegacyTurn($instance))->toBeFalse()
        ->and($receipts->read($instance, 42))->toBeNull();

    $bound = turn_receipt_script($checkout, ['--thread=42', '--outcome=approved', '--summary=Checked this subtask.']);

    expect($bound->getExitCode())->toBe(0)
        ->and($receipts->read($instance, 42)?->threadId)->toBe(42)
        ->and($receipts->read($instance, 42)?->summary)->toBe('Checked this subtask.');
});

it('removes a receipt only while its content is unchanged', function (): void {
    $checkout = turn_receipt_checkout();
    $instance = turn_receipt_instance($checkout);
    $receipts = turn_receipts(new LocalShellSshExecutor);
    $receipts->prepare($instance, TaskThreadRole::Implementer);
    turn_receipt_script($checkout, ['--outcome=blocked', '--summary=The API key is missing.', '--question=Where do I find the API key?']);
    $first = $receipts->read($instance);
    turn_receipt_script($checkout, ['--outcome=ready_for_review', '--summary=Done.']);

    $receipts->clear($instance, $first ?? throw new RuntimeException('No receipt.'));
    $second = $receipts->read($instance);
    $receipts->clear($instance, $second ?? throw new RuntimeException('No receipt.'));

    expect($second->outcome)->toBe(TaskTurnOutcome::ReadyForReview)
        ->and($receipts->read($instance))->toBeNull();
});

it('removes an earlier receipt when a turn starts', function (): void {
    $checkout = turn_receipt_checkout();
    $instance = turn_receipt_instance($checkout);
    $receipts = turn_receipts(new LocalShellSshExecutor);
    $receipts->prepare($instance, TaskThreadRole::Implementer);
    turn_receipt_script($checkout, ['--outcome=ready_for_review', '--summary=Done.']);

    $receipts->prepare($instance, TaskThreadRole::Implementer);

    expect($receipts->read($instance))->toBeNull();
});

it('refuses input that does not fit the turn', function (TaskThreadRole $role, array $arguments, string $error): void {
    $checkout = turn_receipt_checkout();
    $instance = turn_receipt_instance($checkout);
    $receipts = turn_receipts(new LocalShellSshExecutor);
    $receipts->prepare($instance, $role);

    $process = turn_receipt_script($checkout, $arguments);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toBe("orbit turn: {$error}\n")
        ->and($receipts->read($instance))->toBeNull();
})->with([
    'a reviewer outcome in an implementer turn' => [TaskThreadRole::Implementer, ['--outcome=approved', '--summary=Looks good.'], '--outcome must be one of: ready_for_review, blocked.'],
    'an implementer outcome in a reviewer turn' => [TaskThreadRole::Reviewer, ['--outcome=ready_for_review', '--summary=Done.'], '--outcome must be one of: approved, changes_requested, blocked, topology_requested.'],
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
    'a blocked reviewer turn without a cause' => [TaskThreadRole::Reviewer, ['--outcome=blocked', '--summary=The brief is unclear.', '--question=Which ADR wins?'], '--cause must be one of: brief_unclear, contract_gap, scope, environment, missed_contract.'],
    'a cause on an implementer turn' => [TaskThreadRole::Implementer, ['--outcome=blocked', '--summary=No.', '--question=May I install it?', '--cause=scope'], '--cause is only for a reviewer answering or blocked, or the review after a direction resolution.'],
    'a cause on an ordinary approval' => [TaskThreadRole::Reviewer, ['--outcome=approved', '--summary=Good.', '--cause=scope'], '--cause is only for a reviewer answering or blocked, or the review after a direction resolution.'],
    'a cause on an ordinary changes request' => [TaskThreadRole::Reviewer, ['--outcome=changes_requested', '--summary=Fix the export.', '--cause=scope'], '--cause is only for a reviewer answering or blocked, or the review after a direction resolution.'],
    'a cause on an ordinary ready for review' => [TaskThreadRole::Implementer, ['--outcome=ready_for_review', '--summary=Done.', '--cause=environment'], '--cause is only for a reviewer answering or blocked, or the review after a direction resolution.'],
]);

it('records the question of a blocked turn', function (): void {
    $checkout = turn_receipt_checkout();
    $instance = turn_receipt_instance($checkout);
    $receipts = turn_receipts(new LocalShellSshExecutor);
    $receipts->prepare($instance, TaskThreadRole::Implementer);

    $process = turn_receipt_script($checkout, ['--outcome=blocked', '--summary=Installing intl needs sudo.', '--question', ' May I run sudo apt-get install php8.5-intl? ']);
    $receipt = $receipts->read($instance);

    expect($process->getExitCode())->toBe(0)
        ->and($receipt?->outcome)->toBe(TaskTurnOutcome::Blocked)
        ->and($receipt?->question)->toBe('May I run sudo apt-get install php8.5-intl?')
        ->and($receipt?->body())->toBe("Installing intl needs sudo.\n\nQuestion: May I run sudo apt-get install php8.5-intl?");
});

it('records a reviewer cause and limits a relay to answers or resource requests', function (): void {
    $checkout = turn_receipt_checkout();
    $instance = turn_receipt_instance($checkout);
    $receipts = turn_receipts(new LocalShellSshExecutor);
    $receipts->prepare($instance, TaskThreadRole::Reviewer, mode: new TaskTurnMode(relay: true));

    $refused = turn_receipt_script($checkout, ['--outcome=approved', '--summary=Good.', '--cause=scope']);
    expect($refused->getExitCode())->toBe(2)
        ->and($refused->getErrorOutput())->toBe("orbit turn: --outcome must be one of: answered, blocked, topology_requested.\n");

    $recorded = turn_receipt_script($checkout, ['--outcome=answered', '--summary=Use the ADR.', '--cause=contract_gap']);
    $receipt = $receipts->read($instance);

    expect($recorded->getExitCode())->toBe(0)
        ->and($receipt?->outcome)->toBe(TaskTurnOutcome::Answered)
        ->and($receipt?->cause)->toBe(QuestionCause::ContractGap->value)
        ->and($receipt?->summary)->toBe('Use the ADR.');
});

it('requires a known cause on an answered relay and accepts every cause', function (): void {
    $checkout = turn_receipt_checkout();
    $instance = turn_receipt_instance($checkout);
    $receipts = turn_receipts(new LocalShellSshExecutor);
    $receipts->prepare($instance, TaskThreadRole::Reviewer, mode: new TaskTurnMode(relay: true));
    $causeError = '--cause must be one of: brief_unclear, contract_gap, scope, environment, missed_contract.';

    $missing = turn_receipt_script($checkout, ['--outcome=answered', '--summary=Use the ADR.']);
    $invalid = turn_receipt_script($checkout, ['--outcome=answered', '--summary=Use the ADR.', '--cause=nope']);

    expect($missing->getExitCode())->toBe(2)
        ->and($missing->getErrorOutput())->toBe("orbit turn: {$causeError}\n")
        ->and($invalid->getExitCode())->toBe(2)
        ->and($invalid->getErrorOutput())->toBe("orbit turn: {$causeError}\n")
        ->and($receipts->read($instance))->toBeNull();

    foreach (QuestionCause::cases() as $cause) {
        $receipts->prepare($instance, TaskThreadRole::Reviewer, mode: new TaskTurnMode(relay: true));
        $process = turn_receipt_script($checkout, ['--outcome=answered', '--summary=Noted.', '--cause='.$cause->value]);
        $receipt = $receipts->read($instance);

        expect($process->getExitCode())->toBe(0)
            ->and($receipt?->outcome)->toBe(TaskTurnOutcome::Answered)
            ->and($receipt?->cause)->toBe($cause->value);
    }
});

it('requires a cause on every outcome of the review after a direction resolution', function (): void {
    $checkout = turn_receipt_checkout();
    $instance = turn_receipt_instance($checkout);
    $receipts = turn_receipts(new LocalShellSshExecutor);
    $receipts->prepare($instance, TaskThreadRole::Reviewer, mode: new TaskTurnMode(causeRequired: true));

    $refused = turn_receipt_script($checkout, ['--outcome=approved', '--summary=Good.']);
    expect($refused->getExitCode())->toBe(2)
        ->and($refused->getErrorOutput())->toContain('--cause must be one of:');

    $recorded = turn_receipt_script($checkout, ['--outcome=approved', '--summary=Good.', '--cause=brief_unclear']);
    $receipt = $receipts->read($instance);

    expect($recorded->getExitCode())->toBe(0)
        ->and($receipt?->outcome)->toBe(TaskTurnOutcome::Approved)
        ->and($receipt?->cause)->toBe('brief_unclear');
});

it('records a topology request in every reviewer context without cause or final approval fields', function (?TaskTurnMode $mode): void {
    $checkout = turn_receipt_checkout();
    $instance = turn_receipt_instance($checkout);
    $receipts = turn_receipts(new LocalShellSshExecutor);
    $receipts->prepare($instance, TaskThreadRole::Reviewer, final: true, mode: $mode);
    $process = turn_receipt_script($checkout, ['--outcome=topology_requested', '--summary=Discovery needs Nodes.']);
    expect($process->getExitCode())->toBe(0)
        ->and($receipts->read($instance)?->outcome)->toBe(TaskTurnOutcome::TopologyRequested);
})->with([null, new TaskTurnMode(consult: true), new TaskTurnMode(relay: true), new TaskTurnMode(causeRequired: true)]);

it('refuses an implementer topology request with consult guidance', function (): void {
    $checkout = turn_receipt_checkout();
    $receipts = turn_receipts(new LocalShellSshExecutor);
    $instance = turn_receipt_instance($checkout);
    $receipts->prepare($instance, TaskThreadRole::Implementer);
    $process = turn_receipt_script($checkout, ['--outcome=topology_requested', '--summary=Need Nodes.']);
    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('Ask the reviewer through a blocked consult')
        ->and($receipts->read($instance))->toBeNull();
});

it('refuses question, cause and pull request fields on a topology request at both receipt boundaries', function (string $flag, array $field): void {
    $checkout = turn_receipt_checkout();
    $receipts = turn_receipts(new LocalShellSshExecutor);
    $instance = turn_receipt_instance($checkout);
    $receipts->prepare($instance, TaskThreadRole::Reviewer);
    $process = turn_receipt_script($checkout, ['--outcome=topology_requested', '--summary=Need Nodes.', $flag]);
    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('topology_requested refuses')
        ->and(TaskTurnReceipt::parse(json_encode(['outcome' => 'topology_requested', 'summary' => 'Need Nodes.', ...$field], JSON_THROW_ON_ERROR))->outcome)->toBeNull();
})->with([
    ['--question=Why?', ['question' => 'Why?']],
    ['--cause=environment', ['cause' => 'environment']],
    ['--pr-summary=Feature', ['pull_request' => ['summary' => 'Feature']]],
    ['--pr-change=Feature', ['pull_request' => ['changes' => ['Feature']]]],
    ['--pr-breaking=none', ['pull_request' => ['breaking' => []]]],
]);

it('refuses to write a receipt before Orbit starts a turn', function (): void {
    $checkout = turn_receipt_checkout();
    File::ensureDirectoryExists($checkout.'/.git/orbit');
    copy(resource_path('tasks/turn'), $checkout.'/.git/orbit/turn');
    chmod($checkout.'/.git/orbit/turn', 0755);

    $process = turn_receipt_script($checkout, ['--outcome=blocked', '--summary=No.']);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toBe("orbit turn: Orbit has not started a turn in this workspace.\n");
});

it('treats a hand-written receipt without an outcome and summary as invalid', function (string $contents): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult(0, "receipt\n".$contents, '', 1, false)]);

    $receipt = turn_receipts($transport)->read(turn_receipt_instance('/srv/orbit/apps/orbit/task-13'));

    expect($receipt?->outcome)->toBeNull()
        ->and($receipt?->hash)->toBe(hash('sha256', $contents));
})->with([
    'invalid json' => ['{"outcome":'],
    'unknown outcome' => ['{"outcome":"done","summary":"Done."}'],
    'empty summary' => ['{"outcome":"blocked","summary":" "}'],
    'blocked without a question' => ['{"outcome":"blocked","summary":"Gateway implementation not completed."}'],
    'blocked with an empty question' => ['{"outcome":"blocked","summary":"Stuck.","question":" "}'],
    'an unknown cause' => ['{"outcome":"blocked","summary":"Stuck.","question":"Which?","cause":"taste"}'],
]);

it('reports an unreachable workspace instead of a missing receipt', function (): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult(255, '', 'ssh: connect to host 10.44.0.143 port 22: Connection refused', 1, false)]);

    turn_receipts($transport)->read(turn_receipt_instance('/srv/orbit/apps/orbit/task-13'));
})->throws(TaskTurnReceiptException::class, 'The task workspace could not be reached for the turn receipt.');

it('requires the pull request fields when the reviewer approves the last subtask', function (array $arguments, string $error): void {
    $checkout = turn_receipt_checkout();
    $instance = turn_receipt_instance($checkout);
    $receipts = turn_receipts(new LocalShellSshExecutor);
    $receipts->prepare($instance, TaskThreadRole::Reviewer, true);

    $process = turn_receipt_script($checkout, ['--outcome=approved', '--summary=Checked the feature.', ...$arguments]);

    expect($process->getErrorOutput())->toBe("orbit turn: {$error}\n")
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
    $checkout = turn_receipt_checkout();
    $instance = turn_receipt_instance($checkout);
    $receipts = turn_receipts(new LocalShellSshExecutor);
    $receipts->prepare($instance, TaskThreadRole::Reviewer, true);
    $changes = array_map(static fn (int $number): string => '--pr-change=Change '.$number, range(1, 40));

    $process = turn_receipt_script($checkout, ['--outcome=approved', '--summary=Checked the feature.', '--pr-summary=Adds exports.', ...$changes, '--pr-breaking=None']);
    $receipt = $receipts->read($instance);

    expect($process->getExitCode())->toBe(0)
        ->and(json_decode((string) file_get_contents($checkout.'/.git/orbit/turn.json'), true))->toBe(['role' => 'reviewer', 'final' => true, 'deliverables' => []])
        ->and($receipt?->outcome)->toBe(TaskTurnOutcome::Approved)
        ->and($receipt?->pullRequest?->summary)->toBe('Adds exports.')
        ->and($receipt?->pullRequest?->changes)->toHaveCount(40)
        ->and($receipt?->pullRequest?->breaking)->toBe([]);
});

it('lets a reviewer request changes on the last subtask without the pull request fields', function (): void {
    $checkout = turn_receipt_checkout();
    $instance = turn_receipt_instance($checkout);
    $receipts = turn_receipts(new LocalShellSshExecutor);
    $receipts->prepare($instance, TaskThreadRole::Reviewer, true);

    $process = turn_receipt_script($checkout, ['--outcome=changes_requested', '--summary=Add the missing test.']);

    expect($process->getExitCode())->toBe(0)
        ->and($receipts->read($instance)?->outcome)->toBe(TaskTurnOutcome::ChangesRequested);
});

/** @return list<TaskDeliverable> */
function turn_receipt_deliverables(): array
{
    return [
        TaskDeliverable::fromArray(['id' => 'reference-page', 'type' => 'file', 'description' => 'Document the export', 'path' => 'docs/reference/tasks.md', 'change' => 'modified']),
        TaskDeliverable::fromArray(['id' => 'export-test', 'type' => 'command', 'description' => 'Test the export', 'command' => 'vendor/bin/pest tests/Feature/ExportTest.php', 'directory' => 'apps/gateway']),
        TaskDeliverable::fromArray(['id' => 'error-copy', 'type' => 'review', 'description' => 'Errors name the subtask']),
    ];
}

describe('deliverable confirmations', function (): void {
    it('writes the deliverables into the turn', function (): void {
        $checkout = turn_receipt_checkout();
        $receipts = turn_receipts(new LocalShellSshExecutor);

        $receipts->prepare(turn_receipt_instance($checkout), TaskThreadRole::Implementer, deliverables: turn_receipt_deliverables());

        expect(json_decode((string) file_get_contents($checkout.'/.git/orbit/turn.json'), true)['deliverables'])->toBe([
            ['id' => 'reference-page', 'type' => 'file', 'description' => 'Document the export'],
            ['id' => 'export-test', 'type' => 'command', 'description' => 'Test the export'],
            ['id' => 'error-copy', 'type' => 'review', 'description' => 'Errors name the subtask'],
        ]);
    });

    it('records the confirmation of every deliverable in a ready_for_review receipt', function (): void {
        $checkout = turn_receipt_checkout();
        $instance = turn_receipt_instance($checkout);
        $receipts = turn_receipts(new LocalShellSshExecutor);
        $receipts->prepare($instance, TaskThreadRole::Implementer, deliverables: turn_receipt_deliverables());

        $process = turn_receipt_script($checkout, [
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
        $checkout = turn_receipt_checkout();
        $instance = turn_receipt_instance($checkout);
        $receipts = turn_receipts(new LocalShellSshExecutor);
        $receipts->prepare($instance, TaskThreadRole::Reviewer, deliverables: turn_receipt_deliverables());

        $process = turn_receipt_script($checkout, ['--outcome=approved', '--summary=Checked.', '--deliverable=error-copy=Read each message in ExportController']);

        expect($process->getExitCode())->toBe(0)
            ->and($receipts->read($instance)?->deliverables)->toBe(['error-copy' => 'Read each message in ExportController']);
    });

    it('refuses confirmations that do not fit the turn', function (TaskThreadRole $role, array $arguments, string $error): void {
        $checkout = turn_receipt_checkout();
        $instance = turn_receipt_instance($checkout);
        $receipts = turn_receipts(new LocalShellSshExecutor);
        $receipts->prepare($instance, $role, deliverables: turn_receipt_deliverables());

        $process = turn_receipt_script($checkout, $arguments);

        expect($process->getExitCode())->toBe(2)
            ->and($process->getErrorOutput())->toBe("orbit turn: {$error}\n")
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
        $checkout = turn_receipt_checkout();
        $instance = turn_receipt_instance($checkout);
        turn_receipts(new LocalShellSshExecutor)->prepare($instance, TaskThreadRole::Implementer);

        $process = turn_receipt_script($checkout, ['--outcome=ready_for_review', '--summary=Done.', '--deliverable=docs=Added']);

        expect($process->getExitCode())->toBe(2)
            ->and($process->getErrorOutput())->toBe("orbit turn: unknown deliverable docs. This subtask's deliverables are: none.\n");
    });
});

it('names the turn command in the prompts and the reminder', function (): void {
    $prompt = TaskPromptRenderer::implementer(new TaskPromptGroup(
        id: 183,
        title: 'One task model',
        brief: 'Replace task groups.',
        projectSlug: 'orbit',
        projectId: 1,
        defaultBranch: 'main',
        taskCheck: null,
        startCommit: null,
    ), new TaskPromptSubtask(
        id: 491,
        title: 'Rename the run receipt',
        brief: 'End each turn with the turn command.',
        position: 4,
        deliverables: [],
    ), 17);
    $reviewer = TaskTurnInstructions::reviewer(threadId: 19);
    $reminder = TaskRubricReminder::compose(TaskThreadRole::Implementer, [
        new TaskRubricItem('turn_receipt', false, 'No turn receipt was found.'),
    ], threadId: 17);

    expect($prompt)->toContain('"$(git rev-parse --git-path orbit)/turn" --thread=17 --outcome=ready_for_review')
        ->and($prompt)->toContain('"$(git rev-parse --git-path orbit)/turn" --thread=17 --outcome=blocked')
        ->and($prompt)->toContain('consult')
        ->and($prompt)->toContain('answered')
        ->and($prompt)->toContain('brief_unclear, contract_gap, scope, environment, or missed_contract')
        ->and($prompt)->not->toContain('.git/orbit/run')
        ->and($reviewer)->toContain('"$(git rev-parse --git-path orbit)/turn" --thread=19 --outcome=approved')
        ->and($reviewer)->toContain('"$(git rev-parse --git-path orbit)/turn" --thread=19 --outcome=changes_requested')
        ->and($reviewer)->not->toContain('This is a consult')
        ->and($reviewer)->not->toContain('--outcome=answered')
        ->and(TaskTurnInstructions::consult(19))->toContain('"$(git rev-parse --git-path orbit)/turn" --thread=19 --outcome=answered')
        ->and(TaskTurnInstructions::consult(19))->toContain('This is a consult, not a review.')
        ->and(TaskTurnInstructions::consult(19))->toContain('brief_unclear, contract_gap, scope, environment, or missed_contract')
        ->and(TaskTurnInstructions::relay(19))->toContain("This is a relay of the operator's direction, not a review.")
        ->and(TaskTurnInstructions::relay(19))->toContain('"$(git rev-parse --git-path orbit)/turn" --thread=19 --outcome=answered')
        ->and($reviewer)->not->toContain('.git/orbit/run')
        ->and($reminder)->toContain('No turn receipt was found.')
        ->and($reminder)->toContain('"$(git rev-parse --git-path orbit)/turn" --thread=17 --outcome=ready_for_review')
        ->and($reminder)->not->toContain('.git/orbit/run');
});

it('writes the review context with the turn file and leaves no partial file', function (): void {
    $checkout = turn_receipt_checkout();
    $instance = turn_receipt_instance($checkout);
    $receipts = turn_receipts(new LocalShellSshExecutor);
    $context = "# Task context\n\n## Task brief\n\nit's \"quoted\" and café GROUP-END\n";

    $receipts->prepare($instance, TaskThreadRole::Reviewer, context: $context);

    $orbit = $checkout.'/.git/orbit';
    expect(is_file($orbit.'/context.md'))->toBeTrue()
        ->and(is_file($orbit.'/context.md.new'))->toBeFalse()
        ->and(is_file($orbit.'/turn.json.new'))->toBeFalse()
        ->and((string) file_get_contents($orbit.'/context.md'))->toBe($context)
        ->and(is_file($orbit.'/turn.json'))->toBeTrue();

    $receipts->prepare($instance, TaskThreadRole::Implementer);

    expect((string) file_get_contents($orbit.'/context.md'))->toBe($context);

    $replacement = "# Task context\n\nreplaced\n";
    $receipts->prepare($instance, TaskThreadRole::Reviewer, context: $replacement);

    expect((string) file_get_contents($orbit.'/context.md'))->toBe($replacement)
        ->and(is_file($orbit.'/context.md.new'))->toBeFalse();
});

it('reports an unreachable workspace when the review context cannot be written', function (): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult(255, '', 'ssh: connect to host 10.44.0.143 port 22: Connection refused', 1, false)]);
    $receipts = turn_receipts($transport);

    expect(fn () => $receipts->prepare(
        turn_receipt_instance('/srv/orbit/apps/orbit/task-13'),
        TaskThreadRole::Reviewer,
        context: "# Task context\n",
    ))->toThrow(TaskTurnReceiptException::class, 'The task workspace could not be reached for the turn receipt.');

    expect($transport->commands[0]->input)->toContain('python3 -I -c')
        ->and($transport->commands[0]->input)->toContain('| workspace_metadata \'turn\'')
        ->and($transport->commands[0]->input)->not->toContain('context.md.new');
});

it('replaces a context path that is a symlink to a directory instead of writing inside it', function (): void {
    $checkout = turn_receipt_checkout();
    $instance = turn_receipt_instance($checkout);
    $receipts = turn_receipts(new LocalShellSshExecutor);
    $orbit = $checkout.'/.git/orbit';
    mkdir($orbit.'/nested', 0755, true);
    file_put_contents($orbit.'/nested/keep', 'keep');
    symlink('nested', $orbit.'/context.md');
    $context = "# Task context\n\nsymlink\n";

    $receipts->prepare($instance, TaskThreadRole::Reviewer, context: $context);

    expect(is_file($orbit.'/context.md'))->toBeTrue()
        ->and(is_link($orbit.'/context.md'))->toBeFalse()
        ->and((string) file_get_contents($orbit.'/context.md'))->toBe($context)
        ->and((string) file_get_contents($orbit.'/nested/keep'))->toBe('keep')
        ->and(glob($orbit.'/nested/*'))->toBe([$orbit.'/nested/keep']);
});
