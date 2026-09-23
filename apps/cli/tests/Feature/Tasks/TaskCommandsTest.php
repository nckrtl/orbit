<?php

declare(strict_types=1);

use App\Commands\Tasks\Concerns\ConfirmsTaskChanges;
use App\Commands\Tasks\TaskCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\CommandPrompts;
use App\Support\Console\ConsoleMode;
use App\Support\Console\PromptAborted;
use App\Support\Console\PromptContext;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Terminal;
use Orbit\Sdk\Requests\Apps\ListAppsRequest;
use Orbit\Sdk\Requests\Tasks\CancelTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\CreateTaskCommentRequest;
use Orbit\Sdk\Requests\Tasks\CreateTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskGroupsRequest;
use Orbit\Sdk\Requests\Tasks\ShowTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\UpdateSubtaskRequest;
use Orbit\Sdk\Requests\Tasks\UpdateTaskGroupRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-tasks-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', '/tmp/test-ca.pem'));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('omitted and invalid input', function (): void {
    it('refuses in machine and noninteractive modes before any request', function (string $command, array $arguments, string $code): void {
        foreach ([['--json' => true], ['--no-interaction' => true]] as $mode) {
            $mock = MockClient::global([]);

            expect(Artisan::call($command, [...$arguments, ...$mode]))->toBe(1);

            if (isset($mode['--json'])) {
                expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe($code);
            } else {
                expect(Artisan::output())->not->toBe('');
            }

            $mock->assertNothingSent();
            MockClient::destroyGlobal();
        }
    })->with([
        'show without group' => ['tasks:show', [], 'tasks.group_required'],
        'show with invalid group' => ['tasks:show', ['group' => 'abc'], 'tasks.group_invalid'],
        'agents without group' => ['tasks:agents', [], 'tasks.group_required'],
        'list with invalid project' => ['tasks:list', ['--project' => '0'], 'tasks.project_invalid'],
        'list with unknown status' => ['tasks:list', ['--status' => 'queued'], 'tasks.status_invalid'],
        'create without project' => ['tasks:create', ['title' => 'T', '--brief' => 'B'], 'tasks.project_required'],
        'create without title' => ['tasks:create', ['--project' => '1', '--brief' => 'B'], 'tasks.title_required'],
        'create without brief' => ['tasks:create', ['title' => 'T', '--project' => '1'], 'tasks.brief_required'],
        'create with a long title' => ['tasks:create', ['title' => str_repeat('x', 161), '--project' => '1', '--brief' => 'B'], 'tasks.title_invalid'],
        'create with a blank brief' => ['tasks:create', ['title' => 'T', '--project' => '1', '--brief' => '   '], 'tasks.brief_invalid'],
        'create with a running status' => ['tasks:create', ['title' => 'T', '--project' => '1', '--brief' => 'B', '--status' => 'running'], 'tasks.status_invalid'],
        'create with a missing subtasks file' => ['tasks:create', ['title' => 'T', '--project' => '1', '--brief' => 'B', '--subtasks' => '/nonexistent/subtasks.json'], 'tasks.subtasks_invalid'],
        'update without a change' => ['tasks:update', ['group' => '1'], 'tasks.update_required'],
        'update to a claimed status' => ['tasks:update', ['group' => '1', '--status' => 'running'], 'tasks.status_invalid'],
        'cancel without consent' => ['tasks:cancel', ['group' => '1'], 'input.confirmation_required'],
        'complete without consent' => ['tasks:complete', ['group' => '1'], 'input.confirmation_required'],
        'subtask create without brief' => ['tasks:subtask:create', ['group' => '1', 'title' => 'T'], 'tasks.brief_required'],
        'subtask update without subtask' => ['tasks:subtask:update', ['group' => '1', '--title' => 'T'], 'tasks.subtask_required'],
        'subtask update without a change' => ['tasks:subtask:update', ['group' => '1', 'subtask' => '2'], 'tasks.update_required'],
        'subtask update with position zero' => ['tasks:subtask:update', ['group' => '1', 'subtask' => '2', '--position' => '0'], 'tasks.position_invalid'],
        'subtask destroy without consent' => ['tasks:subtask:destroy', ['group' => '1', 'subtask' => '2'], 'input.confirmation_required'],
        'comment without type' => ['tasks:comment:create', ['group' => '1', 'subtask' => '2', '--body' => 'B', '--author' => 'nick'], 'tasks.comment_type_required'],
        'comment with an unknown type' => ['tasks:comment:create', ['group' => '1', 'subtask' => '2', '--type' => 'approved', '--body' => 'B', '--author' => 'nick'], 'tasks.comment_type_invalid'],
        'comment without body' => ['tasks:comment:create', ['group' => '1', 'subtask' => '2', '--type' => 'resolution', '--author' => 'nick'], 'tasks.comment_body_required'],
        'comment without author' => ['tasks:comment:create', ['group' => '1', 'subtask' => '2', '--type' => 'resolution', '--body' => 'B'], 'tasks.comment_author_required'],
        'comment with an invalid thread' => ['tasks:comment:create', ['group' => '1', 'subtask' => '2', '--type' => 'resolution', '--body' => 'B', '--author' => 'nick', '--agent-thread' => 'x'], 'tasks.agent_thread_invalid'],
        'comment list without subtask' => ['tasks:comment:list', ['group' => '1'], 'tasks.subtask_required'],
    ]);

    it('refuses a subtasks file that is not an array of titled briefs', function (string $contents): void {
        $path = $this->orbitHome.'/subtasks.json';
        is_dir($this->orbitHome) || mkdir($this->orbitHome, 0700, true);
        file_put_contents($path, $contents);
        $mock = MockClient::global([]);

        expect(Artisan::call('tasks:create', ['title' => 'T', '--project' => '1', '--brief' => 'B', '--subtasks' => $path, '--json' => true]))->toBe(1)
            ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe('tasks.subtasks_invalid');
        $mock->assertNothingSent();
    })->with([
        'not JSON' => ['title: one'],
        'an object' => ['{"title": "One", "brief": "First."}'],
        'an entry without a brief' => ['[{"title": "One"}]'],
        'an empty title' => ['[{"title": "", "brief": "First."}]'],
        'too many entries' => [json_encode(array_fill(0, 51, ['title' => 'T', 'brief' => 'B']), JSON_THROW_ON_ERROR)],
    ]);
});

