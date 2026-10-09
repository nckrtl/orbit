<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskAgentDefaults;
use App\Infrastructure\Tasks\Pi\PiModel;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;

function pi_only_project(): Project
{
    $gateway = Node::query()->create([
        'name' => 'pi-only-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80',
        'wireguard_ip' => '10.44.0.80',
    ]);
    test()->markAsGateway($gateway);
    test()->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    test()->postJson('/api/v1/extensions/tasks/enable')->assertOk();

    return Project::query()->create([
        'name' => 'pi-only',
        'slug' => 'pi-only',
        'repository_url' => 'git@example.test:pi-only.git',
        'default_branch' => 'main',
        'apps' => fixture_apps(null),
    ]);
}

it('records the pi driver and Pi-runnable models on a new group', function (): void {
    $project = pi_only_project();

    expect(config('orbit.tasks.implementer_agent_driver'))->toBe('pi')
        ->and(config('orbit.tasks.reviewer_agent_driver'))->toBe('pi')
        ->and(TaskAgentDefaults::ImplementerModel)->toBe('gpt-5.6-luna')
        ->and(TaskAgentDefaults::ReviewerModel)->toBe('gpt-5.6-luna')
        ->and(PiModel::forModel(TaskAgentDefaults::ImplementerModel))->toBe('openai-codex/gpt-5.6-luna')
        ->and(PiModel::forModel(TaskAgentDefaults::ReviewerModel))->toBe('openai-codex/gpt-5.6-luna');

    $this->postJson('/api/v1/task-groups', [
        'project_id' => $project->id,
        'title' => 'Pi only',
        'brief' => 'New groups record the pi driver.',
    ])->assertCreated()
        ->assertJsonPath('data.implementer_model', 'gpt-5.6-luna')
        ->assertJsonPath('data.reviewer_model', 'gpt-5.6-luna');

    $group = Task::topLevel()->where('title', 'Pi only')->sole();
    expect($group->implementer_agent_driver)->toBe('pi')
        ->and($group->reviewer_agent_driver)->toBe('pi')
        ->and($group->implementer_model)->toBe('gpt-5.6-luna')
        ->and($group->reviewer_model)->toBe('gpt-5.6-luna');
});

it('refuses a configured t3 driver and stores no task', function (string $role): void {
    $project = pi_only_project();
    config()->set('orbit.tasks.implementer_agent_driver', 'pi');
    config()->set('orbit.tasks.reviewer_agent_driver', 'pi');
    config()->set("orbit.tasks.{$role}_agent_driver", 't3');

    $this->postJson('/api/v1/task-groups', [
        'project_id' => $project->id,
        'title' => 'Refused',
        'brief' => 'A configured t3 driver stores nothing.',
        'tasks' => [
            ['title' => 'Should not be stored', 'brief' => 'No row.', 'deliverables' => [
                ['id' => 'none', 'type' => 'review', 'description' => 'Nothing is stored.'],
            ]],
        ],
    ])->assertStatus(409)->assertJsonPath('error.code', 'tasks.agent_driver_unavailable');

    $this->assertDatabaseCount('tasks', 0);
})->with(['implementer', 'reviewer']);
