<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskDefinition;

require_once __DIR__.'/../../Support/TaskDefinitions.php';

describe('task definition routes', function (): void {
    it('creates, lists, shows, replaces, and destroys a definition without starting a task', function (): void {
        task_definition_gateway();
        $project = task_definition_project();
        $other = task_definition_project('other-definitions');
        $definition = [
            'name' => 'app-maintenance',
            'title' => 'Maintain {app}',
            'brief' => 'Update {app}.',
            'parameters' => [
                ['name' => 'app', 'type' => 'text', 'required' => true],
            ],
            'status' => 'backlog',
            'schedule' => ['cron' => '0  3 * * 1', 'values' => ['app' => 'orbit']],
            'phases' => [
                ['key' => 'prepare', 'title' => 'Prepare', 'brief' => 'Prepare the work.', 'repeat' => false],
                ['key' => 'verify', 'title' => 'Verify', 'brief' => 'Verify the work.', 'repeat' => false],
            ],
            'subtasks' => [
                [
                    'key' => 'update',
                    'title' => 'Update dependencies',
                    'kind' => 'agent',
                    'phase' => 'prepare',
                    'brief' => 'Update the dependencies.',
                    'implementer_model' => 'claude-opus',
                    'reviewer_model' => 'claude-sonnet',
                    'routes' => ['skipped' => 'complete'],
                ],
                [
                    'key' => 'size',
                    'title' => 'Is this a major upgrade?',
                    'kind' => 'decide',
                    'phase' => 'prepare',
                    'question' => 'Is this a major upgrade?',
                    'options' => ['major', 'minor'],
                    'evidence' => ['update'],
                    'min_probability' => 0.9,
                    'routes' => ['major' => 'review', 'minor' => 'browser'],
                ],
                [
                    'key' => 'review',
                    'title' => 'Adapt the app',
                    'kind' => 'agent',
                    'phase' => 'verify',
                    'routes' => ['passed' => 'browser'],
                ],
                [
                    'key' => 'browser',
                    'title' => 'Browser tests',
                    'kind' => 'check',
                    'phase' => 'verify',
                    'deliverables' => [
                        ['id' => 'browser', 'type' => 'command', 'description' => 'Run browser tests', 'command' => 'bin/browser', 'directory' => '.'],
                    ],
                ],
                [
                    'key' => 'deploy',
                    'title' => 'Deploy',
                    'kind' => 'action',
                    'operation' => 'instance:deploy',
                    'arguments' => ['instance' => 1],
                ],
                [
                    'key' => 'ship',
                    'title' => 'Merge',
                    'kind' => 'merge',
                ],
            ],
        ];

        $this->postJson("/api/v1/projects/{$project->id}/task-definitions", $definition)
            ->assertCreated()
            ->assertJsonPath('data.project_id', $project->id)
            ->assertJsonPath('data.name', 'app-maintenance')
            ->assertJsonPath('data.title', 'Maintain {app}')
            ->assertJsonPath('data.status', 'backlog')
            ->assertJsonPath('data.schedule.cron', '0 3 * * 1')
            ->assertJsonPath('data.schedule.values.app', 'orbit')
            ->assertJsonPath('data.subtasks.1.kind', 'decide')
            ->assertJsonPath('data.subtasks.3.deliverables.0.command', 'bin/browser')
            ->assertJsonPath('data.subtasks.4.operation', 'instance:deploy');

        $this->postJson("/api/v1/projects/{$other->id}/task-definitions", task_definition_payload([
            'name' => 'app-maintenance',
            'title' => 'Other',
        ]))->assertCreated();

        expect(Task::query()->count())->toBe(0);

        $this->getJson('/api/v1/task-definitions')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'app-maintenance')
            ->assertJsonPath('data.0.project_id', $project->id)
            ->assertJsonPath('data.1.name', 'app-maintenance')
            ->assertJsonPath('data.1.project_id', $other->id);

        $this->getJson('/api/v1/task-definitions?project_id='.$project->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.project_id', $project->id);

        $this->getJson("/api/v1/projects/{$project->id}/task-definitions/app-maintenance")
            ->assertOk()
            ->assertJsonPath('data.brief', 'Update {app}.')
            ->assertJsonPath('data.subtasks.5.kind', 'merge');

        $replacement = task_definition_payload([
            'name' => 'app-maintenance',
            'title' => 'Maintain the app',
            'brief' => 'A shorter plan.',
            'status' => 'todo',
            'subtasks' => [
                ['key' => 'only', 'title' => 'Only step', 'kind' => 'agent'],
            ],
        ]);

        $this->putJson("/api/v1/projects/{$project->id}/task-definitions/app-maintenance", $replacement)
            ->assertOk()
            ->assertJsonPath('data.title', 'Maintain the app')
            ->assertJsonPath('data.status', 'todo')
            ->assertJsonPath('data.schedule', null)
            ->assertJsonPath('data.subtasks.0.key', 'only')
            ->assertJsonMissingPath('data.subtasks.1');

        expect(Task::query()->count())->toBe(0)
            ->and(TaskDefinition::query()->where('project_id', $project->id)->value('title'))->toBe('Maintain the app');

        $this->deleteJson("/api/v1/projects/{$project->id}/task-definitions/app-maintenance")
            ->assertOk()
            ->assertJsonPath('data.name', 'app-maintenance')
            ->assertJsonPath('data.title', 'Maintain the app');

        $this->getJson("/api/v1/projects/{$project->id}/task-definitions/app-maintenance")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'http.404');

        expect(TaskDefinition::query()->where('project_id', $project->id)->count())->toBe(0)
            ->and(TaskDefinition::query()->where('project_id', $other->id)->count())->toBe(1)
            ->and(Task::query()->count())->toBe(0);
    });

    it('stores file deliverable brace-alternation globs', function (): void {
        task_definition_gateway();
        $project = task_definition_project('brace-globs');
        $deliverables = [
            ['id' => 'pantry-sync', 'type' => 'file', 'description' => 'Pantry sync types', 'path' => 'app/{Data,Enums}/PantrySync/**/*.php', 'change' => 'any'],
            ['id' => 'integration', 'type' => 'file', 'description' => 'Integration screens', 'path' => 'resources/js/**/*Pantry*Integration*.{php,tsx}', 'change' => 'any'],
            ['id' => 'tasks-domain', 'type' => 'file', 'description' => 'Task domain', 'path' => 'apps/gateway/app/Domain/Tasks/**/*.{php}', 'change' => 'any'],
        ];

        $this->postJson("/api/v1/projects/{$project->id}/task-definitions", task_definition_payload([
            'name' => 'brace-globs',
            'subtasks' => [
                ['key' => 'docs', 'title' => 'Write the docs', 'kind' => 'agent', 'deliverables' => $deliverables],
            ],
        ]))->assertCreated()->assertJsonPath('data.subtasks.0.deliverables', $deliverables);

        $this->putJson("/api/v1/projects/{$project->id}/task-definitions/brace-globs", task_definition_payload([
            'name' => 'brace-globs',
            'title' => 'Keep the globs',
            'subtasks' => [
                ['key' => 'docs', 'title' => 'Write the docs', 'kind' => 'agent', 'deliverables' => $deliverables],
            ],
        ]))->assertOk()->assertJsonPath('data.subtasks.0.deliverables', $deliverables);
    });

    it('returns 409 tasks.definition_exists when the Project already uses the name', function (): void {
        task_definition_gateway();
        $project = task_definition_project();

        $this->postJson("/api/v1/projects/{$project->id}/task-definitions", task_definition_payload())
            ->assertCreated();

        $this->postJson("/api/v1/projects/{$project->id}/task-definitions", task_definition_payload([
            'title' => 'Again',
        ]))
            ->assertConflict()
            ->assertJsonPath('error.code', 'tasks.definition_exists')
            ->assertJsonPath('error.message', 'The Project already uses this task definition name.')
            ->assertJsonPath('error.details.name', 'build-feature');

        expect(TaskDefinition::query()->count())->toBe(1)
            ->and(TaskDefinition::query()->value('title'))->toBe('Build a feature');
    });

    it('returns 409 extension.disabled for every definition route and changes nothing', function (): void {
        task_definition_gateway();
        $project = task_definition_project();
        $this->postJson("/api/v1/projects/{$project->id}/task-definitions", task_definition_payload())
            ->assertCreated();
        app(TaskExtensionState::class)->disable();

        $this->getJson('/api/v1/task-definitions')
            ->assertConflict()
            ->assertJsonPath('error.code', 'extension.disabled');
        $this->getJson("/api/v1/projects/{$project->id}/task-definitions/build-feature")
            ->assertConflict()
            ->assertJsonPath('error.code', 'extension.disabled');
        $this->postJson("/api/v1/projects/{$project->id}/task-definitions", task_definition_payload(['name' => 'another']))
            ->assertConflict()
            ->assertJsonPath('error.code', 'extension.disabled');
        $this->putJson("/api/v1/projects/{$project->id}/task-definitions/build-feature", task_definition_payload([
            'title' => 'Changed',
        ]))
            ->assertConflict()
            ->assertJsonPath('error.code', 'extension.disabled');
        $this->deleteJson("/api/v1/projects/{$project->id}/task-definitions/build-feature")
            ->assertConflict()
            ->assertJsonPath('error.code', 'extension.disabled');

        expect(TaskDefinition::query()->count())->toBe(1)
            ->and(TaskDefinition::query()->value('title'))->toBe('Build a feature');
    });

    it('lets any authorized peer list and show, and only Gateway access write', function (): void {
        $gateway = task_definition_gateway();
        $project = task_definition_project();
        $other = Node::query()->create([
            'name' => 'definition-other',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.91',
            'wireguard_ip' => '10.44.0.91',
        ]);
        $reader = Node::query()->create([
            'name' => 'definition-reader',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.92',
            'wireguard_ip' => '10.44.0.92',
        ]);
        $locked = Node::query()->create([
            'name' => 'definition-locked',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.93',
            'wireguard_ip' => '10.44.0.93',
        ]);
        $granted = Node::query()->create([
            'name' => 'definition-granted',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.94',
            'wireguard_ip' => '10.44.0.94',
        ]);
        $reader->accessibleNodes()->attach($other);
        $granted->accessibleNodes()->attach($gateway);

        $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
            ->postJson("/api/v1/projects/{$project->id}/task-definitions", task_definition_payload())
            ->assertCreated();

        $this->withServerVariables(['REMOTE_ADDR' => $reader->wireguard_ip])
            ->getJson('/api/v1/task-definitions')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'build-feature');
        $this->withServerVariables(['REMOTE_ADDR' => $reader->wireguard_ip])
            ->getJson("/api/v1/projects/{$project->id}/task-definitions/build-feature")
            ->assertOk()
            ->assertJsonPath('data.title', 'Build a feature');
        $this->withServerVariables(['REMOTE_ADDR' => $reader->wireguard_ip])
            ->postJson("/api/v1/projects/{$project->id}/task-definitions", task_definition_payload(['name' => 'reader-write']))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'node_access.required');
        $this->withServerVariables(['REMOTE_ADDR' => $reader->wireguard_ip])
            ->putJson("/api/v1/projects/{$project->id}/task-definitions/build-feature", task_definition_payload([
                'title' => 'Reader edit',
            ]))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'node_access.required');
        $this->withServerVariables(['REMOTE_ADDR' => $reader->wireguard_ip])
            ->deleteJson("/api/v1/projects/{$project->id}/task-definitions/build-feature")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'node_access.required');

        $this->withServerVariables(['REMOTE_ADDR' => $locked->wireguard_ip])
            ->getJson('/api/v1/task-definitions')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'node_access.required');
        $this->withServerVariables(['REMOTE_ADDR' => $locked->wireguard_ip])
            ->getJson("/api/v1/projects/{$project->id}/task-definitions/build-feature")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'node_access.required');

        $this->withServerVariables(['REMOTE_ADDR' => $granted->wireguard_ip])
            ->postJson("/api/v1/projects/{$project->id}/task-definitions", task_definition_payload(['name' => 'granted-write']))
            ->assertCreated()
            ->assertJsonPath('data.name', 'granted-write');

        expect(TaskDefinition::query()->where('name', 'build-feature')->value('title'))->toBe('Build a feature')
            ->and(TaskDefinition::query()->pluck('name')->all())->toEqualCanonicalizing(['build-feature', 'granted-write']);
    });

    it('returns 404 when the definition is missing and 422 when the replacement name differs', function (): void {
        task_definition_gateway();
        $project = task_definition_project();
        $this->postJson("/api/v1/projects/{$project->id}/task-definitions", task_definition_payload())
            ->assertCreated();

        $this->getJson("/api/v1/projects/{$project->id}/task-definitions/missing")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'http.404');
        $this->putJson("/api/v1/projects/{$project->id}/task-definitions/missing", task_definition_payload(['name' => 'missing']))
            ->assertNotFound();
        $this->deleteJson("/api/v1/projects/{$project->id}/task-definitions/missing")
            ->assertNotFound();
        $this->getJson('/api/v1/projects/999999/task-definitions/build-feature')
            ->assertNotFound();

        $this->putJson("/api/v1/projects/{$project->id}/task-definitions/build-feature", task_definition_payload([
            'name' => 'renamed',
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonPath('error.details.name.0', 'The definition name must match the name in the path.');

        $this->getJson('/api/v1/task-definitions?project_id=nope')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        expect(TaskDefinition::query()->count())->toBe(1)
            ->and(TaskDefinition::query()->value('name'))->toBe('build-feature');
    });

    it('keeps a nested empty object in arguments and a parameter default', function (): void {
        task_definition_gateway();
        $project = task_definition_project();
        $url = "/api/v1/projects/{$project->id}/task-definitions";
        $object = '{"options":{},"flags":[]}';

        $created = $this->postJson($url, task_definition_payload([
            'parameters' => [
                [
                    'name' => 'tuning',
                    'type' => 'text',
                    'required' => false,
                    'default' => ['options' => (object) [], 'flags' => []],
                ],
            ],
            'subtasks' => [
                [
                    'key' => 'ship',
                    'title' => 'Deploy',
                    'kind' => 'action',
                    'operation' => 'instance:deploy',
                    'arguments' => ['options' => (object) [], 'flags' => []],
                ],
            ],
        ]))->assertCreated();

        expect($created->getContent())->toContain('"default":'.$object)->toContain('"arguments":'.$object);

        $shown = $this->getJson($url.'/build-feature')->assertOk();
        expect($shown->getContent())->toContain('"default":'.$object)->toContain('"arguments":'.$object);

        $document = json_decode($shown->getContent(), false, 512, JSON_THROW_ON_ERROR);
        unset($document->data->project_id);

        $replaced = $this->call(
            'PUT',
            $url.'/build-feature',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode($document->data, JSON_THROW_ON_ERROR),
        );

        $replaced->assertOk();
        expect($replaced->getContent())->toContain('"default":'.$object)->toContain('"arguments":'.$object);

        $again = $this->getJson($url.'/build-feature')->assertOk();
        expect($again->getContent())->toContain('"default":'.$object)->toContain('"arguments":'.$object);
    });

    it('reads an empty schedule values object back as an object', function (): void {
        task_definition_gateway();
        $project = task_definition_project();

        $response = $this->postJson("/api/v1/projects/{$project->id}/task-definitions", task_definition_payload([
            'schedule' => ['cron' => '0 3 * * 1', 'values' => (object) []],
        ]))->assertCreated();

        expect($response->getContent())->toContain('"values":{}');
    });

    it('does not replace a definition when the replacement is invalid', function (): void {
        task_definition_gateway();
        $project = task_definition_project();
        $this->postJson("/api/v1/projects/{$project->id}/task-definitions", task_definition_payload())
            ->assertCreated();

        $this->putJson("/api/v1/projects/{$project->id}/task-definitions/build-feature", task_definition_payload([
            'subtasks' => [
                ['key' => 'docs', 'title' => 'Write the docs', 'kind' => 'nope'],
            ],
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'tasks.definition_invalid')
            ->assertJsonPath('error.details.rules.0.rule', 'kind')
            ->assertJsonPath('error.details.rules.0.subtask', 'docs');

        expect(TaskDefinition::query()->value('title'))->toBe('Build a feature')
            ->and(Task::query()->count())->toBe(0);
    });
});
