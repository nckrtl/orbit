<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskableType;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\Schema;

it('keeps task and subtask columns on one tasks table', function (): void {
    expect(Schema::hasTable('task_groups'))->toBeFalse()
        ->and(Schema::hasTable('tasks'))->toBeTrue()
        ->and(Schema::hasColumns('tasks', [
            'parent_id',
            'project_id',
            'taskable_type',
            'taskable_id',
            'title',
            'brief',
            'status',
            'reviewer_agent_thread_id',
            'pr_url',
            'notify_coder',
            'implementer_model',
            'reviewer_model',
            'tokens',
            'line_diff',
            'duration_ms',
            'started_at',
            'settled_at',
            'position',
            'implementer_agent_thread_id',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('agent_threads', [
            'tokens',
            'input_tokens',
            'cached_input_tokens',
            'output_tokens',
            'model_calls',
            'peak_context_tokens',
            'archived_at',
            'archive_command_id',
            'archive_attempts',
            'archive_retry_at',
            't3_input_tokens',
            't3_cached_input_tokens',
            't3_output_tokens',
            't3_model_calls',
            't3_peak_context_tokens',
            't3_counted_total_processed_tokens',
            't3_observed_total_processed_tokens',
            't3_event_sequence',
            't3_metrics_partial',
            't3_metrics_initialized',
            't3_metrics_collected_at',
            't3_metrics_final_at',
            't3_metrics_retry_at',
            't3_metrics_attempts',
            't3_metrics_activity_version',
            't3_metrics_observed_activity_version',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('agent_thread_send_leases', ['agent_thread_id', 'owner_token', 'expires_at']))->toBeTrue();
});

it('rolls back archive backoff columns and their index', function (): void {
    $migration = require database_path('migrations/2026_09_29_120000_add_archive_backoff_to_agent_threads.php');

    try {
        run_legacy_schema_migration($migration, 'down');

        expect(Schema::hasColumns('agent_threads', ['archive_attempts', 'archive_retry_at']))->toBeFalse()
            ->and(Schema::hasIndex('agent_threads', 'agent_threads_archive_retry_at_index'))->toBeFalse();
    } finally {
        run_legacy_schema_migration($migration, 'up');
    }
});

it('persists a Task morph to a Project instance and ordered subtasks', function (): void {
    $node = Node::query()->create([
        'name' => 'task-migration-node',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.99',
    ]);
    $project = Project::query()->create([
        'name' => 'Task migration',
        'slug' => 'task-migration',
        'repository_url' => 'git@example.test:task-migration.git',
        'apps' => fixture_apps(null),
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'feature',
        'checkout_path' => '/tmp/task-migration',
        'status' => 'reserved',
    ]);

    $group = $project->tasks()->create([
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

    expect($fresh?->taskable)->toBeInstanceOf(Instance::class)
        ->and($fresh?->taskable_type)->toBe(TaskableType::Instance)
        ->and($fresh?->taskable_id)->toBe($instance->id)
        ->and($fresh?->tokens)->toBe(12)
        ->and($fresh?->line_diff)->toBe(40)
        ->and($fresh?->duration_ms)->toBe(1500)
        ->and($fresh?->tasks->pluck('title')->all())->toBe(['First', 'Second'])
        ->and($instance->tasks()->first()?->id)->toBe($group->id)
        ->and(Task::query()->where('parent_id', $group->id)->count())->toBe(2);
});
