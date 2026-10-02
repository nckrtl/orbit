<?php

declare(strict_types=1);

use App\Actions\Annotations\AnnotationStoreAction;
use App\Actions\Tasks\CancelTaskGroupAction;
use App\Actions\Tasks\StoreTaskCommentAction;
use App\Data\Annotations\AnnotationInput;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

it('stops asking for assistance when a flagged task is saved as completed or cancelled', function (string $level, string $status): void {
    $gateway = Node::query()->create([
        'name' => 'ended-assistance-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.81',
        'wireguard_ip' => '10.44.0.81',
    ]);
    $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $project = Project::query()->create([
        'name' => 'Ended assistance',
        'slug' => 'ended-assistance',
        'repository_url' => 'git@example.test:ended-assistance.git',
        'default_branch' => 'main',
    ]);
    $reason = 'The operator asked to hold this task.';
    $open = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Still blocked',
        'brief' => 'This one still asks.',
        'status' => TaskGroupStatus::Running,
        'assistance_requested' => true,
        'assistance_reason' => 'Still blocked.',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Ending task',
        'brief' => 'This one ends.',
        'status' => TaskGroupStatus::Running,
        'assistance_requested' => $level === 'group',
        'assistance_reason' => $level === 'group' ? $reason : null,
    ]);
    $subject = $group;
    if ($level === 'subtask') {
        $subject = Task::query()->create([
            'parent_id' => $group->id,
            'position' => 1,
            'title' => 'Ending subtask',
            'brief' => 'This subtask ends.',
            'status' => TaskStatus::Running,
            'assistance_requested' => true,
            'assistance_reason' => $reason,
        ]);
    }

    $subject->status = $level === 'group' ? TaskGroupStatus::from($status) : TaskStatus::from($status);
    $subject->save();

    $subject->refresh();
    expect($subject->assistance_requested)->toBeFalse()
        ->and($subject->assistance_reason)->toBe($reason)
        ->and($subject->status->value)->toBe($status);

    $subject->assistance_requested = true;
    $subject->save();

    expect($subject->fresh()?->assistance_requested)->toBeFalse()
        ->and($subject->fresh()?->assistance_reason)->toBe($reason);

    $this->getJson('/api/v1/tasks/status')
        ->assertOk()
        ->assertJsonPath('data.assistance', [[
            'id' => $open->id,
            'project_id' => $project->id,
            'project' => $project->slug,
            'project_code' => $project->code,
            'title' => 'Still blocked',
            'status' => 'running',
            'assistance_kind' => null,
            'assistance_question' => null,
            'assistance_reason' => 'Still blocked.',
        ]]);
})->with([
    'completed group' => ['group', 'completed'],
    'cancelled group' => ['group', 'cancelled'],
    'completed subtask' => ['subtask', 'completed'],
    'cancelled subtask' => ['subtask', 'cancelled'],
]);

it('clears assistance in the same update that cancels the remaining subtasks', function (): void {
    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create([
        'name' => 'Cancel remaining',
        'slug' => 'cancel-remaining',
        'repository_url' => 'git@example.test:cancel-remaining.git',
        'default_branch' => 'main',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Cancel the rest',
        'brief' => 'End the open subtasks.',
        'status' => TaskGroupStatus::Running,
        'assistance_requested' => true,
        'assistance_reason' => 'The group is blocked.',
    ]);
    $open = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Still open',
        'brief' => 'Waiting.',
        'status' => TaskStatus::Running,
        'assistance_requested' => true,
        'assistance_reason' => 'Still waiting.',
    ]);

    $statements = [];
    DB::listen(function (object $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $cancelled = app(CancelTaskGroupAction::class)->execute($group->fresh() ?? $group);

    $statusUpdate = collect($statements)->first(static fn (string $sql): bool => str_contains($sql, 'not in'));

    expect($statusUpdate)->toBeString()
        ->and($statusUpdate)->toContain('assistance_requested')
        ->and($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($cancelled->assistance_requested)->toBeFalse()
        ->and($cancelled->assistance_reason)->toBe('The group is blocked.')
        ->and($open->fresh()?->status)->toBe(TaskStatus::Cancelled)
        ->and($open->fresh()?->assistance_requested)->toBeFalse()
        ->and($open->fresh()?->assistance_reason)->toBe('Still waiting.');
});

it('clears assistance when resolving an annotation completes its task', function (): void {
    $node = Node::query()->create([
        'name' => 'ended-annotation-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.82',
        'wireguard_ip' => '10.44.0.82',
    ]);
    $this->markAsGateway($node);
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $project = Project::query()->create([
        'name' => 'Ended annotation',
        'slug' => 'ended-annotation',
        'repository_url' => 'https://example.test/ended-annotation.git',
        'default_branch' => 'main',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'dev',
        'environment' => 'development',
        'source_layout' => 'worktree',
        'checkout_path' => '/worktree',
        'status' => 'active',
    ]);
    $annotation = app(AnnotationStoreAction::class)->create($instance, new AnnotationInput([
        'id' => 'annotation-ended',
        'threadId' => 'thread-one',
        'comment' => 'Make the title smaller',
    ]));
    $task = $annotation->task()->firstOrFail();
    $group = $task->parent()->firstOrFail();
    $group->update([
        'status' => TaskGroupStatus::Running,
        'assistance_requested' => true,
        'assistance_reason' => 'Hold this annotation.',
    ]);
    $task->update([
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'assistance_requested' => true,
        'assistance_reason' => 'Hold the subtask.',
    ]);

    app(AnnotationStoreAction::class)->transition($annotation, 'resolved', 'Done');

    $group->refresh();
    $task->refresh();
    expect($group->status)->toBe(TaskGroupStatus::Completed)
        ->and($group->assistance_requested)->toBeFalse()
        ->and($group->assistance_reason)->toBe('Hold this annotation.')
        ->and($task->status)->toBe(TaskStatus::Completed)
        ->and($task->assistance_requested)->toBeFalse()
        ->and($task->assistance_reason)->toBe('Hold the subtask.');

    $this->getJson('/api/v1/tasks/status')
        ->assertOk()
        ->assertJsonPath('data.assistance', []);
});

it('keeps an assistance comment from putting an ended group back on tasks status', function (string $status): void {
    $gateway = Node::query()->create([
        'name' => 'ended-comment-gateway-'.$status,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.83',
        'wireguard_ip' => '10.44.0.83',
    ]);
    $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $project = Project::query()->create([
        'name' => 'Ended comment',
        'slug' => 'ended-comment-'.$status,
        'repository_url' => 'git@example.test:ended-comment.git',
        'default_branch' => 'main',
    ]);
    $open = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Still blocked',
        'brief' => 'This one still asks.',
        'status' => TaskGroupStatus::Running,
        'assistance_requested' => true,
        'assistance_reason' => 'Still blocked.',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Ended group',
        'brief' => 'Already finished.',
        'status' => TaskGroupStatus::from($status),
        'assistance_requested' => false,
        'assistance_reason' => 'Earlier reason.',
    ]);
    $task = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Open subtask',
        'brief' => 'Still running.',
        'status' => TaskStatus::Running,
    ]);
    $reason = 'The operator asked for help after the task ended.';

    $comment = app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'assistance_requested',
        'body' => $reason,
        'author' => 'operator',
    ]);

    $group->refresh();
    $task->refresh();
    expect($comment->body)->toBe($reason)
        ->and($group->status->value)->toBe($status)
        ->and($group->assistance_requested)->toBeFalse()
        ->and($group->assistance_reason)->toBe($reason)
        ->and($task->status)->toBe(TaskStatus::Running)
        ->and($task->assistance_requested)->toBeTrue()
        ->and($task->assistance_reason)->toBe($reason);

    $this->getJson('/api/v1/tasks/status')
        ->assertOk()
        ->assertJsonPath('data.assistance', [[
            'id' => $open->id,
            'project_id' => $project->id,
            'project' => $project->slug,
            'project_code' => $project->code,
            'title' => 'Still blocked',
            'status' => 'running',
            'assistance_kind' => null,
            'assistance_question' => null,
            'assistance_reason' => 'Still blocked.',
        ]]);
})->with(['completed', 'cancelled']);

