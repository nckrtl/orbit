<?php

declare(strict_types=1);

use App\Actions\Tasks\RunTaskDeliverableProbeAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Testing\TestResponse;
use Orbit\Sdk\Requests\Tasks\ProbeTaskDeliverableRequest;
use Orbit\Sdk\Requests\Tasks\ShowTaskCheckRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\FakeTaskCheckRunner;

/** @return array{Task, Task} */
function deliverable_probe_api_fixture(): array
{
    $gateway = Node::query()->create([
        'name' => 'probe-api-gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.81', 'wireguard_ip' => '10.44.0.81',
    ]);
    test()->markAsGateway($gateway);
    test()->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create([
        'name' => 'probe-api', 'slug' => 'probe-api', 'repository_url' => 'git@example.test:probe-api.git',
        'default_branch' => 'main', 'task_check' => 'composer check',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $gateway->id, 'name' => 'probe-api',
        'checkout_path' => '/tmp/probe-api', 'status' => 'source_resolved',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id, 'title' => 'Probe API', 'brief' => 'Probe a declared test.',
        'status' => TaskGroupStatus::Running, 'execution_mode' => TaskExecutionMode::Managed,
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create([
        'parent_id' => $group->id, 'position' => 1, 'title' => 'Test probe', 'brief' => 'Run the declared test.',
        'status' => TaskStatus::Running, 'started_at' => now(), 'subtask_start_commit' => str_repeat('c', 40),
        'deliverables' => [
            ['id' => 'test', 'type' => 'command', 'description' => 'Focused test', 'command' => 'vendor/bin/pest --filter=ExactTest',
                'directory' => 'apps/gateway', 'fails_on_base' => true, 'paths' => ['apps/gateway/tests/Feature/ExactTest.php']],
            ['id' => 'file', 'type' => 'file', 'description' => 'A file', 'path' => 'file.php', 'change' => 'modified'],
            ['id' => 'review', 'type' => 'review', 'description' => 'Review it'],
        ],
    ]);

    return [$group->fresh(['taskable', 'project']), $task];
}

function deliverable_probe_api_mcp_document(TestResponse $response): stdClass
{
    if ($response->baseResponse instanceof StreamedResponse) {
        preg_match_all('/^data: (.+)$/m', $response->streamedContent(), $matches);
        $content = end($matches[1]);
    } else {
        $content = $response->getContent();
    }

    return json_decode($content, flags: JSON_THROW_ON_ERROR);
}

describe('DeliverableProbeApi', function (): void {
    it('keeps a lost-start reservation readable and returns its check id and request id', function (): void {
        $this->travelTo(new DateTimeImmutable('2026-10-06T12:00:00+00:00'));
        [$group, $task] = deliverable_probe_api_fixture();
        $this->withHeader('X-Orbit-Request-Id', fixture_request_id());
        $runner = new FakeTaskCheckRunner;
        $runner->afterStart = fn () => throw new TaskCheckException('Lost accepted start reply.');
        app()->instance(TaskCheckRunner::class, $runner);
        $response = $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/deliverables/test/probe", [])
            ->assertStatus(502)->assertJsonPath('error.code', 'tasks.probe_start_pending')
            ->assertHeader('X-Orbit-Request-Id', fixture_request_id());
        $check = TaskCheck::query()->sole();
        $response->assertJsonPath('error.details.check_id', $check->id);
        record_fixture($response, 'tasks/tasks-deliverable-probe/start-pending', ProbeTaskDeliverableRequest::class,
            'POST /api/v1/task-groups/{group}/tasks/{task}/deliverables/{deliverable}/probe', recordRequestIdHeader: true);
        $this->getJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/checks/{$check->id}")
            ->assertOk()->assertJsonPath('data.id', $check->id)->assertJsonPath('data.status', 'running');
        $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/deliverables/test/probe", [])
            ->assertConflict()->assertJsonPath('error.code', 'tasks.probe_check_running');
        expect(TaskCheck::query()->count())->toBe(1)->and($runner->starts)->toBe(1);
        $runner->afterStart = null;
        app(RunTaskDeliverableProbeAction::class)->reconcile($group);
        expect(TaskCheck::query()->sole()->status->value)->toBe('passed')->and($runner->starts)->toBe(1);
    });

    it('records pending and finished check fixtures for human and SDK contracts', function (string $status, int $exitCode): void {
        $this->travelTo(new DateTimeImmutable('2026-10-06T12:00:00+00:00'));
        [$group, $task] = deliverable_probe_api_fixture();
        $this->withHeader('X-Orbit-Request-Id', fixture_request_id());
        $reading = TaskCheckReading::finished($exitCode, str_repeat('a', 40), str_repeat('b', 40), [],
            $exitCode === 0 ? "checks passed\n" : "Declared test failed. Inspect the assertion before retrying.\n",
            deliverables: ['commands' => ['test' => ['exit_code' => $exitCode, 'base_exit_code' => 1]]],
            execution: ['managed_user' => 'orbit-worker', 'uid' => 1001, 'tmpdir' => '/tmp/orbit-check-1001-deliverable-probe']);
        app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner([$reading]));
        $started = $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/deliverables/test/probe", ['base' => true])->assertCreated();
        if ($status === 'running') {
            record_fixture($started, 'tasks/tasks-deliverable-probe/created', ProbeTaskDeliverableRequest::class,
                'POST /api/v1/task-groups/{group}/tasks/{task}/deliverables/{deliverable}/probe');
        } else {
            app(RunTaskDeliverableProbeAction::class)->reconcile($group);
        }
        $id = $started->json('data.id');
        $shown = $this->getJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/checks/{$id}")
            ->assertOk()->assertJsonPath('data.status', $status);
        record_fixture($shown, 'tasks/tasks-check-show/'.$status, ShowTaskCheckRequest::class,
            'GET /api/v1/task-groups/{group}/tasks/{task}/checks/{check}');
    })->with([['running', 0], ['passed', 0], ['failed', 1]]);

    it('returns quota refusal without creating or reusing a fourth reservation', function (): void {
        [$group, $task] = deliverable_probe_api_fixture();
        for ($i = 0; $i < 3; $i++) {
            $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/deliverables/test/probe", [])->assertCreated();
            app(RunTaskDeliverableProbeAction::class)->reconcile($group);
        }
        $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/deliverables/test/probe", [])
            ->assertStatus(429)->assertJsonPath('error.code', 'tasks.probe_limit');
        expect(TaskCheck::query()->count())->toBe(3);
    });

    it('publishes only creation success with quota and retained-reservation recovery schemas', function (): void {
        $catalogue = json_decode((string) file_get_contents(base_path('../../docs/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $operation = $catalogue['paths']['/api/v1/task-groups/{group}/tasks/{task}/deliverables/{deliverable}/probe']['post'];
        expect($operation['responses'])->toHaveKeys(['201', '429', '502'])->not->toHaveKey('200')
            ->and($operation['responses']['429']['description'])->toContain('tasks.probe_limit')
            ->and($operation['responses']['502']['description'])->toContain('tasks.probe_start_pending', 'check_id')
            ->and($operation['description'])->toContain('exact retry does not reuse a check');
        $details = $operation['responses']['502']['content']['application/json']['schema']['allOf'][1]['properties']['error']['properties']['details'];
        expect($details['required'])->toBe(['check_id'])->and($details['properties']['check_id'])->toBe(['type' => 'integer', 'minimum' => 1]);
    });
    it('runs tasks-deliverable-probe and tasks-check-show through MCP with numeric check ids', function (): void {
        [$group, $task] = deliverable_probe_api_fixture();
        $call = function (string $name, array $arguments): stdClass {
            return deliverable_probe_api_mcp_document($this->postJson('/mcp', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ])->assertOk());
        };
        $started = $call('tasks-deliverable-probe', ['group' => $group->id, 'task' => $task->id, 'deliverable' => 'test']);
        expect($started->result->isError)->toBeFalse();
        $data = json_decode($started->result->content[0]->text, true, flags: JSON_THROW_ON_ERROR)['data'];
        expect($data['id'])->toBeInt()->and($data['kind'])->toBe('probe');
        app(RunTaskDeliverableProbeAction::class)->reconcile($group);
        $shown = $call('tasks-check-show', ['group' => $group->id, 'task' => $task->id, 'check' => $data['id']]);
        expect($shown->result->isError)->toBeFalse();
        $result = json_decode($shown->result->content[0]->text, true, flags: JSON_THROW_ON_ERROR)['data'];
        expect($result['id'])->toBe($data['id'])->and($result['receipt']['check_id'])->toBe($data['id'])
            ->and($result['output_tail'])->toBe("checks passed\n");
    });
    it('creates a probe and reads its id, evidence, output tail and receipt via tasks:check:show', function (bool $base): void {
        [$group, $task] = deliverable_probe_api_fixture();
        $evidence = ['commands' => ['test' => ['passed' => true, 'exit_code' => 0, 'base_exit_code' => 1]]];
        $reading = TaskCheckReading::finished(0, str_repeat('a', 40), str_repeat('b', 40), [], "checks passed\n",
            deliverables: $evidence, execution: ['managed_user' => 'orbit-worker', 'uid' => 1001, 'tmpdir' => '/tmp/orbit-check-1001-probe']);
        $runner = new FakeTaskCheckRunner([$reading]);
        app()->instance(TaskCheckRunner::class, $runner);

        $response = $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/deliverables/test/probe", $base ? ['base' => true] : [])
            ->assertCreated()->assertJsonPath('data.kind', 'probe')->assertJsonPath('data.status', 'running');
        $id = $response->json('data.id');
        expect($id)->toBeInt()->and(TaskCheck::query()->findOrFail($id)->kind)->toBe(TaskCheckKind::Probe)
            ->and($runner->commands)->toBe([''])->and($runner->setups)->toBe([[]]);
        $command = ['id' => 'test', 'command' => 'vendor/bin/pest --filter=ExactTest', 'directory' => 'apps/gateway'];
        if ($base) {
            $command += ['fails_on_base' => true, 'paths' => ['apps/gateway/tests/Feature/ExactTest.php']];
        }
        expect($runner->deliverables)->toBe([['start' => str_repeat('c', 40), 'commands' => [$command]]]);
        app(RunTaskDeliverableProbeAction::class)->reconcile($group);

        $this->getJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/checks/{$id}")
            ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.kind', 'probe')
            ->assertJsonPath('data.status', 'passed')->assertJsonPath('data.exit_code', 0)
            ->assertJsonPath('data.deliverable_evidence', $evidence)->assertJsonPath('data.output_tail', "checks passed\n")
            ->assertJsonPath('data.receipt.check_id', $id)->assertJsonPath('data.receipt.deliverable', 'test')
            ->assertJsonPath('data.receipt.command', $command['command'])->assertJsonPath('data.receipt.directory', 'apps/gateway')
            ->assertJsonPath('data.receipt.base_exit_code', 1)->assertJsonPath('data.receipt.output_tail', "checks passed\n")
            ->assertJsonPath('data.receipt.managed_user', 'orbit-worker')->assertJsonPath('data.receipt.uid', 1001)
            ->assertJsonPath('data.receipt.tmpdir', '/tmp/orbit-check-1001-probe')->assertJsonPath('data.receipt.head', str_repeat('a', 40))
            ->assertJsonPath('data.receipt.tree', str_repeat('b', 40))->assertJsonPath('data.receipt.exit_code', 0)
            ->assertJsonPath('data.receipt.started_at', fn (mixed $value): bool => is_string($value))
            ->assertJsonPath('data.receipt.finished_at', fn (mixed $value): bool => is_string($value));
    })->with([false, true]);

    it('refuses non-command and unknown deliverables through HTTP', function (string $deliverable, int $status): void {
        [$group, $task] = deliverable_probe_api_fixture();
        $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/deliverables/{$deliverable}/probe", [])
            ->assertStatus($status);
        expect(TaskCheck::query()->count())->toBe(0);
    })->with([['file', 422], ['review', 422], ['unknown', 404]]);

    it('refuses free-form filters, extra command args and invalid base input', function (array $body): void {
        [$group, $task] = deliverable_probe_api_fixture();
        $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/deliverables/test/probe", $body)
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        expect(TaskCheck::query()->count())->toBe(0);
    })->with([
        [['filter' => 'OtherTest']], [['args' => ['--filter=OtherTest']]], [['command' => 'false']],
        [['base' => 'true']], [['base' => null]],
    ]);

    it('scopes probe and check-show to the named group and subtask', function (): void {
        [$group, $task] = deliverable_probe_api_fixture();
        $other = Task::query()->create([
            'parent_id' => $group->id, 'position' => 2, 'title' => 'Other', 'brief' => 'Other task.', 'status' => TaskStatus::Running,
        ]);
        $check = app(RunTaskDeliverableProbeAction::class)->execute($group, $task, 'test');
        $this->getJson("/api/v1/task-groups/{$group->id}/tasks/{$other->id}/checks/{$check->id}")->assertNotFound();
        $this->getJson("/api/v1/task-groups/{$task->id}/tasks/{$task->id}/checks/{$check->id}")->assertNotFound();
        $this->postJson("/api/v1/task-groups/{$other->id}/tasks/{$task->id}/deliverables/test/probe", [])->assertNotFound();
        expect(TaskCheck::query()->count())->toBe(1);
    });

    it('requires an active WireGuard peer for both operations', function (): void {
        [$group, $task] = deliverable_probe_api_fixture();
        $check = app(RunTaskDeliverableProbeAction::class)->execute($group, $task, 'test');
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.240']);
        $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/deliverables/test/probe", [])
            ->assertForbidden()->assertJsonPath('error.code', 'peer.identity_unknown');
        $this->getJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/checks/{$check->id}")
            ->assertForbidden()->assertJsonPath('error.code', 'peer.identity_unknown');
        expect(TaskCheck::query()->count())->toBe(1);
    });

    it('reads baseline and handoff checks by id without a probe receipt', function (string $kind): void {
        [$group, $task] = deliverable_probe_api_fixture();
        $check = TaskCheck::query()->create([
            'task_id' => $task->id, 'kind' => $kind, 'status' => 'passed', 'exit_code' => 0,
            'pid' => 123, 'process_started' => 'started', 'head_before' => '', 'tree_before' => '', 'started_at' => now(),
            'output' => 'check output',
        ]);
        $this->getJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/checks/{$check->id}")
            ->assertOk()->assertJsonPath('data.id', $check->id)->assertJsonPath('data.kind', $kind)
            ->assertJsonPath('data.exit_code', 0)->assertJsonPath('data.output_tail', 'check output')->assertJsonPath('data.receipt', null);
    })->with(['baseline', 'handoff']);

    it('publishes tasks-deliverable-probe and tasks-check-show in the MCP schema and CLI catalogue', function (): void {
        deliverable_probe_api_fixture();
        $manifest = json_decode((string) file_get_contents(resource_path('mcp/tools.json')), true, flags: JSON_THROW_ON_ERROR);
        $tools = collect($manifest['tools'])->keyBy('name');
        foreach (['tasks-deliverable-probe' => ['group', 'task', 'deliverable', 'base'], 'tasks-check-show' => ['group', 'task', 'check']] as $name => $inputs) {
            $tool = $tools->get($name);
            expect($tool)->toBeArray()->and(array_keys($tool['input_schema']['properties']))->toBe($inputs)
                ->and($tool['input_schema']['additionalProperties'])->toBeFalse();
            $response = $this->postJson('/mcp/search', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => 'search_tools', 'arguments' => ['query' => $name, 'limit' => 1]],
            ])->assertOk();
            $document = deliverable_probe_api_mcp_document($response);
            expect($document->result->isError)->toBeFalse();
            $found = json_decode($document->result->content[0]->text, true, flags: JSON_THROW_ON_ERROR);
            expect($found['tools'][0]['name'])->toBe($name)
                ->and($found['tools'][0]['inputSchema'])->toBe($tool['input_schema']);
        }
        expect($tools['tasks-deliverable-probe']['input_schema']['properties']['base']['type'])->toBe('boolean');
    });
});
