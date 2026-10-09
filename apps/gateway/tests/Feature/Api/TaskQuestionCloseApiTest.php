<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\QuestionAsker;
use App\Domain\Tasks\QuestionStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskQuestion;

/** An escalated question on a running subtask, called from the Gateway with the extension on. */
function question_close_api_question(): TaskQuestion
{
    $gateway = Node::query()->create([
        'name' => 'question-close-gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.93', 'wireguard_ip' => '10.44.0.93',
    ]);
    test()->markAsGateway($gateway);
    test()->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create([
        'name' => 'question-close', 'slug' => 'question-close',
        'repository_url' => 'git@example.test:question-close.git', 'default_branch' => 'main',
        'apps' => fixture_apps(null),
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id, 'title' => 'Questions', 'brief' => 'Close them.', 'status' => TaskGroupStatus::Running,
    ]);
    $subtask = Task::query()->create([
        'parent_id' => $group->id, 'position' => 1, 'title' => 'Ask', 'brief' => 'One question.', 'status' => TaskStatus::Running,
    ]);

    return TaskQuestion::query()->create([
        'task_id' => $group->id, 'subtask_id' => $subtask->id, 'attempt' => 1, 'asked_by' => QuestionAsker::Implementer,
        'question' => 'Which mirror?', 'status' => QuestionStatus::Escalated, 'asked_at' => now(), 'escalated_at' => now(),
    ]);
}

it('closes a question as superseded or answered', function (string $status): void {
    $question = question_close_api_question();

    $this->postJson("/api/v1/task-questions/{$question->id}/close", ['status' => $status, 'reason' => 'Resolution 2803 answered it.'])
        ->assertOk()
        ->assertJsonPath('data.id', $question->id)
        ->assertJsonPath('data.status', $status)
        ->assertJsonPath('data.answer', 'Resolution 2803 answered it.')
        ->assertJsonPath('data.answered_by', 'operator')
        ->assertJsonStructure(['meta' => ['request_id']]);

    expect(TaskComment::query()->where('type', 'question_closed')->sole()->task_id)->toBe($question->subtask_id);
})->with(['superseded', 'answered']);

it('returns 409 for a question that is already closed, and 200 for the same close again', function (): void {
    $question = question_close_api_question();
    $this->postJson("/api/v1/task-questions/{$question->id}/close", ['status' => 'superseded', 'reason' => 'Stale.'])->assertOk();

    $this->postJson("/api/v1/task-questions/{$question->id}/close", ['status' => 'superseded', 'reason' => 'Stale.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'superseded');
    $this->postJson("/api/v1/task-questions/{$question->id}/close", ['status' => 'answered', 'reason' => 'Stale.'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'tasks.question_closed');
});

it('returns 422 for an unknown status or an empty reason, and 404 for an unknown question', function (): void {
    $question = question_close_api_question();

    $this->postJson("/api/v1/task-questions/{$question->id}/close", ['status' => 'escalated', 'reason' => 'Stale.'])
        ->assertUnprocessable();
    $this->postJson("/api/v1/task-questions/{$question->id}/close", ['status' => 'superseded', 'reason' => '   '])
        ->assertUnprocessable();
    $this->postJson("/api/v1/task-questions/{$question->id}/close", ['status' => 'superseded', 'reason' => str_repeat('a', 2001)])
        ->assertUnprocessable();
    $this->postJson('/api/v1/task-questions/999999/close', ['status' => 'superseded', 'reason' => 'Stale.'])
        ->assertNotFound();

    expect($question->fresh()?->status)->toBe(QuestionStatus::Escalated);
});

it('lists superseded questions', function (): void {
    $question = question_close_api_question();
    $this->postJson("/api/v1/task-questions/{$question->id}/close", ['status' => 'superseded', 'reason' => 'Stale.'])->assertOk();

    $this->getJson('/api/v1/task-questions?status=superseded')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $question->id);
    $this->getJson('/api/v1/task-questions?status=escalated')->assertOk()->assertJsonCount(0, 'data');
});

it('publishes the close operation as the tasks-question-close MCP tool', function (): void {
    $tools = json_decode((string) file_get_contents(base_path('resources/mcp/tools.json')), true, flags: JSON_THROW_ON_ERROR);
    $tool = collect($tools['tools'] ?? $tools)->firstWhere('name', 'tasks-question-close');

    expect($tool)->toBeArray()
        ->and($tool['method'] ?? null)->toBe('POST')
        ->and($tool['path'] ?? null)->toBe('/api/v1/task-questions/{question}/close')
        ->and($tool['input_schema']['properties']['status']['enum'] ?? null)->toBe(['answered', 'superseded'])
        ->and($tool['input_schema']['required'] ?? null)->toBe(['question', 'status', 'reason']);
});