it('keeps a stale save from leaving an ended task asking for assistance', function (string $level, string $status, string $interleaving): void {
    $project = Project::query()->create([
        'name' => 'Stale assistance',
        'slug' => 'stale-assistance-'.$level.'-'.$status.'-'.$interleaving,
        'repository_url' => 'git@example.test:stale-assistance.git',
        'default_branch' => 'main',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Stale group',
        'brief' => 'Two copies of one row.',
        'status' => TaskGroupStatus::Running,
        'assistance_requested' => false,
    ]);
    $subject = $group;
    if ($level === 'subtask') {
        $subject = Task::query()->create([
            'parent_id' => $group->id,
            'position' => 1,
            'title' => 'Stale subtask',
            'brief' => 'Two copies of one subtask.',
            'status' => TaskStatus::Running,
            'assistance_requested' => false,
        ]);
    }
    $finder = $level === 'group'
        ? static fn (int $id): Task => Task::topLevel()->findOrFail($id)
        : static fn (int $id): Task => Task::query()->findOrFail($id);
    $stale = $finder($subject->id);
    $other = $finder($subject->id);
    $endedStatus = $level === 'group' ? TaskGroupStatus::from($status) : TaskStatus::from($status);

    if ($interleaving === 'end') {
        $other->update([
            'assistance_requested' => true,
            'assistance_reason' => 'Flagged while it was open.',
        ]);
        $stale->status = $endedStatus;
        $stale->save();

        expect($stale->assistance_requested)->toBeFalse()
            ->and($stale->fresh()?->status->value)->toBe($status)
            ->and($stale->fresh()?->assistance_requested)->toBeFalse()
            ->and($stale->fresh()?->assistance_reason)->toBe('Flagged while it was open.');
    } else {
        $other->status = $endedStatus;
        $other->assistance_reason = 'Ended by the other writer.';
        $other->save();
        $stale->update([
            'assistance_requested' => true,
            'assistance_reason' => 'Asked too late.',
        ]);

        expect($stale->assistance_requested)->toBeFalse()
            ->and($stale->status->value)->toBe('running')
            ->and($stale->fresh()?->status->value)->toBe($status)
            ->and($stale->fresh()?->assistance_requested)->toBeFalse()
            ->and($stale->fresh()?->assistance_reason)->toBe('Asked too late.');
    }
})->with([
    'group completed flagged then ended' => ['group', 'completed', 'end'],
    'group cancelled flagged then ended' => ['group', 'cancelled', 'end'],
    'group completed ended then flagged' => ['group', 'completed', 'flag'],
    'group cancelled ended then flagged' => ['group', 'cancelled', 'flag'],
    'subtask completed flagged then ended' => ['subtask', 'completed', 'end'],
    'subtask cancelled flagged then ended' => ['subtask', 'cancelled', 'end'],
    'subtask completed ended then flagged' => ['subtask', 'completed', 'flag'],
    'subtask cancelled ended then flagged' => ['subtask', 'cancelled', 'flag'],
]);