describe('requests', function (): void {
    it('creates a group with the subtasks file, status, and notification', function (): void {
        $path = $this->orbitHome.'/subtasks.json';
        is_dir($this->orbitHome) || mkdir($this->orbitHome, 0700, true);
        file_put_contents($path, '[{"title": "One", "brief": "First."}, {"title": "Two", "brief": "Second."}]');
        $mock = MockClient::global(gateway_fixture_mock('tasks/tasks-create/created'));

        expect(Artisan::call('tasks:create', ['title' => 'Add the tasks CLI', '--project' => '1', '--brief' => 'Brief', '--status' => 'todo', '--subtasks' => $path, '--notify-coder' => true, '--json' => true]))->toBe(0);

        $mock->assertSent(static fn (Request $request): bool => $request instanceof CreateTaskGroupRequest
            && (string) $request->body() === '{"app_id":1,"title":"Add the tasks CLI","brief":"Brief","status":"todo","notify_coder":true,"tasks":[{"title":"One","brief":"First."},{"title":"Two","brief":"Second."}]}');
    });

    it('omits the status and notification that the caller did not supply', function (): void {
        $mock = MockClient::global(gateway_fixture_mock('tasks/tasks-create/created'));

        expect(Artisan::call('tasks:create', ['title' => 'Add the tasks CLI', '--project' => '1', '--brief' => 'Brief', '--json' => true]))->toBe(0);

        $mock->assertSent(static fn (Request $request): bool => $request instanceof CreateTaskGroupRequest
            && (string) $request->body() === '{"app_id":1,"title":"Add the tasks CLI","brief":"Brief"}');
    });

    it('sends only the supplied update fields', function (): void {
        $mock = MockClient::global([
            ...gateway_fixture_mock('tasks/tasks-update/updated'),
            ...gateway_fixture_mock('tasks/tasks-subtask-update/updated'),
        ]);

        expect(Artisan::call('tasks:update', ['group' => '13', '--status' => 'todo', '--json' => true]))->toBe(0)
            ->and(Artisan::call('tasks:subtask:update', ['group' => '13', 'subtask' => '57', '--position' => '3', '--json' => true]))->toBe(0);

        $mock->assertSent(static fn (Request $request): bool => $request instanceof UpdateTaskGroupRequest
            && $request->resolveEndpoint() === '/api/v1/task-groups/13'
            && (string) $request->body() === '{"status":"todo"}');
        $mock->assertSent(static fn (Request $request): bool => $request instanceof UpdateSubtaskRequest
            && $request->resolveEndpoint() === '/api/v1/task-groups/13/tasks/57'
            && (string) $request->body() === '{"position":3}');
    });

    it('filters the list and sends the comment thread', function (): void {
        $mock = MockClient::global([
            ...gateway_fixture_mock('tasks/tasks-list/default'),
            ...gateway_fixture_mock('tasks/tasks-comment-create/created'),
        ]);

        expect(Artisan::call('tasks:list', ['--project' => '4', '--status' => 'settling', '--json' => true]))->toBe(0)
            ->and(Artisan::call('tasks:comment:create', ['group' => '1', 'subtask' => '2', '--type' => 'assistance_requested', '--body' => 'Stuck.', '--author' => 'nick', '--agent-thread' => '9', '--json' => true]))->toBe(0);

        $mock->assertSent(static fn (Request $request): bool => $request instanceof ListTaskGroupsRequest
            && $request->query()->all() === ['app_id' => 4, 'status' => 'settling']);
        $mock->assertSent(static fn (Request $request): bool => $request instanceof CreateTaskCommentRequest
            && (string) $request->body() === '{"type":"assistance_requested","body":"Stuck.","author":"nick","agent_thread_id":9}');
    });

    it('cancels with --yes without reading the group first', function (): void {
        $mock = MockClient::global(gateway_fixture_mock('tasks/tasks-cancel/cancelled'));

        expect(Artisan::call('tasks:cancel', ['group' => '1', '--yes' => true, '--json' => true]))->toBe(0);

        $mock->assertSent(CancelTaskGroupRequest::class);
        $mock->assertNotSent(ShowTaskGroupRequest::class);
    });
});

