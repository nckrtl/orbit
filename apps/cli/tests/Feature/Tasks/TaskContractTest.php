<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;

/**
 * Contract tests replay the recorded tasks responses and compare the complete command output
 * with tests/Expected/tasks. A fixture change that reaches a tasks command fails here first.
 */
beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=120');
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    putenv($this->originalColumns === false ? 'COLUMNS' : 'COLUMNS='.$this->originalColumns);
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

/** @param array<string, mixed> $arguments */
function run_task_contract(string $fixture, string $command, array $arguments, int $exitCode): void
{
    [$family, $case] = explode('/', $fixture, 2);

    foreach (['human.txt' => [], 'json' => ['--json' => true]] as $extension => $mode) {
        // A global mock keeps its first responses, so replace it for every replay.
        MockClient::destroyGlobal();
        MockClient::global(gateway_fixture_mock("tasks/{$fixture}"));

        expect(Artisan::call($command, [...$arguments, ...$mode]))->toBe($exitCode);
        expect_output(Artisan::output(), "tasks/{$family}/{$case}.{$extension}");
    }
}

describe('tasks contract', function (): void {
    it('renders the extension toggles and status', function (): void {
        run_task_contract('tasks-enable/enabled', 'tasks:enable', [], 0);
        run_task_contract('tasks-disable/disabled', 'tasks:disable', [], 0);
        run_task_contract('tasks-status/enabled', 'tasks:status', [], 0);
    });

    it('renders the group list and an empty list', function (): void {
        run_task_contract('tasks-list/default', 'tasks:list', [], 0);
        run_task_contract('tasks-list/empty', 'tasks:list', [], 0);
    });

    it('renders one group with its brief and subtasks', function (): void {
        run_task_contract('tasks-show/default', 'tasks:show', ['group' => '1'], 0);
    });

    it('renders a created group and a refused todo create', function (): void {
        run_task_contract('tasks-create/created', 'tasks:create', ['title' => 'Add the tasks CLI', '--project' => '1', '--brief' => 'Brief'], 0);
        run_task_contract('tasks-create/no-subtasks', 'tasks:create', ['title' => 'Empty', '--project' => '1', '--brief' => 'No subtasks.', '--status' => 'todo'], 1);
    });

    it('renders group updates, a refused update, cancel, and complete', function (): void {
        run_task_contract('tasks-update/updated', 'tasks:update', ['group' => '1', '--title' => 'Add the tasks command family'], 0);
        run_task_contract('tasks-update/not-in-backlog', 'tasks:update', ['group' => '1', '--title' => 'Too late'], 1);
        run_task_contract('tasks-cancel/cancelled', 'tasks:cancel', ['group' => '1', '--yes' => true], 0);
        run_task_contract('tasks-complete/completed', 'tasks:complete', ['group' => '2', '--yes' => true], 0);
    });

    it('renders subtask create, update, and destroy', function (): void {
        run_task_contract('tasks-subtask-create/created', 'tasks:subtask:create', ['group' => '1', 'title' => 'Document the commands', '--brief' => 'Add docs/cli/tasks.mdx.'], 0);
        run_task_contract('tasks-subtask-update/updated', 'tasks:subtask:update', ['group' => '1', 'subtask' => '1', '--position' => '2'], 0);
        run_task_contract('tasks-subtask-destroy/destroyed', 'tasks:subtask:destroy', ['group' => '1', 'subtask' => '1', '--yes' => true], 0);
    });

    it('renders comments and agent threads', function (): void {
        run_task_contract('tasks-comment-create/created', 'tasks:comment:create', ['group' => '1', 'subtask' => '1', '--type' => 'resolution', '--body' => 'Use the existing request base.', '--author' => 'nick'], 0);
        run_task_contract('tasks-comment-list/default', 'tasks:comment:list', ['group' => '1', 'subtask' => '1'], 0);
        run_task_contract('tasks-agents/default', 'tasks:agents', ['group' => '1'], 0);
    });
});
