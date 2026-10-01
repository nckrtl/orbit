<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Orbit\Sdk\Requests\Tasks\CreateTaskDefinitionRequest;
use Orbit\Sdk\Requests\Tasks\DestroyTaskDefinitionRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskDefinitionsRequest;
use Orbit\Sdk\Requests\Tasks\ShowTaskDefinitionRequest;
use Orbit\Sdk\Requests\Tasks\UpdateTaskDefinitionRequest;

require_once __DIR__.'/../../Support/TaskDefinitions.php';

/**
 * Records the task definition responses that the SDK and the CLI replay.
 * The Project is id 1 and the request id is fixed, so the files stay stable.
 */
describe('task definition response fixtures', function (): void {
    beforeEach(function (): void {
        $this->travelTo(Carbon::parse('2026-09-23T10:00:00Z'));
        task_definition_gateway('gateway', '10.44.0.1');
        $this->withHeader('X-Orbit-Request-Id', fixture_request_id());
        $this->project = task_definition_project('orbit');
        expect($this->project->id)->toBe(1);
    });

    it('records an empty definition list', function (): void {
        record_fixture(
            $this->getJson('/api/v1/task-definitions')->assertOk(),
            'tasks/tasks-definition-list/empty',
            ListTaskDefinitionsRequest::class,
            'GET /api/v1/task-definitions',
        );
    });

    it('records a definition list, one definition, a replacement, and its deletion', function (): void {
        $created = $this->postJson('/api/v1/projects/1/task-definitions', task_definition_payload())->assertCreated();
        record_fixture($created, 'tasks/tasks-definition-create/created', CreateTaskDefinitionRequest::class, 'POST /api/v1/projects/{project}/task-definitions');
        record_fixture(
            $this->getJson('/api/v1/task-definitions?project_id=1')->assertOk(),
            'tasks/tasks-definition-list/default',
            ListTaskDefinitionsRequest::class,
            'GET /api/v1/task-definitions',
        );
        record_fixture(
            $this->getJson('/api/v1/projects/1/task-definitions/build-feature')->assertOk(),
            'tasks/tasks-definition-show/default',
            ShowTaskDefinitionRequest::class,
            'GET /api/v1/projects/{project}/task-definitions/{name}',
        );

        $replacement = task_definition_payload([
            'title' => 'Build the feature',
            'status' => 'todo',
        ]);
        record_fixture(
            $this->putJson('/api/v1/projects/1/task-definitions/build-feature', $replacement)->assertOk(),
            'tasks/tasks-definition-update/updated',
            UpdateTaskDefinitionRequest::class,
            'PUT /api/v1/projects/{project}/task-definitions/{name}',
        );
        record_fixture(
            $this->deleteJson('/api/v1/projects/1/task-definitions/build-feature')->assertOk(),
            'tasks/tasks-definition-destroy/destroyed',
            DestroyTaskDefinitionRequest::class,
            'DELETE /api/v1/projects/{project}/task-definitions/{name}',
        );
    });

    it('records a refused invalid definition and a name the Project already uses', function (): void {
        record_fixture(
            $this->postJson('/api/v1/projects/1/task-definitions', task_definition_payload([
                'subtasks' => [
                    ['key' => 'docs', 'title' => 'Write the docs', 'kind' => 'nope'],
                ],
            ]))->assertUnprocessable(),
            'tasks/tasks-definition-create/invalid',
            CreateTaskDefinitionRequest::class,
            'POST /api/v1/projects/{project}/task-definitions',
        );

        $this->postJson('/api/v1/projects/1/task-definitions', task_definition_payload())->assertCreated();
        record_fixture(
            $this->postJson('/api/v1/projects/1/task-definitions', task_definition_payload())->assertConflict(),
            'tasks/tasks-definition-create/exists',
            CreateTaskDefinitionRequest::class,
            'POST /api/v1/projects/{project}/task-definitions',
        );
    });

    it('records a missing definition', function (): void {
        record_fixture(
            $this->getJson('/api/v1/projects/1/task-definitions/missing')->assertNotFound(),
            'tasks/tasks-definition-show/missing',
            ShowTaskDefinitionRequest::class,
            'GET /api/v1/projects/{project}/task-definitions/{name}',
        );
    });
});
