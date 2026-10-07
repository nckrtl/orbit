<?php

declare(strict_types=1);

use App\Commands\Tasks\Concerns\ConfirmsTaskChanges;
use App\Commands\Tasks\TaskCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\Extensions\GatewayExtensionState;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\CommandPrompts;
use App\Support\Console\ConsoleMode;
use App\Support\Console\PromptAborted;
use App\Support\Console\PromptContext;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Terminal;
use Orbit\Sdk\Requests\Projects\ListProjectsRequest;
use Orbit\Sdk\Requests\Tasks\CancelSubtaskRequest;
use Orbit\Sdk\Requests\Tasks\CancelTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\CreateSubtaskRequest;
use Orbit\Sdk\Requests\Tasks\CreateTaskCommentRequest;
use Orbit\Sdk\Requests\Tasks\CreateTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskGroupsRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskQuestionsRequest;
use Orbit\Sdk\Requests\Tasks\ShowTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\ShowTasksStatusRequest;
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
    app()->forgetInstance(GatewayConfigRepository::class);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', '/tmp/test-ca.pem'));

    MockClient::global(gateway_fixture_mock());
    app(GatewayExtensionState::class)->discover();
    MockClient::destroyGlobal();
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
});

describe('omitted and invalid input', function (): void {
    it('refuses in machine and noninteractive modes before any request', function (string $command, array $arguments, string $code): void {
        foreach ([['--json' => true], ['--no-interaction' => true]] as $mode) {
            $mock = MockClient::global([]);

            expect(Artisan::call($command, [...$arguments, ...$mode]))->toBe(1);

            if (isset($mode['--json'])) {
                expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe($code);
            } else {
                $output = Artisan::output();

                expect($output)->not->toBe('');

                if ($code === 'tasks.since_invalid') {
                    expect($output)->toContain('Since must be an ISO 8601 date or time.');
                }
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
        'questions with invalid project' => ['tasks:question:list', ['--project' => '0'], 'tasks.project_invalid'],
        'questions with unknown cause' => ['tasks:question:list', ['--cause' => 'nope'], 'tasks.cause_invalid'],
        'questions with unknown status' => ['tasks:question:list', ['--status' => 'queued'], 'tasks.status_invalid'],
        'questions with a relative since' => ['tasks:question:list', ['--since' => 'yesterday'], 'tasks.since_invalid'],
        'questions with an impossible date' => ['tasks:question:list', ['--since' => '2026-02-31'], 'tasks.since_invalid'],
        'questions with an offset minute of 99' => ['tasks:question:list', ['--since' => '2026-10-07T12:00:00+01:99'], 'tasks.since_invalid'],
        'questions with an offset hour of 99' => ['tasks:question:list', ['--since' => '2026-10-07T12:00:00+99:00'], 'tasks.since_invalid'],
        'questions with a fractional minute of 60' => ['tasks:question:list', ['--since' => '2026-10-07T12:60.5Z'], 'tasks.since_invalid'],
        'questions with a fractional minute of 99' => ['tasks:question:list', ['--since' => '2026-10-07T12:99.5Z'], 'tasks.since_invalid'],
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
        'subtask cancel without consent' => ['tasks:subtask:cancel', ['group' => '1', 'subtask' => '2'], 'input.confirmation_required'],
        'subtask cancel with an invalid subtask' => ['tasks:subtask:cancel', ['group' => '1', 'subtask' => 'x', '--yes' => true], 'tasks.subtask_invalid'],
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
            && (string) $request->body() === '{"project_id":1,"title":"Add the tasks CLI","brief":"Brief","status":"todo","notify_coder":true,"tasks":[{"title":"One","brief":"First."},{"title":"Two","brief":"Second."}]}');
    });

    it('omits the status and notification that the caller did not supply', function (): void {
        $mock = MockClient::global(gateway_fixture_mock('tasks/tasks-create/created'));

        expect(Artisan::call('tasks:create', ['title' => 'Add the tasks CLI', '--project' => '1', '--brief' => 'Brief', '--json' => true]))->toBe(0);

        $mock->assertSent(static fn (Request $request): bool => $request instanceof CreateTaskGroupRequest
            && (string) $request->body() === '{"project_id":1,"title":"Add the tasks CLI","brief":"Brief"}');
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
            && $request->query()->all() === ['project_id' => 4, 'status' => 'settling']);
        $mock->assertSent(static fn (Request $request): bool => $request instanceof CreateTaskCommentRequest
            && (string) $request->body() === '{"type":"assistance_requested","body":"Stuck.","author":"nick","agent_thread_id":9}');
    });

    it('accepts the VM review wait as a list filter', function (): void {
        $mock = MockClient::global(gateway_fixture_mock('tasks/tasks-list/default'));

        expect(Artisan::call('tasks:list', ['--status' => 'waiting_for_review', '--json' => true]))->toBe(0);
        $mock->assertSent(static fn (Request $request): bool => $request instanceof ListTaskGroupsRequest
            && $request->query()->all() === ['status' => 'waiting_for_review']);
    });

    it('filters the question list and renders an empty list', function (): void {
        $mock = MockClient::global(gateway_fixture_mock('tasks/tasks-question-list/default'));

        expect(Artisan::call('tasks:question:list', [
            '--project' => '4',
            '--cause' => 'contract_gap',
            '--status' => 'answered',
            '--since' => '2026-10-07T00:00:00Z',
            '--json' => true,
        ]))->toBe(0);

        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($json['questions'])->toHaveCount(2)
            ->and($json['questions'][0]['cause'])->toBe('contract_gap')
            ->and($json['questions'][1]['answer'])->toBeNull();
        $mock->assertSent(static fn (Request $request): bool => $request instanceof ListTaskQuestionsRequest
            && $request->query()->all() === [
                'project_id' => 4,
                'cause' => 'contract_gap',
                'status' => 'answered',
                'since' => '2026-10-07T00:00:00Z',
            ]);

        MockClient::destroyGlobal();
        MockClient::global(gateway_fixture_mock('tasks/tasks-question-list/empty'));

        expect(Artisan::call('tasks:question:list'))->toBe(0);
        expect(Artisan::output())->toContain('No questions match.');
    });

    it('sends a reduced-precision since filter', function (string $since): void {
        $mock = MockClient::global(gateway_fixture_mock('tasks/tasks-question-list/empty'));

        expect(Artisan::call('tasks:question:list', ['--since' => $since, '--json' => true]))->toBe(0);

        $mock->assertSent(static fn (Request $request): bool => $request instanceof ListTaskQuestionsRequest
            && $request->query()->all() === ['since' => $since]);
    })->with([
        'minutes' => '2026-10-07T12:00Z',
        'hours' => '2026-10-07T12Z',
        'fractional seconds' => '2026-10-07T12:00:00.5Z',
        'offset' => '2026-10-07T12:00:00+01:00',
    ]);

    it('normalizes a fractional since cutoff before the Gateway parses it', function (string $since, string $cutoff, string $excluded): void {
        $mock = MockClient::global(gateway_fixture_mock('tasks/tasks-question-list/empty'));

        expect(Artisan::call('tasks:question:list', ['--since' => $since, '--json' => true]))->toBe(0);

        $sent = null;
        $mock->assertSent(static function (Request $request) use (&$sent): bool {
            if (! $request instanceof ListTaskQuestionsRequest) {
                return false;
            }

            $value = $request->query()->all()['since'] ?? null;
            $sent = is_string($value) ? $value : null;

            return is_string($sent);
        });

        $parsed = Carbon::parse($sent)->utc();
        $included = Carbon::parse($cutoff)->utc();

        expect($parsed->equalTo($included))->toBeTrue()
            ->and($included->greaterThanOrEqualTo($parsed))->toBeTrue()
            ->and(Carbon::parse($excluded)->utc()->greaterThanOrEqualTo($parsed))->toBeFalse();
    })->with([
        'fractional minutes' => ['2026-10-07T12:00.5Z', '2026-10-07T12:00:30Z', '2026-10-07T12:00:05Z'],
        'fractional hours' => ['2026-10-07T12.5Z', '2026-10-07T12:30:00Z', '2026-10-07T12:05:00Z'],
        'fractional minutes with an offset' => ['2026-10-07T12:00.5+01:00', '2026-10-07T11:00:30Z', '2026-10-07T11:00:05Z'],
    ]);

    it('cancels with --yes without reading the group first', function (): void {
        $mock = MockClient::global(gateway_fixture_mock('tasks/tasks-cancel/cancelled'));

        expect(Artisan::call('tasks:cancel', ['group' => '1', '--yes' => true, '--json' => true]))->toBe(0);

        $mock->assertSent(CancelTaskGroupRequest::class);
        $mock->assertNotSent(ShowTaskGroupRequest::class);
    });

    it('cancels a running subtask with --yes without reading the group first', function (): void {
        $mock = MockClient::global(gateway_fixture_mock('tasks/tasks-subtask-cancel/cancelled'));

        expect(Artisan::call('tasks:subtask:cancel', ['group' => '13', 'subtask' => '57', '--yes' => true, '--json' => true]))->toBe(0)
            ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['status'])->toBe('cancelled');

        $mock->assertSent(static fn (Request $request): bool => $request instanceof CancelSubtaskRequest
            && $request->resolveEndpoint() === '/api/v1/task-groups/13/tasks/57/cancel');
        $mock->assertNotSent(ShowTaskGroupRequest::class);
    });

    it('sends fails_on_base and paths when creating and updating a subtask', function (bool $failsOnBase): void {
        $path = $this->orbitHome.'/deliverables.json';
        is_dir($this->orbitHome) || mkdir($this->orbitHome, 0700, true);
        file_put_contents($path, json_encode([[
            'id' => 'layout-repro',
            'type' => 'command',
            'description' => 'The layout fails before the fix',
            'command' => "vendor/bin/pest tests/Feature/HomeScreenTest.php --filter='home screen layout'",
            'directory' => 'apps/gateway',
            'fails_on_base' => $failsOnBase,
            'paths' => ['apps/gateway/tests/Feature/HomeScreenTest.php'],
        ]], JSON_THROW_ON_ERROR));
        $mock = MockClient::global([
            ...gateway_fixture_mock('tasks/tasks-subtask-create/created'),
            ...gateway_fixture_mock('tasks/tasks-subtask-update/updated'),
        ]);
        $encoded = $failsOnBase ? 'true' : 'false';

        expect(Artisan::call('tasks:subtask:create', ['group' => '1', 'title' => 'Layout', '--brief' => 'Fix the layout.', '--deliverables' => $path, '--json' => true]))->toBe(0)
            ->and(Artisan::call('tasks:subtask:update', ['group' => '1', 'subtask' => '2', '--deliverables' => $path, '--json' => true]))->toBe(0);

        $mock->assertSent(static fn (Request $request): bool => $request instanceof CreateSubtaskRequest
            && str_contains((string) $request->body(), '"fails_on_base":'.$encoded)
            && str_contains((string) $request->body(), '"paths":["apps\\/gateway\\/tests\\/Feature\\/HomeScreenTest.php"]'));
        $mock->assertSent(static fn (Request $request): bool => $request instanceof UpdateSubtaskRequest
            && str_contains((string) $request->body(), '"fails_on_base":'.$encoded));
    })->with([
        'true' => [true],
        'false' => [false],
    ]);

    it('refuses fails_on_base on a deliverable that is not a command', function (string $type, array $extra): void {
        $path = $this->orbitHome.'/deliverables.json';
        is_dir($this->orbitHome) || mkdir($this->orbitHome, 0700, true);
        file_put_contents($path, json_encode([[
            'id' => 'docs',
            'type' => $type,
            'description' => 'Docs',
            'fails_on_base' => true,
            ...$extra,
        ]], JSON_THROW_ON_ERROR));
        $mock = MockClient::global([]);

        expect(Artisan::call('tasks:subtask:update', ['group' => '1', 'subtask' => '2', '--deliverables' => $path, '--json' => true]))->toBe(1)
            ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['message'])->toBe('The fails_on_base field is only allowed on a command deliverable (deliverable docs).');
        $mock->assertNothingSent();

        MockClient::destroyGlobal();
        $mock = MockClient::global([]);

        expect(Artisan::call('tasks:subtask:create', ['group' => '1', 'title' => 'Docs', '--brief' => 'Write the docs.', '--deliverables' => $path, '--json' => true]))->toBe(1)
            ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe('tasks.deliverables_invalid');
        $mock->assertNothingSent();
    })->with([
        'a file' => ['file', ['path' => 'docs/a.md', 'change' => 'any']],
        'a review' => ['review', []],
    ]);

    it('refuses paths that are not a list of strings', function (): void {
        $path = $this->orbitHome.'/deliverables.json';
        is_dir($this->orbitHome) || mkdir($this->orbitHome, 0700, true);
        file_put_contents($path, json_encode([[
            'id' => 'repro',
            'type' => 'command',
            'description' => 'Repro',
            'command' => 'bun test',
            'paths' => ['a.ts', 3],
        ]], JSON_THROW_ON_ERROR));
        $mock = MockClient::global([]);

        expect(Artisan::call('tasks:subtask:update', ['group' => '1', 'subtask' => '2', '--deliverables' => $path, '--json' => true]))->toBe(1)
            ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['message'])->toBe('The paths value for deliverable repro must be a list of strings.');
        $mock->assertNothingSent();
    });

    it('shows the watched pull request in JSON without changing the publication URL', function (): void {
        $mock = MockClient::global(gateway_fixture_mock('tasks/tasks-show/watched'));

        expect(Artisan::call('tasks:show', ['group' => '1', '--json' => true]))->toBe(0);
        $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($output['watched_pr_url'])->toBe('https://github.com/nckrtl/orbit/pull/451')
            ->and($output['watched_pr_number'])->toBe(451)
            ->and($output['watched_pr_state'])->toBe('open')
            ->and($output['pr_url'])->toBeNull();
        $mock->assertSent(ShowTaskGroupRequest::class);
    });

    it('shows fails_on_base for a test deliverable', function (): void {
        $fixture = json_decode((string) file_get_contents(gateway_fixture_path('tasks/tasks-show/default')), true, flags: JSON_THROW_ON_ERROR);
        $fixture['body']['data']['tasks'][0]['deliverables'][1]['fails_on_base'] = true;
        MockClient::global([
            ShowTaskGroupRequest::class => MockResponse::make($fixture['body'], $fixture['status']),
        ]);

        expect(Artisan::call('tasks:show', ['group' => '1']))->toBe(0);
        $human = Artisan::output();
        expect($human)->toContain('Fails on the start commit')->toContain('sdk-test');

        MockClient::destroyGlobal();
        MockClient::global([
            ShowTaskGroupRequest::class => MockResponse::make($fixture['body'], $fixture['status']),
        ]);

        expect(Artisan::call('tasks:show', ['group' => '1', '--json' => true]))->toBe(0);
        expect(Artisan::output())->toContain('"fails_on_base":true');
    });

    it('returns the Gateway error code when a subtask cannot be cancelled', function (string $fixture, string $code): void {
        MockClient::global(gateway_fixture_mock($fixture));

        expect(Artisan::call('tasks:subtask:cancel', ['group' => '1', 'subtask' => '1', '--yes' => true, '--json' => true]))->toBe(1)
            ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe($code);
    })->with([
        'not running' => ['tasks/tasks-subtask-cancel/not-running', 'tasks.subtask_not_running'],
        'interrupt failed' => ['tasks/tasks-subtask-cancel/interrupt-failed', 'tasks.subtask_interrupt_failed'],
    ]);
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
        $mock->assertSent(ListProjectsRequest::class);
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
        'id' => $id, 'project_id' => 1, 'project' => 'orbit', 'project_code' => 'ORB', 'title' => $title, 'brief' => 'Brief', 'status' => $status, 'tasks' => [],
    ];

    return MockClient::global([
        ListTaskGroupsRequest::class => static fn ($pending): MockResponse => MockResponse::make(['data' => array_values(array_filter(
            [$group(1, 'Backlog feature', 'backlog'), $group(3, 'Second backlog feature', 'backlog'), $group(5, 'Settling feature', 'settling')],
            static fn (array $row): bool => ! isset($pending->getRequest()->query()->all()['status']) || $row['status'] === $pending->getRequest()->query()->all()['status'],
        )), 'meta' => $meta]),
        ShowTaskGroupRequest::class => MockResponse::make(['data' => $group(1, 'Feature', 'backlog'), 'meta' => $meta]),
        ListProjectsRequest::class => MockResponse::make(['data' => [
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

describe('assistance columns', function (): void {
    it('shows direction requests before failures, and the kind on the list and the group', function (): void {
        $columns = getenv('COLUMNS');
        putenv('COLUMNS=160');
        $recorded = json_decode((string) file_get_contents(dirname(__DIR__, 5).'/packages/php-sdk/fixtures/tasks/tasks-show/default.json'), true, flags: JSON_THROW_ON_ERROR);
        $group = $recorded['body']['data'];
        $group['assistance_requested'] = true;
        $group['assistance_kind'] = 'direction';
        $group['assistance_question'] = 'Which database should this use?';
        $group['assistance_reason'] = 'The implementer is blocked.';
        $group['tasks'][0]['assistance_requested'] = true;
        $group['tasks'][0]['assistance_kind'] = 'direction';
        $group['tasks'][0]['assistance_question'] = 'Which database should this use?';
        $group['tasks'][0]['assistance_reason'] = 'The implementer is blocked.';
        $failure = $group;
        $failure['id'] = 3;
        $failure['title'] = 'Failed push';
        $failure['assistance_kind'] = 'failure';
        $failure['assistance_question'] = null;
        $failure['assistance_reason'] = 'The push failed.';
        $failure['tasks'][0]['assistance_kind'] = 'failure';
        $failure['tasks'][0]['assistance_question'] = null;
        $failure['tasks'][0]['assistance_reason'] = 'The push failed.';
        $clear = $group;
        $clear['id'] = 2;
        $clear['title'] = 'Clear';
        $clear['assistance_requested'] = false;
        $clear['assistance_kind'] = 'direction';
        $clear['assistance_question'] = 'An old question.';
        $clear['assistance_reason'] = 'An old reason.';
        $clear['tasks'][0]['assistance_requested'] = false;
        $clear['tasks'][0]['assistance_kind'] = 'direction';
        $clear['tasks'][0]['assistance_question'] = 'An old question.';
        $clear['tasks'][0]['assistance_reason'] = 'An old reason.';
        $body = static fn (array $data): array => ['data' => $data, 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']];

        MockClient::global([
            ListTaskGroupsRequest::class => MockResponse::make($body([$failure, $group, $clear])),
        ]);

        expect(Artisan::call('tasks:list'))->toBe(0);
        $list = Artisan::output();
        expect($list)->toContain('KIND')
            ->and($list)->toContain('direction')
            ->and($list)->toContain('failure')
            ->and($list)->toContain('Which database should this use?')
            ->and($list)->toContain('The push failed.')
            ->and($list)->not->toContain('An old reason.')
            ->and($list)->not->toContain('An old question.');

        MockClient::destroyGlobal();
        MockClient::global([
            ShowTaskGroupRequest::class => MockResponse::make($body($group)),
        ]);

        expect(Artisan::call('tasks:show', ['group' => '1']))->toBe(0);
        $shown = Artisan::output();
        expect($shown)->toContain('Kind')
            ->and($shown)->toContain('direction')
            ->and($shown)->toContain('Which database should this use?')
            ->and($shown)->toContain('The implementer is blocked.')
            ->and($shown)->toContain('KIND');

        MockClient::destroyGlobal();
        MockClient::global([
            ShowTasksStatusRequest::class => MockResponse::make($body([
                'enabled' => true,
                'assistance' => [
                    [
                        'id' => 2,
                        'project_id' => 1,
                        'project' => 'orbit',
                        'project_code' => 'ORB',
                        'title' => 'Failed push',
                        'status' => 'running',
                        'assistance_kind' => 'failure',
                        'assistance_question' => null,
                        'assistance_reason' => 'The push failed.',
                    ],
                    [
                        'id' => 4,
                        'project_id' => 1,
                        'project' => 'orbit',
                        'project_code' => 'ORB',
                        'title' => 'Needs a database',
                        'status' => 'running',
                        'assistance_kind' => 'direction',
                        'assistance_question' => 'Which database should this use?',
                        'assistance_reason' => 'The implementer is blocked.',
                    ],
                    [
                        'id' => 9,
                        'project_id' => 1,
                        'project' => 'orbit',
                        'project_code' => 'ORB',
                        'title' => 'Needs an ADR',
                        'status' => 'reviewing',
                        'assistance_kind' => 'direction',
                        'assistance_question' => 'Which ADR applies?',
                        'assistance_reason' => 'The reviewer is blocked.',
                    ],
                    [
                        'id' => 11,
                        'project_id' => 1,
                        'project' => 'orbit',
                        'project_code' => 'ORB',
                        'title' => 'Check failed',
                        'status' => 'settling',
                        'assistance_kind' => 'failure',
                        'assistance_question' => null,
                        'assistance_reason' => 'The check failed.',
                    ],
                ],
            ])),
        ]);

        expect(Artisan::call('tasks:status'))->toBe(0);
        $status = Artisan::output();
        $directionAt = strpos($status, 'Needs your direction');
        $failuresAt = strpos($status, 'Failures');
        expect($directionAt)->not->toBeFalse()
            ->and($failuresAt)->not->toBeFalse()
            ->and($directionAt)->toBeLessThan($failuresAt)
            ->and(strpos($status, 'Which database should this use?'))->toBeLessThan(strpos($status, 'Which ADR applies?'))
            ->and(strpos($status, 'Which ADR applies?'))->toBeLessThan(strpos($status, 'The push failed.'))
            ->and(strpos($status, 'The push failed.'))->toBeLessThan(strpos($status, 'The check failed.'))
            ->and($status)->not->toContain('An old reason.');

        expect(Artisan::call('tasks:status', ['--json' => true]))->toBe(0);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($json['assistance'], 'id'))->toBe([2, 4, 9, 11])
            ->and($json['assistance'][1])->toMatchArray([
                'id' => 4,
                'project_code' => 'ORB',
                'assistance_kind' => 'direction',
                'assistance_question' => 'Which database should this use?',
                'assistance_reason' => 'The implementer is blocked.',
            ]);
        putenv($columns === false ? 'COLUMNS' : 'COLUMNS='.$columns);
    });
});

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

it('shows the pinned compute mode and capacity wait in human and JSON output', function (bool $json): void {
    $fixture = json_decode((string) file_get_contents(gateway_fixture_path('tasks/tasks-show/default')), true, flags: JSON_THROW_ON_ERROR);
    $fixture['body']['data']['task_compute'] = 'vm';
    $fixture['body']['data']['capacity_wait_reason'] = 'The local VM budget is full.';
    MockClient::global([ShowTaskGroupRequest::class => MockResponse::make($fixture['body'])]);

    expect(Artisan::call('tasks:show', ['group' => '1', '--json' => $json, '--no-interaction' => true]))->toBe(0);
    $output = Artisan::output();
    if ($json) {
        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['task_compute'])->toBe('vm')
            ->and($data['capacity_wait_reason'])->toBe('The local VM budget is full.');
    } else {
        expect($output)->toContain('Task compute', 'vm', 'Waiting for capacity', 'The local VM budget is full.');
    }
})->with([false, true]);
