<?php

declare(strict_types=1);

use App\Domain\Tasks\OpenApiTaskActions;
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

/** @return list<array<string, mixed>> */
function task_definition_subtasks(int $count): array
{
    $subtasks = [];

    for ($index = 0; $index < $count; $index++) {
        $subtasks[] = ['key' => 'step-'.$index, 'title' => 'Step', 'kind' => 'agent'];
    }

    return $subtasks;
}

/** @return list<array<string, mixed>> */
function task_definition_parameters(int $count): array
{
    $parameters = [];

    for ($index = 0; $index < $count; $index++) {
        $parameters[] = ['name' => 'param-'.$index, 'type' => 'text', 'required' => false];
    }

    return $parameters;
}

/** @return list<array<string, mixed>> */
function task_definition_phases(int $count): array
{
    $phases = [];

    for ($index = 0; $index < $count; $index++) {
        $phases[] = ['key' => 'phase-'.$index, 'title' => 'Phase', 'brief' => 'Group the work.', 'repeat' => false];
    }

    return $phases;
}

/** @return array<string, int> */
function task_definition_arguments(int $count): array
{
    $arguments = [];

    for ($index = 0; $index < $count; $index++) {
        $arguments['arg-'.$index] = 1;
    }

    return $arguments;
}

/** @return array<string, string> */
function task_definition_values(int $count): array
{
    $values = [];

    for ($index = 0; $index < $count; $index++) {
        $values['value-'.$index] = 'set';
    }

    return $values;
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

    it('refuses reserved subtask keys', function (): void {
        task_definition_gateway();
        $project = task_definition_project();

        foreach (['complete', 'fail'] as $key) {
            reject_task_definition($project, task_definition_payload([
                'subtasks' => [
                    ['key' => $key, 'title' => 'End', 'kind' => 'agent'],
                ],
            ]), [
                ['rule' => 'keys', 'subtask' => $key],
            ]);
        }
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

    it('returns 422 tasks.definition_invalid when a parameter name is duplicated', function (): void {
        task_definition_gateway();
        $project = task_definition_project();

        reject_task_definition($project, task_definition_payload([
            'parameters' => [
                ['name' => 'app', 'type' => 'text', 'required' => true],
                ['name' => 'app', 'type' => 'app', 'required' => false],
            ],
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

    it('returns 422 tasks.definition_invalid when a model name is empty', function (string $field): void {
        task_definition_gateway();
        $project = task_definition_project();

        reject_task_definition($project, task_definition_payload([
            'subtasks' => [
                ['key' => 'docs', 'title' => 'Write the docs', 'kind' => 'agent', $field => ''],
            ],
        ]), [
            ['rule' => 'fields', 'subtask' => 'docs'],
        ]);
    })->with(['implementer_model', 'reviewer_model']);

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

    it('reads task action marks from the generated list, matching the OpenAPI document', function (): void {
        $path = dirname(base_path(), 2).'/docs/openapi.json';
        $decoded = json_decode((string) file_get_contents($path), true);
        $marked = [];

        foreach (is_array($decoded) ? ($decoded['paths'] ?? []) : [] as $pathItem) {
            if (! is_array($pathItem)) {
                continue;
            }

            foreach (['get', 'post', 'put', 'patch', 'delete', 'head'] as $method) {
                $operation = $pathItem[$method] ?? null;

                if (is_array($operation) && ($operation['x-orbit-task-action'] ?? false) === true) {
                    $marked[] = $operation['operationId'] ?? null;
                }
            }
        }

        $listed = json_decode((string) file_get_contents(resource_path('tasks/actions.json')), true);

        expect($marked)->toEqualCanonicalizing(['instance-deploy', 'instance-rollback'])
            ->and($listed['actions'] ?? null)->toEqualCanonicalizing($marked)
            ->and(app(OpenApiTaskActions::class)->names())->toBe($listed['actions']);
    });

    it('reads task actions from the list file it is given', function (): void {
        $path = tempnam(sys_get_temp_dir(), 'task-actions-');
        expect($path)->toBeString();

        try {
            file_put_contents($path, json_encode(['actions' => ['instance-deploy', 'widget-rebuild']], JSON_THROW_ON_ERROR));
            $actions = new OpenApiTaskActions($path);

            expect($actions->names())->toBe(['instance-deploy', 'widget-rebuild'])
                ->and($actions->allows('widget:rebuild'))->toBeTrue()
                ->and($actions->allows('instance:rollback'))->toBeFalse();
        } finally {
            if (is_string($path)) {
                unlink($path);
            }
        }
    });

    it('marks parameters required on definition create, update, and the task definition schema', function (): void {
        $root = dirname(base_path(), 2);
        $document = json_decode((string) file_get_contents($root.'/docs/openapi.json'), true);
        $tools = json_decode((string) file_get_contents(resource_path('mcp/tools.json')), true);
        $create = $document['paths']['/api/v1/projects/{project}/task-definitions']['post']['requestBody']['content']['application/json']['schema'];
        $update = $document['paths']['/api/v1/projects/{project}/task-definitions/{name}']['put']['requestBody']['content']['application/json']['schema'];
        $definition = $document['components']['schemas']['TaskDefinition'];
        $requiredByTool = [];

        foreach ($tools['tools'] as $tool) {
            $requiredByTool[$tool['name']] = $tool['input_schema']['required'] ?? [];
        }

        expect($create['required'])->toContain('parameters')
            ->and($update['required'])->toContain('parameters')
            ->and($definition['required'])->toContain('project_id', 'name', 'title', 'brief', 'parameters', 'status', 'schedule', 'phases', 'subtasks')
            ->and($definition['properties']['parameters']['items'])->toHaveKey('properties')
            ->and($definition['properties']['phases']['items'])->toHaveKey('properties')
            ->and($definition['properties']['subtasks']['items'])->toHaveKey('properties')
            ->and($definition['properties']['parameters']['items']['properties'])->toHaveKeys(['name', 'type', 'required'])
            ->and($definition['properties']['phases']['items']['properties'])->toHaveKeys(['key', 'title', 'brief', 'repeat'])
            ->and($definition['properties']['subtasks']['items']['properties'])->toHaveKeys(['key', 'title', 'kind'])
            ->and($requiredByTool['tasks-definition-create'])->toContain('parameters')
            ->and($requiredByTool['tasks-definition-update'])->toContain('parameters');
    });

    it('returns 422 validation.failed when parameters is omitted', function (string $method): void {
        task_definition_gateway();
        $project = task_definition_project();
        $payload = task_definition_payload();
        unset($payload['parameters']);

        if ($method === 'put') {
            test()->postJson("/api/v1/projects/{$project->id}/task-definitions", task_definition_payload())->assertCreated();
            $response = test()->putJson("/api/v1/projects/{$project->id}/task-definitions/build-feature", $payload);
        } else {
            $response = test()->postJson("/api/v1/projects/{$project->id}/task-definitions", $payload);
        }

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonPath('error.details.parameters.0', 'The parameters field must be present.');

        expect(TaskDefinition::query()->count())->toBe($method === 'put' ? 1 : 0)
            ->and(Task::query()->count())->toBe(0);
    })->with(['post', 'put']);

    it('returns 422 tasks.definition_invalid when a list exceeds its cap', function (string $field, int $count): void {
        task_definition_gateway();
        $project = task_definition_project();
        $items = match ($field) {
            'subtasks' => task_definition_subtasks($count),
            'parameters' => task_definition_parameters($count),
            'phases' => task_definition_phases($count),
        };

        reject_task_definition($project, task_definition_payload([$field => $items]), [
            ['rule' => 'bounds', 'subtask' => null],
        ]);
    })->with([
        'subtasks' => ['subtasks', 101],
        'parameters' => ['parameters', 51],
        'phases' => ['phases', 51],
    ]);

    it('returns 422 tasks.definition_invalid when a subtask has more than 50 arguments', function (): void {
        task_definition_gateway();
        $project = task_definition_project();

        reject_task_definition($project, task_definition_payload([
            'subtasks' => [[
                'key' => 'deploy',
                'title' => 'Deploy',
                'kind' => 'action',
                'operation' => 'instance:deploy',
                'arguments' => task_definition_arguments(51),
            ]],
        ]), [
            ['rule' => 'bounds', 'subtask' => 'deploy'],
        ]);
    });

    it('returns 422 tasks.definition_invalid when a schedule has more than 100 values', function (): void {
        task_definition_gateway();
        $project = task_definition_project();

        reject_task_definition($project, task_definition_payload([
            'schedule' => ['cron' => '0 3 * * 1', 'values' => task_definition_values(101)],
        ]), [
            ['rule' => 'schedule_names', 'subtask' => null],
            ['rule' => 'bounds', 'subtask' => null],
        ]);
    });

    it('returns 422 tasks.definition_invalid when a phase key is duplicated', function (): void {
        task_definition_gateway();
        $project = task_definition_project();

        reject_task_definition($project, task_definition_payload([
            'phases' => [
                ['key' => 'prepare', 'title' => 'Prepare', 'brief' => 'Prepare the work.', 'repeat' => false],
                ['key' => 'prepare', 'title' => 'Prepare again', 'brief' => 'Prepare it again.', 'repeat' => true],
            ],
        ]), [
            ['rule' => 'phase_keys', 'subtask' => null],
        ]);
    });

    it('stores a definition at each size cap', function (): void {
        task_definition_gateway();
        $project = task_definition_project();
        $subtasks = task_definition_subtasks(99);
        $subtasks[] = [
            'key' => 'deploy',
            'title' => 'Deploy',
            'kind' => 'action',
            'operation' => 'instance:deploy',
            'arguments' => task_definition_arguments(50),
        ];
        $values = [];

        foreach (task_definition_parameters(50) as $parameter) {
            $values[$parameter['name']] = 'set';
        }

        test()->postJson("/api/v1/projects/{$project->id}/task-definitions", task_definition_payload([
            'parameters' => task_definition_parameters(50),
            'phases' => task_definition_phases(50),
            'schedule' => ['cron' => '0 3 * * 1', 'values' => $values],
            'subtasks' => $subtasks,
        ]))
            ->assertCreated()
            ->assertJsonCount(50, 'data.parameters')
            ->assertJsonCount(50, 'data.phases')
            ->assertJsonCount(100, 'data.subtasks')
            ->assertJsonCount(50, 'data.schedule.values')
            ->assertJsonCount(50, 'data.subtasks.99.arguments');

        expect(Task::query()->count())->toBe(0);
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