describe('prompts', function (): void {
    it('selects a group by its stable ID from the offered statuses', function (): void {
        $mock = task_prompt_mock();
        [$status, $display] = task_prompt_run('select-backlog', [Key::DOWN, Key::ENTER]);

        expect($status)->toBe(0)
            ->and($display)->toContain('selected 3')
            ->not->toContain('Settling feature');
        $mock->assertSent(static fn (Request $request): bool => $request instanceof ListTaskGroupsRequest
            && $request->query()->all() === ['status' => 'backlog']);
    });

    it('leaves out excluded statuses and stops on an empty list', function (): void {
        task_prompt_mock();
        [$status, $display] = task_prompt_run('select-settling', []);

        expect($status)->toBe(1)
            ->and($display)->toContain('No matching records were found.');
    });

    it('selects a Project and asks for a title again until it is valid', function (): void {
        $mock = task_prompt_mock();
        [$status, $display] = task_prompt_run('create-inputs', [
            Key::DOWN, Key::ENTER,
            Key::ENTER, 'F', 'e', 'a', 't', Key::ENTER,
            'B', 'r', 'i', 'e', 'f', Key::CTRL_D,
        ]);

        expect($status)->toBe(0)
            ->and($display)->toContain('Title is required.')
            ->and($display)->toContain('project 42 · Feat · Brief');
        $mock->assertSent(ListAppsRequest::class);
    });

    it('asks which fields change', function (): void {
        task_prompt_mock();
        [$status, $display] = task_prompt_run('fields', [Key::DOWN, Key::DOWN, Key::SPACE, Key::ENTER]);

        expect($status)->toBe(0)->and($display)->toContain('fields status');
    });

    it('defaults consent to No and accepts an explicit yes', function (array $keys, int $expected, string $text): void {
        task_prompt_mock();
        [$status, $display] = task_prompt_run('consent', $keys);

        expect($status)->toBe($expected)
            ->and($display)->toContain('Cancel task group ORB-1 (Feature)?')
            ->and($display)->toContain($text);
    })->with([
        'default' => [[Key::ENTER], 1, 'was not cancelled'],
        'yes' => [['y', Key::ENTER], 0, 'consented'],
    ]);

    it('stops before mutation when a selection is cancelled', function (): void {
        $mock = task_prompt_mock();
        [$status] = task_prompt_run('select-backlog', [Key::CTRL_C]);

        expect($status)->not->toBe(0);
        $mock->assertNotSent(CancelTaskGroupRequest::class);
    });
});

