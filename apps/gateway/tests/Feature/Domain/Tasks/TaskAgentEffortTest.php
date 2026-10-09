<?php

declare(strict_types=1);

use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\TaskAgentSpawner;
use App\Domain\Tasks\TaskWorkspaceMcp;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AcceptingTaskWorkspaceMcp;

/** Loads the real effort configuration without leaking environment changes to other tests. */
function configure_task_effort(string $role, ?string $value): void
{
    $values = [
        'ORBIT_TASKS_IMPLEMENTER_EFFORT' => $role === 'implementer' ? $value : 'low',
        'ORBIT_TASKS_REVIEWER_EFFORT' => $role === 'reviewer' ? $value : 'low',
    ];
    $saved = [];
    foreach ($values as $key => $setting) {
        $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
        if ($setting !== null) {
            $_ENV[$key] = $_SERVER[$key] = $setting;
            putenv("{$key}={$setting}");
        }
    }

    try {
        $config = require config_path('orbit.php');
        config()->set('orbit.tasks', $config['tasks']);
    } finally {
        foreach ($saved as $key => [$env, $server, $process]) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
            if ($env !== null) {
                $_ENV[$key] = $env;
            }
            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
            if ($process !== false) {
                putenv("{$key}={$process}");
            }
        }
    }
}

function effort_task(): Task
{
    $project = Project::query()->create([
        'name' => 'effort', 'slug' => 'effort',
        'repository_url' => 'git@example.test:effort.git', 'default_branch' => 'main',
        'apps' => fixture_apps(null),
    ]);
    $node = Node::query()->create([
        'name' => 'effort-node', 'platform' => 'linux', 'status' => 'active',
        'wireguard_ip' => '10.44.0.110', 'public_ssh_host' => '10.44.0.110',
        'settings' => ['pi' => ['token' => 'effort-test-pi-token']],
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-1',
        'checkout_path' => '/srv/orbit/task-1', 'branch' => 'task-1', 'status' => 'source_resolved',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id, 'title' => 'Effort', 'brief' => 'Configure effort.', 'status' => 'running',
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    app()->instance(TaskWorkspaceMcp::class, new AcceptingTaskWorkspaceMcp);

    return Task::query()->create([
        'parent_id' => $group->id, 'position' => 1,
        'title' => 'Thread effort', 'brief' => 'Use the configured effort.', 'status' => 'running',
    ]);
}

function fake_effort_pi(): void
{
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.110:3774/sessions' => Http::response(['id' => 'session'], 201),
        'http://10.44.0.110:3774/sessions/*/messages' => Http::response(['duplicate' => false], 202),
    ]);
}

dataset('effort roles', [
    'implementer' => ['implementer', 'spawnImplementer', 'reserveImplementer'],
    'reviewer' => ['reviewer', 'spawnReviewer', 'reserveReviewer'],
]);

dataset('effort settings', [
    'configured medium' => ['medium', 'medium'],
    'unset defaults to high' => [null, 'high'],
    'empty defaults to high' => ['', 'high'],
    'runtime validates unchanged value' => ['runtime-specific', 'runtime-specific'],
]);

describe('thread effort', function (): void {
    it('stores configured or default effort and sends it to Pi', function (string $role, string $spawn, string $reserve, ?string $value, string $expected): void {
        $task = effort_task();
        $spawner = app(TaskAgentSpawner::class);
        configure_task_effort($role, $value);
        fake_effort_pi();

        $id = $spawner->{$spawn}($task);

        expect(config('orbit.tasks.'.$role.'_effort'))->toBe($expected);
        $this->assertDatabaseHas('agent_threads', ['id' => $id, 'role' => $role, 'effort' => $expected]);
        $thread = AgentThread::query()->findOrFail($id);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://10.44.0.110:3774/sessions'
            && $request['id'] === $thread->external_id && $request['thinkingLevel'] === $expected);
    })->with('effort roles')->with('effort settings');

    it('keeps stored effort while the next thread in an open group reads changed config', function (string $role, string $spawn, string $reserve): void {
        $task = effort_task();
        $spawner = app(TaskAgentSpawner::class);
        configure_task_effort($role, 'medium');
        $id = $spawner->{$reserve}($task);
        configure_task_effort($role, 'low');
        fake_effort_pi();

        expect($spawner->{$spawn}($task))->toBe($id);
        $thread = AgentThread::query()->findOrFail($id);
        app(AgentDriverRegistry::class)->get('pi')->send($thread, 'Continue.');

        $this->assertDatabaseHas('agent_threads', ['id' => $id, 'effort' => 'medium']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://10.44.0.110:3774/sessions'
            && $request['id'] === $thread->external_id && $request['thinkingLevel'] === 'medium');
        expect(Http::recorded(fn (Request $request): bool => $request->url() === 'http://10.44.0.110:3774/sessions'))->toHaveCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://10.44.0.110:3774/sessions/'.$thread->external_id.'/messages'
            && $request['text'] === 'Continue.');

        $next = Task::query()->create([
            'parent_id' => $task->parent_id, 'position' => 2,
            'title' => 'Next thread', 'brief' => 'Use changed effort.', 'status' => 'running',
        ]);
        $nextId = $spawner->{$spawn}($next);
        $nextThread = AgentThread::query()->findOrFail($nextId);

        $this->assertDatabaseHas('agent_threads', ['id' => $nextId, 'role' => $role, 'effort' => 'low']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://10.44.0.110:3774/sessions'
            && $request['id'] === $nextThread->external_id && $request['thinkingLevel'] === 'low');
    })->with('effort roles');

    it('starts a legacy reserved thread with configured effort when stored effort is absent', function (string $role, string $spawn, string $reserve): void {
        $task = effort_task();
        $spawner = app(TaskAgentSpawner::class);
        $id = $spawner->{$reserve}($task);
        AgentThread::query()->findOrFail($id)->update(['effort' => null]);
        configure_task_effort($role, 'medium');
        fake_effort_pi();

        expect($spawner->{$spawn}($task))->toBe($id);

        $thread = AgentThread::query()->findOrFail($id);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://10.44.0.110:3774/sessions'
            && $request['id'] === $thread->external_id && $request['thinkingLevel'] === 'medium');
    })->with('effort roles');

    it('backfills configured effort for legacy sessions of both roles', function (): void {
        config()->set('orbit.tasks.implementer_effort', 'medium');
        config()->set('orbit.tasks.reviewer_effort', 'low');
        $default = DB::getDefaultConnection();
        config()->set('database.connections.effort_migration', ['driver' => 'sqlite', 'database' => ':memory:']);
        DB::setDefaultConnection('effort_migration');
        try {
            Schema::create('task_groups', static function (Blueprint $table): void {
                $table->id();
                $table->string('implementer_model')->nullable();
                $table->string('reviewer_model')->nullable();
            });
            Schema::create('task_agent_sessions', static function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('task_group_id');
                $table->string('role');
            });
            DB::table('task_groups')->insert(['id' => 1]);
            DB::table('task_agent_sessions')->insert([
                ['task_group_id' => 1, 'role' => 'implementer'],
                ['task_group_id' => 1, 'role' => 'reviewer'],
            ]);
            $migration = require database_path('migrations/2026_09_21_150000_add_model_and_effort_to_task_agent_sessions.php');

            $migration->up();

            expect(DB::table('task_agent_sessions')->orderBy('id')->pluck('effort')->all())->toBe(['medium', 'low']);
        } finally {
            DB::setDefaultConnection($default);
            DB::purge('effort_migration');
        }
    });
});
