<?php

declare(strict_types=1);

/** Run only through Gateway Tinker on TASK-753's disposable Incus lease. */

use App\Domain\Tasks\AgentDriver;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentInputRequest;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadStart;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\QuestionStatus;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskQuestion;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

function proof_require(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function proof_save(string $name, mixed $data): void
{
    $body = is_string($data) ? $data : json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    proof_require(file_put_contents('/home/orbit/task-760-proof/'.$name, $body) !== false, 'Cannot save evidence.');
    echo "=== {$name}\n{$body}\n";
}

/** A closed script of turns; all receipts are written by the real installed turn command over SSH. */
final class ConsultProofDriver implements AgentDriver
{
    public array $calls = [];

    private array $observations = [];

    private int $reviewerTurns = 0;

    public function __construct(private readonly Task $group, private readonly Instance $workspace) {}

    public function key(): string
    {
        return 'scripted-task-760';
    }

    public function allows(Node $node): bool
    {
        return $node->id === $this->workspace->node_id;
    }

    public function remote(array $argv, ?string $input = null): string
    {
        return app(DevelopmentSshExecutor::class)->execute(
            $this->workspace->node,
            new RemoteCommand(arguments: $argv, input: $input),
            'task-760-proof', 'tasks.proof_failed',
        )->stdout;
    }

    public function create(AgentThreadStart $intent): string
    {
        proof_require($intent->workspace->id === $this->workspace->id && $this->allows($intent->node), 'Unexpected workspace or Node.');
        $thread = AgentThread::query()->where('task_group_id', $this->group->id)->where('role', $intent->role->value)->sole();
        $external = $intent->externalId ?? 'task-760-'.$thread->id;
        $this->calls[] = ['operation' => 'create', 'thread' => $thread->id, 'external_id' => $external, 'prompt' => $intent->prompt];
        $this->observations[$thread->id] = new AgentObservation(AgentThreadState::Working);
        if (! $intent->deferOpeningTurn) {
            proof_require($intent->role === TaskThreadRole::Implementer, 'Unexpected non-deferred reviewer creation.');
            $this->block($thread, false);
        }

        return $external;
    }

    public function block(AgentThread $thread, bool $second): void
    {
        $this->receipt($thread, 'blocked', $second ? 'The brief does not choose a fixture database.' : 'I missed the receipt cause in the contract.',
            $second ? 'Which database should the disposable fixture use?' : 'Which cause applies when the contract already answers my question?');
    }

    public function send(AgentThread $thread, string $message, ?string $key = null): void
    {
        proof_require($thread->task_group_id === $this->group->id && $thread->node_id === $this->workspace->node_id, 'Foreign thread.');
        $this->calls[] = ['operation' => 'send', 'thread' => $thread->id, 'role' => $thread->role, 'key' => $key, 'message' => $message];
        if ($thread->role === TaskThreadRole::Reviewer->value) {
            $this->reviewerTurns++;
            match ($this->reviewerTurns) {
                1 => $this->receipt($thread, 'answered', 'Use missed_contract. ADR 0187 defines it for a question already answered by the contract.', cause: 'missed_contract'),
                2 => $this->receipt($thread, 'blocked', 'The contract cannot choose an operator preference.', 'Which database should the disposable fixture use?', 'contract_gap'),
                3 => $this->receipt($thread, 'answered', 'The operator chose SQLite. Use SQLite only for this disposable fixture.', cause: 'contract_gap'),
                default => throw new AgentDriverException('Unexpected reviewer turn.'),
            };
        } else {
            proof_require($thread->role === TaskThreadRole::Implementer->value, 'Unexpected role.');
            $this->remote(['bash', '-seu', '--', $this->workspace->checkout_path, $message], 'printf "%s\n" "$2" >> "$1/continued.txt"');
            $this->observations[$thread->id] = new AgentObservation(AgentThreadState::Working, turnId: $key, sessionUpdatedAt: now()->toIso8601String());
        }
    }

    private function receipt(AgentThread $thread, string $outcome, string $summary, ?string $question = null, ?string $cause = null): void
    {
        $arguments = ['--thread='.$thread->id, '--outcome='.$outcome, '--summary='.$summary];
        if ($question !== null) {
            $arguments[] = '--question='.$question;
        }
        if ($cause !== null) {
            $arguments[] = '--cause='.$cause;
        }
        $output = $this->remote(['bash', '-seu', '--', $this->workspace->checkout_path, ...$arguments], 'cd -- "$1"; shift; .git/orbit/turn "$@"');
        $this->calls[] = ['operation' => 'receipt', 'thread' => $thread->id, 'arguments' => $arguments, 'output' => $output];
        $this->observations[$thread->id] = new AgentObservation(AgentThreadState::Done, turnId: 'task-760-turn-'.count($this->calls), sessionUpdatedAt: now()->toIso8601String());
    }

    public function observe(AgentThread $thread): AgentObservation
    {
        return $this->observations[$thread->id] ?? throw new AgentDriverException('Unknown scripted thread.');
    }

    public function respond(AgentThread $thread, AgentInputRequest $request, array $answers): void
    {
        throw AgentDriverException::unsupported('scripted input requests');
    }

    public function interrupt(AgentThread $thread): void
    {
        throw AgentDriverException::unsupported('scripted interruptions');
    }

    public function archive(AgentThread $thread, string $commandId): void
    {
        throw AgentDriverException::unsupported('scripted archive');
    }

    public function events(AgentThread $thread, ?string $cursor, ?float $timeoutSeconds = null): iterable
    {
        return [];
    }
}

function proof_cli(array $arguments): string
{
    $process = proc_open(['orbit', ...$arguments], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    proof_require(is_resource($process), 'CLI did not start.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proof_require(proc_close($process) === 0, 'CLI failed: '.$stderr.$stdout);

    return $stdout;
}

/** Serve the CLI's resolution through the real HTTP kernel in this same scripted-driver process. */
function proof_resolution(Task $group, Task $task): void
{
    $context = stream_context_create(['ssl' => ['local_cert' => '/home/orbit/task-760-proof/tls.pem', 'verify_peer' => false]]);
    $server = stream_socket_server('tls://10.44.0.1:18760', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
    proof_require(is_resource($server), 'Cannot bind fixture TLS listener: '.$error);
    $process = null;
    try {
        $argv = ['env', 'ORBIT_HOME=/home/orbit/task-760-proof/cli', 'orbit', 'tasks:comment:create', (string) $group->id, (string) $task->id,
            '--type=resolution', '--body=Use SQLite only for the disposable TASK-753 fixture.', '--author=TASK-753 fixture operator', '--json'];
        $process = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        proof_require(is_resource($process), 'Resolution CLI did not start.');
        $served = [];
        for ($requestNumber = 0; $requestNumber < 2; $requestNumber++) {
            $connection = stream_socket_accept($server, 10, $peer);
            proof_require(is_resource($connection) && str_starts_with($peer, '10.44.0.1:'), 'Resolution must come from the actual Gateway peer.');
            stream_set_timeout($connection, 10);
            [$method, $uri, $protocol] = explode(' ', trim((string) fgets($connection)));
            $expected = $requestNumber === 0 ? ['GET', '/api/v1/extensions'] : ['POST', '/api/v1/task-groups/'.$group->id.'/tasks/'.$task->id.'/comments'];
            proof_require([$method, $uri] === $expected && $protocol === 'HTTP/1.1', 'Unexpected CLI request.');
            $headers = [];
            while (($line = fgets($connection)) !== false && trim($line) !== '') {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower($name)] = trim($value);
            }
            $length = (int) ($headers['content-length'] ?? 0);
            proof_require($length >= 0 && $length < 8192 && ($method === 'GET' || $length > 0), 'Invalid resolution body length.');
            $body = '';
            while (strlen($body) < $length) {
                $chunk = fread($connection, $length - strlen($body));
                proof_require(is_string($chunk) && $chunk !== '', 'Incomplete resolution body.');
                $body .= $chunk;
            }
            $serverFields = ['REMOTE_ADDR' => substr($peer, 0, strrpos($peer, ':')), 'HTTPS' => 'on', 'SERVER_PORT' => '18760', 'CONTENT_TYPE' => $headers['content-type'] ?? ''];
            foreach ($headers as $name => $value) {
                $serverFields['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
            }
            $request = Request::create('https://10.44.0.1:18760'.$uri, $method, server: $serverFields, content: $body);
            $kernel = app(Kernel::class);
            $response = $kernel->handle($request);
            $content = $response->getContent();
            fwrite($connection, "HTTP/1.1 ".$response->getStatusCode()." OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($content)."\r\nConnection: close\r\n\r\n".$content);
            fclose($connection);
            $kernel->terminate($request, $response);
            $served[] = ['method' => $method, 'uri' => $uri, 'peer' => $peer, 'status' => $response->getStatusCode()];
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $process = null;
        proof_save('operator-resolution.json', ['argv' => $argv, 'requests' => $served, 'http_status' => $response->getStatusCode(), 'exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr]);
        proof_require($response->getStatusCode() === 201 && $exit === 0, 'Resolution request failed.');
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        fclose($server);
    }
}

function proof_run(): void
{
    proof_require(config('database.connections.sqlite.database') === '/home/orbit/.orbit/gateway.sqlite', 'Not the leased Gateway database.');
    $owner = json_decode(file_get_contents('/home/orbit/task-760-proof/owner.json'), true, flags: JSON_THROW_ON_ERROR);
    proof_require(($owner['issue'] ?? '') === 'TASK-753' && ($owner['subtask'] ?? 0) === 760 && ($owner['lease'] ?? '') === '0fe465d650e58420fa3ee73124991aea', 'Missing exact lease ownership marker.');
    proof_require(Task::withoutGlobalScopes()->count() === 0, 'Refuse to run alongside another task.');
    proof_require(! Project::query()->where('slug', 'task-760-proof')->exists(), 'Fixture project already exists.');
    $extension = app(TaskExtensionState::class);
    proof_require(! $extension->enabled(), 'Expected disabled extension; do not change another owner\'s extension state.');
    $before = ['projects' => Project::query()->count(), 'instances' => Instance::query()->count(), 'tasks' => Task::withoutGlobalScopes()->count(), 'questions' => TaskQuestion::query()->count(), 'threads' => AgentThread::query()->count()];
    $project = $instance = $group = null;
    $remoteCreated = false;
    $node = Node::query()->where('name', 'app-dev')->where('wireguard_ip', '10.44.0.2')->sole();
    $path = '/home/orbit/task-760-workspace';
    try {
        $project = Project::query()->create(['name' => 'TASK-760 proof', 'slug' => 'task-760-proof', 'type' => 'monorepo', 'repository_url' => 'https://example.invalid/task-760.git', 'default_branch' => 'main']);
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-760-proof', 'checkout_path' => $path, 'status' => 'source_resolved']);
        $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'TASK-760 consult and direction proof', 'brief' => 'Consult ADR 0187 for cause names. Database preference needs operator direction. All resources are disposable.', 'status' => TaskGroupStatus::Running, 'started_at' => now(), 'implementer_agent_driver' => 'scripted-task-760', 'reviewer_agent_driver' => 'scripted-task-760', 'notify_coder' => true]);
        $group->taskable()->associate($instance);
        $group->save();
        $task = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Ask two fixture questions', 'brief' => $group->brief, 'status' => TaskStatus::Running, 'deliverables' => [['id' => 'fixture', 'type' => 'review', 'description' => 'Disposable proof only']]]);
        $driver = new ConsultProofDriver($group, $instance);
        $driver->remote(['bash', '-seu', '--', $path, (string) $group->id], <<<'BASH'
            test ! -e "$1"
            mkdir -m 0700 -- "$1"
            cd -- "$1"
            git init -q -b main
            git config user.email proof@example.invalid
            git config user.name 'TASK-760 fixture'
            printf 'TASK-753 subtask-760\n' > .fixture-owner
            printf '{"scripts":{"check":"true"}}\n' > composer.json
            printf '/vendor/\n' > .gitignore
            composer install --no-interaction --no-progress --quiet
            git add .fixture-owner composer.json composer.lock .gitignore
            git commit -qm 'Disposable proof baseline'
            git checkout -qb "task-$2"
            BASH);
        $remoteCreated = true;
        $instance->update(['branch' => 'task-'.$group->id, 'starting_commit' => trim($driver->remote(['git', '-C', $path, 'rev-parse', 'HEAD']))]);
        app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
        config(['orbit.tasks.coder_webhook_url' => 'http://127.0.0.1:18761', 'orbit.tasks.coder_webhook_secret' => 'task-760-disposable-webhook-secret']);
        $extension->enable();
        $scheduler = app(TaskScheduler::class);
        for ($tick = 0; $tick < 30; $tick++) {
            $scheduler->tick();
            if (AgentThread::query()->where('task_group_id', $group->id)->where('role', 'implementer')->exists()) {
                break;
            }
            proof_require(! $group->fresh()->assistance_requested, 'Baseline requested assistance.');
            usleep(250000);
        }
        proof_require(AgentThread::query()->where('task_group_id', $group->id)->where('role', 'implementer')->count() === 1, 'Implementer not started after baseline.');
        proof_save('baseline.json', TaskCheck::query()->where('task_id', $task->id)->get()->toArray());
        $scheduler->tick();
        proof_require(TaskQuestion::query()->where('task_id', $group->id)->sole()->status === QuestionStatus::Open, 'Consult not open.');
        $scheduler->tick();
        $task->refresh();
        $first = TaskQuestion::query()->where('task_id', $group->id)->sole();
        proof_require($first->status === QuestionStatus::Answered && $first->answered_by->value === 'reviewer' && $first->cause->value === 'missed_contract', 'Consult not answered by reviewer with cause.');
        proof_require($task->completion_attempt === 1 && ! $task->assistance_requested && $task->consult_comment_id === null && $task->status === TaskStatus::Running, 'Consult changed attempt or requested assistance.');
        proof_save('consult.json', ['task_id' => $group->id, 'subtask_id' => $task->id, 'completion_attempt' => $task->completion_attempt, 'question' => $first->toArray(), 'continuation' => $driver->remote(['cat', $path.'/continued.txt']), 'calls' => $driver->calls]);
        $implementer = AgentThread::query()->findOrFail($task->implementer_agent_thread_id);
        $driver->block($implementer, true);
        $scheduler->tick();
        $scheduler->tick();
        $task->refresh();
        proof_require($task->assistance_requested && $task->assistance_kind->value === 'direction', 'Second consult did not escalate.');
        proof_require(TaskQuestion::query()->where('task_id', $group->id)->count() === 2, 'Escalation created a duplicate record.');
        proof_save('direction-status.txt', proof_cli(['tasks:status', '--no-ansi', '--no-interaction']));
        proof_require(str_contains(file_get_contents('/home/orbit/task-760-proof/direction-status.txt'), 'Needs your direction'), 'Human status label missing.');
        proof_save('direction-status.json', proof_cli(['tasks:status', '--json']));
        proof_save('direction-questions.json', proof_cli(['tasks:question:list', '--project='.$project->id, '--json']));
        proof_resolution($group, $task);
        $task->refresh();
        proof_require(! $task->assistance_requested && $task->direction_relay_comment_id !== null, 'Resolution was not delivered to reviewer.');
        $scheduler->tick();
        $task->refresh();
        $questions = TaskQuestion::query()->where('task_id', $group->id)->orderBy('id')->get();
        proof_require($questions->count() === 2 && $questions[1]->status === QuestionStatus::Answered && $questions[1]->answered_by->value === 'operator' && $questions[1]->escalated_at !== null && $questions[1]->cause->value === 'contract_gap', 'Operator answer record missing.');
        proof_require($task->completion_attempt === 1 && $task->direction_relay_comment_id === null && ! $task->assistance_requested, 'Relay did not finish in the same attempt.');
        proof_save('relay.json', ['completion_attempt' => $task->completion_attempt, 'reviewer_id' => $group->fresh()->reviewer_agent_thread_id, 'questions' => $questions->toArray(), 'continuation' => $driver->remote(['cat', $path.'/continued.txt']), 'calls' => $driver->calls]);
        proof_save('question-list.txt', proof_cli(['tasks:question:list', '--project='.$project->id, '--no-ansi', '--no-interaction']));
        proof_save('question-list.json', proof_cli(['tasks:question:list', '--project='.$project->id, '--json']));
        $task->update(['status' => TaskStatus::Completed, 'settled_at' => now()]);
        $group->update(['status' => TaskGroupStatus::Settling, 'pr_url' => 'https://example.invalid/task-760-proof']);
        $settled = $scheduler->settle($group->fresh());
        proof_require($settled->questions === 2 && $settled->escalations === 1 && $task->fresh()->questions === 2 && $task->fresh()->escalations === 1, 'Settle counts do not match both records.');
        proof_save('settle-metrics.json', ['task_id' => $group->id, 'status' => $settled->status->value, 'questions' => $settled->questions, 'escalations' => $settled->escalations, 'subtask_questions' => $task->fresh()->questions, 'subtask_escalations' => $task->fresh()->escalations, 'settled_at' => $settled->settled_at->toIso8601String(), 'tokens' => $settled->tokens, 'line_diff' => $settled->line_diff]);
        $events = array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file('/home/orbit/task-760-proof/webhooks.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        proof_require(count($events) === 2 && $events[0]['signature_valid'] && $events[1]['signature_valid'], 'Expected exactly two signed webhooks.');
        proof_require($events[0]['body']['event'] === 'task_group.assistance_requested' && $events[0]['body']['kind'] === 'direction' && $events[0]['body']['question'] === 'Which database should the disposable fixture use?', 'Direction webhook fields missing.');
        proof_require($events[1]['body']['event'] === 'task_group.settled' && $events[1]['body']['questions'] === 2 && $events[1]['body']['escalations'] === 1, 'Settle webhook counts missing.');
        proof_save('webhook-evidence.json', $events);
        proof_save('result.json', ['passed' => true, 'task_id' => $group->id, 'subtask_id' => $task->id, 'project_id' => $project->id, 'limitation' => 'Agent turns came from a scripted AgentDriver bound in-process; no live Pi or T3 model ran. Gateway feature tests cover the drivers.']);
    } catch (Throwable $exception) {
        proof_save('failure.json', ['error' => $exception->getMessage(), 'group' => $group?->fresh()?->toArray(), 'tasks' => $group?->tasks()->get()->toArray(), 'checks' => isset($task) ? TaskCheck::query()->where('task_id', $task->id)->get()->toArray() : [], 'driver_calls' => isset($driver) ? $driver->calls : []]);
        throw $exception;
    } finally {
        if ($instance instanceof Instance && isset($task)) {
            foreach (TaskCheck::query()->where('task_id', $task->id)->where('status', TaskCheckStatus::Running)->get() as $check) {
                if ($check->pid > 0) {
                    app(TaskCheckRunner::class)->cancel($instance, $check->process());
                }
            }
        }
        if ($remoteCreated && $instance instanceof Instance) {
            app(DevelopmentSshExecutor::class)->execute($node, new RemoteCommand(arguments: ['bash', '-seu', '--', $path], input: 'test "$1" = /home/orbit/task-760-workspace; test "$(cat "$1/.fixture-owner")" = "TASK-753 subtask-760"; rm -rf -- "$1"; test ! -e "$1"'), 'task-760-cleanup', 'tasks.proof_cleanup_failed');
        }
        if ($group instanceof Task) {
            proof_require($group->project_id === $project?->id && $group->title === 'TASK-760 consult and direction proof', 'Cleanup ownership mismatch.');
            $group->delete();
        }
        $instance?->delete();
        $project?->delete();
        $extension->disable();
        $after = ['projects' => Project::query()->count(), 'instances' => Instance::query()->count(), 'tasks' => Task::withoutGlobalScopes()->count(), 'questions' => TaskQuestion::query()->count(), 'threads' => AgentThread::query()->count()];
        proof_save('cleanup.json', ['before' => $before, 'after' => $after, 'extension_enabled' => $extension->enabled(), 'foreign_key_violations' => DB::select('PRAGMA foreign_key_check')]);
        proof_require($before === $after && ! $extension->enabled() && DB::select('PRAGMA foreign_key_check') === [], 'Fixture database leftovers.');
    }
}

try {
    proof_run();
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