function task_prompt_mock(): MockClient
{
    $meta = ['request_id' => '11111111-1111-4111-8111-111111111111'];
    $group = static fn (int $id, string $title, string $status): array => [
        'id' => $id, 'app_id' => 1, 'app' => 'orbit', 'project_code' => 'ORB', 'title' => $title, 'brief' => 'Brief', 'status' => $status, 'tasks' => [],
    ];

    return MockClient::global([
        ListTaskGroupsRequest::class => static fn ($pending): MockResponse => MockResponse::make(['data' => array_values(array_filter(
            [$group(1, 'Backlog feature', 'backlog'), $group(3, 'Second backlog feature', 'backlog'), $group(5, 'Settling feature', 'settling')],
            static fn (array $row): bool => ! isset($pending->getRequest()->query()->all()['status']) || $row['status'] === $pending->getRequest()->query()->all()['status'],
        )), 'meta' => $meta]),
        ShowTaskGroupRequest::class => MockResponse::make(['data' => $group(1, 'Feature', 'backlog'), 'meta' => $meta]),
        ListAppsRequest::class => MockResponse::make(['data' => [
            ['id' => 7, 'name' => 'Alpha', 'slug' => 'alpha', 'type' => 'monorepo', 'repository_url' => 'https://example.test/alpha.git'],
            ['id' => 42, 'name' => 'Beta', 'slug' => 'beta', 'type' => 'monorepo', 'repository_url' => 'https://example.test/beta.git'],
        ], 'meta' => $meta]),
    ]);
}

/**
 * @param  list<string>  $keys
 * @return array{int, string}
 */
function task_prompt_run(string $scenario, array $keys): array
{
    $command = new TaskPromptsCommand(new TaskPromptsTerminal($keys));
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $status = $tester->execute(['--scenario' => $scenario]);

    return [$status, $tester->getDisplay()];
}

/** Drives the shared tasks prompt helpers through a scripted terminal. */
final class TaskPromptsCommand extends TaskCommand
{
    use ConfirmsTaskChanges;

    protected $signature = 'test:task-prompts {--scenario=} {--yes} {--json}';

    public function __construct(private readonly Terminal $terminal)
    {
        parent::__construct();
    }

    protected function consoleMode(?OutputInterface $output = null): ConsoleMode
    {
        return new ConsoleMode(false, true, false, false, 100);
    }

    protected function commandPrompts(): CommandPrompts
    {
        return new CommandPrompts($this->consoleMode(), $this->output, $this->terminal);
    }

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        return PromptContext::preserve(function () use ($repository, $factory): int {
            Prompt::fallbackWhen(false);
            $connector = $this->gatewayConnector($repository, $factory);
            assert($connector !== null);

            switch ($this->option('scenario')) {
                case 'select-backlog':
                    $id = $this->selectGroup($connector, ['backlog']);
                    $this->line("selected {$id}");

                    return self::SUCCESS;
                case 'select-settling':
                    $id = $this->selectGroup($connector, excluded: ['backlog', 'settling']);
                    $this->line("selected {$id}");

                    return self::SUCCESS;
                case 'create-inputs':
                    $project = $this->selectProject($connector);
                    $title = $this->promptText('Title', self::TITLE_MAX);
                    $brief = $this->promptText('Brief', self::BRIEF_MAX, multiline: true);
                    $this->line("project {$project} · {$title} · {$brief}");

                    return self::SUCCESS;
                case 'fields':
                    $this->line('fields '.implode(',', $this->promptFields(['title' => 'Title', 'brief' => 'Brief', 'status' => 'Status'])));

                    return self::SUCCESS;
                default:
                    if (! $this->consent(fn (): string => 'Cancel task group '.$this->loadGroup($connector, 1)?->reference().' (Feature)?', 'Task group [1] was not cancelled.')) {
                        return self::FAILURE;
                    }

                    $this->line('consented');

                    return self::SUCCESS;
            }
        });
    }
}

final class TaskPromptsTerminal extends Terminal
{
    /** @param list<string> $keys */
    public function __construct(private array $keys)
    {
        parent::__construct();
    }

    public function read(): string
    {
        return array_shift($this->keys) ?? throw new PromptAborted('Input ended.');
    }

    public function setTty(string $mode): void {}

    public function restoreTty(): void {}

    public function cols(): int
    {
        return 100;
    }

    public function lines(): int
    {
        return 40;
    }
}
