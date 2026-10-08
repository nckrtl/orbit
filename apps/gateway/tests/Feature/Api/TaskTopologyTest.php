<?php

declare(strict_types=1);

use App\Models\Task;
use App\Models\TaskDefinition;

require_once __DIR__.'/../../Support/TaskDefinitions.php';

it('preserves per-subtask declarations through group creation edits and standalone creation', function (): void {
    task_definition_gateway();
    $project = task_definition_project('orbit');
    $group = $this->postJson('/api/v1/task-groups', [
        'project_id' => $project->id, 'title' => 'Declared nodes', 'brief' => 'Use private workload nodes.',
        'tasks' => [
            ['title' => 'First', 'brief' => 'Needs dev.', 'topology' => ['app-dev']],
            ['title' => 'Second', 'brief' => 'Also needs dev.', 'topology' => ['app-dev', 'app-prod-2']],
        ],
    ])->assertCreated()->assertJsonPath('data.tasks.0.topology', ['app-dev'])
        ->assertJsonPath('data.tasks.1.topology', ['app-dev', 'app-prod-2'])->json('data');
    $task = Task::query()->findOrFail($group['tasks'][0]['id']);
    expect($task->topology)->toBe(['app-dev']);
    $url = '/api/v1/task-groups/'.$group['id'].'/tasks';
    $this->patchJson($url.'/'.$task->id, ['title' => 'Renamed'])->assertOk()->assertJsonPath('data.topology', ['app-dev']);
    $this->patchJson($url.'/'.$task->id, ['topology' => []])->assertOk()->assertJsonPath('data.topology', []);
    expect($task->fresh()->topology)->toBe([]);
    $this->postJson($url, ['title' => 'Third', 'brief' => 'Production.', 'topology' => ['app-prod']])
        ->assertCreated()->assertJsonPath('data.topology', ['app-prod']);
});

it('rejects invalid declarations without creating a group', function (mixed $topology): void {
    task_definition_gateway();
    $project = task_definition_project('orbit');
    $this->postJson('/api/v1/task-groups', [
        'project_id' => $project->id, 'title' => 'Invalid', 'brief' => 'Invalid inventory.',
        'tasks' => [['title' => 'Step', 'brief' => 'No start.', 'topology' => $topology]],
    ])->assertUnprocessable();
    expect(Task::withoutGlobalScopes()->count())->toBe(0);
})->with([
    'duplicate' => [['app-dev', 'app-dev']], 'gateway' => [['gateway']], 'live host' => [['beast']],
    'scalar' => ['app-dev'], 'null' => [null], 'object' => [['name' => 'app-dev']],
]);

it('keeps declarations locked after a subtask has started even if its status is reset', function (): void {
    task_definition_gateway();
    $project = task_definition_project('orbit');
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Work', 'status' => 'backlog']);
    $task = $group->tasks()->create(['title' => 'Started', 'brief' => 'Work', 'position' => 1, 'status' => 'todo',
        'topology' => ['app-dev'], 'subtask_start_commit' => str_repeat('a', 40)]);
    $this->patchJson('/api/v1/task-groups/'.$group->id.'/tasks/'.$task->id, ['topology' => ['app-prod']])->assertUnprocessable();
    expect($task->fresh()->topology)->toBe(['app-dev']);
});

it('round trips declared nodes in stored task definitions', function (): void {
    task_definition_gateway();
    $project = task_definition_project('orbit');
    $payload = task_definition_payload(['subtasks' => [
        ['key' => 'build', 'title' => 'Build', 'kind' => 'agent', 'topology' => ['app-dev', 'app-prod']],
    ]]);
    $this->postJson('/api/v1/projects/'.$project->id.'/task-definitions', $payload)
        ->assertCreated()->assertJsonPath('data.subtasks.0.topology', ['app-dev', 'app-prod']);
    expect(TaskDefinition::query()->sole()->subtasks[0]['topology'])->toBe(['app-dev', 'app-prod']);
});

it('refuses invalid declared nodes in stored definitions', function (): void {
    task_definition_gateway();
    $project = task_definition_project('orbit');
    $payload = task_definition_payload(['subtasks' => [
        ['key' => 'build', 'title' => 'Build', 'kind' => 'agent', 'topology' => ['operator']],
    ]]);
    $this->postJson('/api/v1/projects/'.$project->id.'/task-definitions', $payload)->assertUnprocessable();
    expect(TaskDefinition::query()->count())->toBe(0);
});
