<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskDefinition;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../Support/TaskDefinitions.php';

/**
 * @param  array<string, mixed>  $definition
 * @param  list<array{rule: string, subtask: string|null}>  $rules
 */
function reject_task_definition(Project $project, array $definition, array $rules): TestResponse
{
    $response = test()->postJson("/api/v1/projects/{$project->id}/task-definitions", $definition)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'tasks.definition_invalid')
        ->assertJsonPath('error.message', 'The task definition is invalid.')
        ->assertJsonPath('error.details.rules', $rules);

    expect(TaskDefinition::query()->count())->toBe(0)
        ->and(Task::query()->count())->toBe(0);

    return $response;
}

describe('task definition validation', function (): void {
    it('returns 422 tasks.definition_invalid when a subtask key is duplicated', function (): void {
        task_definition_gateway();
        $project = task_definition_project();

        reject_task_definition($project, task_definition_payload([
            'subtasks' => [
                ['key' => 'docs', 'title' => 'Write the docs', 'kind' => 'agent'],
                ['key' => 'docs', 'title' => 'Write them again', 'kind' => 'agent'],
            ],
        ]), [
            ['rule' => 'keys', 'subtask' => 'docs'],
        ]);
    });

    it('returns 422 tasks.definition_invalid when a subtask kind is unknown', function (): void {
        task_definition_gateway();
        $project = task_definition_project();

        reject_task_definition($project, task_definition_payload([
            'subtasks' => [
                ['key' => 'docs', 'title' => 'Write the docs', 'kind' => 'nope'],
            ],
        ]), [
            ['rule' => 'kind', 'subtask' => 'docs'],
        ]);
    });

    it('returns 422 tasks.definition_invalid when a subtask uses a field its kind does not declare', function (): void {
        task_definition_gateway();
        $project = task_definition_project();

        reject_task_definition($project, task_definition_payload([
            'subtasks' => [
                [
                    'key' => 'docs',
                    'title' => 'Write the docs',
                    'kind' => 'agent',
                    'operation' => 'instance:deploy',
                    'arguments' => ['instance' => 1],
                ],
            ],
        ]), [
            ['rule' => 'fields', 'subtask' => 'docs'],
        ]);
    });

    it('returns 422 tasks.definition_invalid when a subtask is missing a field its kind requires', function (): void {
        task_definition_gateway();
        $project = task_definition_project();

        reject_task_definition($project, task_definition_payload([
            'subtasks' => [
                ['key' => 'ship', 'title' => 'Deploy', 'kind' => 'action'],
            ],
        ]), [
            ['rule' => 'fields', 'subtask' => 'ship'],
        ]);
    });

    it('returns 422 tasks.definition_invalid when a route names an outcome the kind does not declare', function (): void {
        task_definition_gateway();
        $project = task_definition_project();

        reject_task_definition($project, task_definition_payload([
            'subtasks' => [
                ['key' => 'docs', 'title' => 'Write the docs', 'kind' => 'agent', 'routes' => ['nope' => 'complete']],
            ],
        ]), [
            ['rule' => 'route_outcome', 'subtask' => 'docs'],
        ]);
    });

    it('returns 422 tasks.definition_invalid when a route target is not a later subtask', function (array $subtasks): void {
        task_definition_gateway();
        $project = task_definition_project();

        reject_task_definition($project, task_definition_payload(['subtasks' => $subtasks]), [
            ['rule' => 'route_target', 'subtask' => 'docs'],
        ]);
    })->with([
        'unknown key' => [[
            ['key' => 'docs', 'title' => 'Write the docs', 'kind' => 'agent', 'routes' => ['passed' => 'missing']],
        ]],
        'the same subtask' => [[
            ['key' => 'docs', 'title' => 'Write the docs', 'kind' => 'agent', 'routes' => ['passed' => 'docs']],
        ]],
        'an earlier subtask' => [[
            ['key' => 'plan', 'title' => 'Plan', 'kind' => 'agent'],
            ['key' => 'docs', 'title' => 'Write the docs', 'kind' => 'agent', 'routes' => ['passed' => 'plan']],
        ]],
    ]);

    it('returns 422 tasks.definition_invalid when a decide subtask has no route for an option', function (): void {
        task_definition_gateway();
        $project = task_definition_project();

        reject_task_definition($project, task_definition_payload([
            'subtasks' => [
                ['key' => 'docs', 'title' => 'Write the docs', 'kind' => 'agent'],
                [
                    'key' => 'choose',
                    'title' => 'Choose',
                    'kind' => 'decide',
                    'question' => 'Ship it?',
                    'options' => ['yes', 'no'],
                    'evidence' => ['docs'],
                    'routes' => ['yes' => 'complete'],
                ],
            ],
        ]), [
            ['rule' => 'decide_routes', 'subtask' => 'choose'],
        ]);
    });

    it('returns 422 tasks.definition_invalid when no path from the first subtask reaches a subtask', function (): void {
        task_definition_gateway();
        $project = task_definition_project();

        reject_task_definition($project, task_definition_payload([
            'subtasks' => [
                ['key' => 'docs', 'title' => 'Write the docs', 'kind' => 'agent', 'routes' => ['passed' => 'ship']],
                ['key' => 'review', 'title' => 'Review', 'kind' => 'agent'],
                ['key' => 'ship', 'title' => 'Merge', 'kind' => 'merge'],
            ],
        ]), [
            ['rule' => 'reachability', 'subtask' => 'review'],
        ]);
    });

    it('returns 422 tasks.definition_invalid when a subtask phase is not in phases', function (): void {
        task_definition_gateway();
        $project = task_definition_project();

        reject_task_definition($project, task_definition_payload([
            'phases' => [
                ['key' => 'prepare', 'title' => 'Prepare', 'brief' => 'Prepare the work.', 'repeat' => false],
            ],
            'subtasks' => [
                ['key' => 'docs', 'title' => 'Write the docs', 'kind' => 'agent', 'phase' => 'missing'],
            ],
        ]), [
            ['rule' => 'phases', 'subtask' => 'docs'],
        ]);
    });

    it('returns 422 tasks.definition_invalid when one phase is not a contiguous run of subtasks', function (): void {
        task_definition_gateway();
        $project = task_definition_project();

        reject_task_definition($project, task_definition_payload([
            'phases' => [
                ['key' => 'prepare', 'title' => 'Prepare', 'brief' => 'Prepare the work.', 'repeat' => false],
                ['key' => 'verify', 'title' => 'Verify', 'brief' => 'Verify the work.', 'repeat' => true],
            ],
            'subtasks' => [
                ['key' => 'docs', 'title' => 'Write the docs', 'kind' => 'agent', 'phase' => 'prepare'],
                ['key' => 'check', 'title' => 'Check', 'kind' => 'check', 'phase' => 'verify', 'deliverables' => [
                    ['id' => 'check', 'type' => 'command', 'description' => 'Run the check', 'command' => 'true'],
                ]],
                ['key' => 'revise', 'title' => 'Revise', 'kind' => 'agent', 'phase' => 'prepare'],
            ],
        ]), [
            ['rule' => 'phases', 'subtask' => 'docs'],
            ['rule' => 'phases', 'subtask' => 'revise'],
        ]);
    });

    it('returns 422 tasks.definition_invalid when an action operation is not marked as a task action', function (): void {
        task_definition_gateway();
        $project = task_definition_project();

        reject_task_definition($project, task_definition_payload([
            'subtasks' => [
                [
                    'key' => 'deploy',
                    'title' => 'Deploy',
                    'kind' => 'action',
                    'operation' => 'instance:logs',
                    'arguments' => ['instance' => 1],
                ],
            ],
        ]), [
            ['rule' => 'action', 'subtask' => 'deploy'],
        ]);
    });

    it('returns 422 tasks.definition_invalid when a placeholder is not declared', function (): void {
        task_definition_gateway();
        $project = task_definition_project();

        reject_task_definition($project, task_definition_payload([
            'title' => 'Build {app}',
        ]), [
            ['rule' => 'parameters', 'subtask' => null],
        ]);
    });

    it('returns 422 tasks.definition_invalid when more than one parameter has type subtasks', function (): void {
        task_definition_gateway();
        $project = task_definition_project();

        reject_task_definition($project, task_definition_payload([
            'parameters' => [
                ['name' => 'features', 'type' => 'subtasks', 'required' => false],
                ['name' => 'extra', 'type' => 'subtasks', 'required' => false],
            ],
        ]), [
            ['rule' => 'parameters', 'subtask' => null],
        ]);
    });

    it('returns 422 tasks.definition_invalid when a schedule value names an undeclared parameter', function (): void {
        task_definition_gateway();
        $project = task_definition_project();

        reject_task_definition($project, task_definition_payload([
            'schedule' => ['cron' => '0 3 * * 1', 'values' => ['missing' => 'orbit']],
        ]), [
            ['rule' => 'schedule_names', 'subtask' => null],
        ]);
    });

    it('returns 422 tasks.definition_invalid when the cron expression is not five valid fields', function (string $cron): void {
        task_definition_gateway();
        $project = task_definition_project();

        reject_task_definition($project, task_definition_payload([
            'schedule' => ['cron' => $cron, 'values' => []],
        ]), [
            ['rule' => 'cron', 'subtask' => null],
        ]);
    })->with([
        'not five fields' => '* * * *',
        'an out of range field' => '60 * * * *',
        'a shortcut instead of five fields' => '@hourly',
        'prose' => 'not-a-cron',
    ]);

    it('returns 422 tasks.definition_invalid when the schedule omits a required parameter', function (): void {
        task_definition_gateway();
        $project = task_definition_project();

        reject_task_definition($project, task_definition_payload([
            'title' => 'Maintain {app}',
            'parameters' => [
                ['name' => 'app', 'type' => 'text', 'required' => true],
            ],
            'schedule' => ['cron' => '0 3 * * 1', 'values' => []],
        ]), [
            ['rule' => 'schedule_values', 'subtask' => null],
        ]);
    });

    it('returns 422 tasks.definition_invalid naming every broken rule', function (): void {
        task_definition_gateway();
        $project = task_definition_project();

        reject_task_definition($project, task_definition_payload([
            'title' => 'Build {app}',
            'schedule' => ['cron' => 'not-a-cron', 'values' => []],
            'subtasks' => [
                ['key' => 'docs', 'title' => 'Write the docs', 'kind' => 'agent'],
                ['key' => 'docs', 'title' => 'Write them again', 'kind' => 'agent'],
            ],
        ]), [
            ['rule' => 'keys', 'subtask' => 'docs'],
            ['rule' => 'parameters', 'subtask' => null],
            ['rule' => 'cron', 'subtask' => null],
        ]);
    });

    it('stores a definition that uses marked task actions and does not refuse models', function (string $operation): void {
        task_definition_gateway();
        $project = task_definition_project('marked-'.str_replace(':', '-', $operation));

        test()->postJson("/api/v1/projects/{$project->id}/task-definitions", task_definition_payload([
            'name' => 'run-'.str_replace(':', '-', $operation),
            'subtasks' => [
                [
                    'key' => 'run',
                    'title' => 'Run',
                    'kind' => 'agent',
                    'implementer_model' => 'claude-opus',
                    'reviewer_model' => 'claude-sonnet',
                ],
                [
                    'key' => 'act',
                    'title' => 'Act',
                    'kind' => 'action',
                    'operation' => $operation,
                    'arguments' => ['instance' => 1],
                ],
            ],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.subtasks.0.implementer_model', 'claude-opus')
            ->assertJsonPath('data.subtasks.0.reviewer_model', 'claude-sonnet')
            ->assertJsonPath('data.subtasks.1.operation', $operation);

        expect(Task::query()->count())->toBe(0);
    })->with(['instance:deploy', 'instance:rollback']);

    it('reads task action marks from the OpenAPI document', function (): void {
        $path = dirname(base_path(), 2).'/docs/openapi.json';
        $decoded = json_decode((string) file_get_contents($path), true);
        $marked = [];

        foreach (is_array($decoded) ? ($decoded['paths'] ?? []) : [] as $pathItem) {
            if (! is_array($pathItem)) {
                continue;
            }

            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                $operation = $pathItem[$method] ?? null;

                if (is_array($operation) && ($operation['x-orbit-task-action'] ?? false) === true) {
                    $marked[] = $operation['operationId'] ?? null;
                }
            }
        }

        expect($marked)->toEqualCanonicalizing(['instance-deploy', 'instance-rollback']);
    });

    it('returns 422 tasks.definition_invalid when an action operation is the OpenAPI summary instead of the route name', function (): void {
        task_definition_gateway();
        $project = task_definition_project();

        reject_task_definition($project, task_definition_payload([
            'subtasks' => [
                [
                    'key' => 'deploy',
                    'title' => 'Deploy',
                    'kind' => 'action',
                    'operation' => 'Deploy an Instance',
                    'arguments' => ['instance' => 1],
                ],
            ],
        ]), [
            ['rule' => 'action', 'subtask' => 'deploy'],
        ]);
    });

    it('returns 422 validation.failed when a parameter, phase, or schedule has an unknown member', function (string $field, array $definition): void {
        task_definition_gateway();
        $project = task_definition_project();

        $response = test()->postJson("/api/v1/projects/{$project->id}/task-definitions", $definition)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        expect($response->json('error.details'))->toHaveKey($field);

        expect(TaskDefinition::query()->count())->toBe(0);
    })->with([
        'parameter' => ['parameters.0', task_definition_payload([
            'parameters' => [
                ['name' => 'app', 'type' => 'text', 'required' => true, 'defualt' => 'orbit'],
            ],
        ])],
        'phase' => ['phases.0', task_definition_payload([
            'phases' => [
                ['key' => 'prepare', 'title' => 'Prepare', 'brief' => 'Prepare the work.', 'repeat' => false, 'note' => 'extra'],
            ],
            'subtasks' => [
                ['key' => 'docs', 'title' => 'Write the docs', 'kind' => 'agent', 'phase' => 'prepare'],
            ],
        ])],
        'schedule' => ['schedule', task_definition_payload([
            'schedule' => ['cron' => '0 3 * * 1', 'values' => [], 'zone' => 'UTC'],
        ])],
    ]);

    it('returns 422 validation.failed when a deliverable breaks the deliverable contract', function (): void {
        task_definition_gateway();
        $project = task_definition_project();

        test()->postJson("/api/v1/projects/{$project->id}/task-definitions", task_definition_payload([
            'subtasks' => [
                [
                    'key' => 'docs',
                    'title' => 'Write the docs',
                    'kind' => 'agent',
                    'deliverables' => [
                        ['id' => 'reference', 'type' => 'file', 'description' => 'The page'],
                    ],
                ],
            ],
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        expect(TaskDefinition::query()->count())->toBe(0);
    });
});
