<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskableType;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
use Illuminate\Support\Facades\Schema;

it('creates task_groups and tasks with morph, metrics, and ordering columns', function (): void {
    expect(Schema::hasTable('task_groups'))->toBeTrue()
        ->and(Schema::hasTable('tasks'))->toBeTrue()
        ->and(Schema::hasColumns('task_groups', [
            'app_id',
            'taskable_type',
            'taskable_id',
            'title',
            'brief',
            'status',
            'reviewer_thread_id',
            'pr_url',
            'notify_coder',
            'implementer_model',
            'reviewer_model',
            'tokens',
            'line_diff',
            'duration_ms',
            'started_at',
            'settled_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('tasks', [
            'task_group_id',
            'position',
            'title',
            'brief',
            'status',
            'implementer_thread_id',
            'tokens',
            'line_diff',
            'duration_ms',
        ]))->toBeTrue();
});

it('persists a TaskGroup morph to an App instance and ordered subtasks', function (): void {
    $node = Node::query()->create([
        'name' => 'task-migration-node',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.99',
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Task migration',
        'slug' => 'task-migration',
        'repository_url' => 'git@example.test:task-migration.git',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'feature',
        'checkout_path' => '/tmp/task-migration',
        'status' => 'reserved',
    ]);

    $group = $app->taskGroups()->create([
        'title' => 'Morph',
        'brief' => 'Attach the instance.',
    ]);
    $group->taskable()->associate($instance);
    $group->tokens = 12;
    $group->line_diff = 40;
    $group->duration_ms = 1500;
    $group->save();

    $group->tasks()->createMany([
        ['position' => 2, 'title' => 'Second', 'brief' => 'Later'],
        ['position' => 1, 'title' => 'First', 'brief' => 'Earlier'],
    ]);

    $fresh = $group->fresh(['taskable', 'tasks']);

    expect($fresh?->taskable)->toBeInstanceOf(AppInstance::class)
        ->and($fresh?->taskable_type)->toBe(TaskableType::Instance)
        ->and($fresh?->taskable_id)->toBe($instance->id)
        ->and($fresh?->tokens)->toBe(12)
        ->and($fresh?->line_diff)->toBe(40)
        ->and($fresh?->duration_ms)->toBe(1500)
        ->and($fresh?->tasks->pluck('title')->all())->toBe(['First', 'Second'])
        ->and($instance->taskGroups()->first()?->id)->toBe($group->id)
        ->and(Task::query()->where('task_group_id', $group->id)->count())->toBe(2);
});
