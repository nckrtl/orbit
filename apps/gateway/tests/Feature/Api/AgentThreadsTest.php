<?php

declare(strict_types=1);

use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentThreadEvent;
use App\Domain\Tasks\TaskAgentSpawner;
use App\Models\AgentThread;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Tests\Support\FakeAgentDriver;

/** @return array{Task, AgentThread} */
function agent_viewer_fixture(): array
{
    $node = Node::query()->create(['name' => 'agent-gateway', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.80', 'wireguard_ip' => '10.44.0.80']);
    test()->markAsGateway($node);
    test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    test()->postJson('/api/v1/extensions/tasks/enable')->assertOk();
    $project = Project::query()->create(['name' => 'viewer', 'slug' => 'viewer', 'repository_url' => 'git@example.test:viewer.git', 'default_branch' => 'main', 'apps' => fixture_apps(null)]);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Viewer', 'brief' => 'Read sessions']);
    $session = AgentThread::query()->create(['task_group_id' => $group->id, 'node_id' => $node->id, 'role' => 'reviewer', 'model' => 'gpt-5.6-luna', 'effort' => 'high', 'external_id' => 'thread-one', 'driver' => 'pi', 'runtime_key' => 'node:'.$node->id]);

    return [$group, $session];
}

describe('task agent viewer', function (): void {
    it('lists persisted links after the workspace is removed', function (): void {
        [$group, $session] = agent_viewer_fixture();
        AgentThread::query()->create([
            'task_group_id' => $group->id, 'node_id' => $session->node_id, 'role' => 'reviewer',
            'model' => 'gpt-5.6-luna', 'effort' => 'high', 'external_id' => TaskAgentSpawner::PendingPrefix.'not-started',
            'driver' => 'pi', 'runtime_key' => 'node:'.$session->node_id,
        ]);

        $this->getJson("/api/v1/task-groups/{$group->id}/agents")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.external_id', 'thread-one')
            ->assertJsonPath('data.0.task_group_id', $group->id)->assertJsonPath('data.0.node_id', $session->node_id)
            ->assertJsonPath('data.0.model', 'gpt-5.6-luna')->assertJsonPath('data.0.effort', 'high');
        $this->postJson('/api/v1/extensions/tasks/disable')->assertOk()->assertJsonPath('data.enabled', false);
        $this->getJson("/api/v1/task-groups/{$group->id}/agents")->assertStatus(409)->assertJsonPath('error.code', 'extension.disabled');
    });

    it('denies a peer without Gateway access and an unknown peer', function (): void {
        [$group, $session] = agent_viewer_fixture();
        $peer = Node::query()->create(['name' => 'other-peer', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.81', 'wireguard_ip' => '10.44.0.81']);
        $this->withServerVariables(['REMOTE_ADDR' => $peer->wireguard_ip])->getJson("/api/v1/task-groups/{$group->id}/agents")->assertForbidden();
        $this->getJson("/api/v1/task-groups/{$group->id}/agents/{$session->id}/stream")->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.99'])->getJson("/api/v1/task-groups/{$group->id}/agents")->assertForbidden();
    });

    it('refuses a session from another group, invalid cursors, and missing Nodes', function (): void {
        [$group, $session] = agent_viewer_fixture();
        $other = Task::topLevel()->create(['project_id' => $group->project_id, 'title' => 'Other', 'brief' => 'Other']);
        $this->getJson("/api/v1/task-groups/{$other->id}/agents/{$session->id}/stream")->assertNotFound();
        $this->withHeader('Last-Event-ID', 'bad cursor')->getJson("/api/v1/task-groups/{$group->id}/agents/{$session->id}/stream")->assertUnprocessable();
        $this->flushHeaders();
        $session->update(['node_id' => null]);
        $this->getJson("/api/v1/task-groups/{$group->id}/agents/{$session->id}/stream")->assertStatus(409)->assertJsonPath('error.code', 'tasks.agent_unavailable');
    });

    it('relays driver events, resumes the cursor, filters other threads, and hides errors', function (): void {
        [$group, $session] = agent_viewer_fixture();
        $session->update(['state' => 'done', 'tokens' => 500, 'observation_version' => 7]);
        $stored = $session->refresh()->getAttributes();
        $driver = new class extends FakeAgentDriver
        {
            public ?string $cursor = null;

            public function events(AgentThread $thread, ?string $cursor, ?float $timeoutSeconds = null): iterable
            {
                $this->cursor = $cursor;
                yield new AgentThreadEvent($thread->id, 'snapshot', ['state' => 'working', 'entries' => []], 'run.8');
                yield new AgentThreadEvent($thread->id + 1, 'entry', ['entry' => ['text' => 'must-not-leak']], 'run.9');
                yield new AgentThreadEvent($thread->id, 'entry', ['entry' => ['text' => 'Visible progress TOKEN=hidden']], 'run.10');
                yield new AgentThreadEvent($thread->id, 'heartbeat');
                yield new AgentThreadEvent($thread->id, 'state', ['state' => 'done'], 'run.11');
                throw new RuntimeException('secret error');
            }
        };
        app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
        $session->update(['driver' => 'example']);
        $stored = $session->refresh()->getAttributes();

        $response = $this->withHeader('Last-Event-ID', 'run.7')->get("/api/v1/task-groups/{$group->id}/agents/{$session->id}/stream");
        $response->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        $output = $response->streamedContent();
        expect($output)->toContain('id: run.8', 'id: run.10', 'id: run.11', 'Visible progress', '[REDACTED]', ': heartbeat', 'event: unavailable')
            ->not->toContain('hidden', 'must-not-leak', 'secret error')
            ->and($driver->cursor)->toBe('run.7')
            ->and($session->fresh()->getAttributes())->toBe($stored);
    });

    it('shows per-thread token metrics', function (): void {
        [$group, $session] = agent_viewer_fixture();
        $session->update([
            'tokens' => 130, 'input_tokens' => 40, 'cached_input_tokens' => 70, 'output_tokens' => 20,
            'model_calls' => 2, 'peak_context_tokens' => 80,
        ]);
        AgentThread::query()->create([
            'task_group_id' => $group->id, 'node_id' => $session->node_id, 'role' => 'implementer',
            'model' => 'gpt-5.6-luna', 'effort' => 'low', 'external_id' => 'thread-two', 'driver' => 'pi',
            'runtime_key' => 'node:'.$session->node_id,
        ]);

        $response = $this->getJson("/api/v1/task-groups/{$group->id}/agents")->assertOk();
        $reported = $response->json('data.0');
        $unknown = $response->json('data.1');

        expect($reported)->toMatchArray([
            'tokens' => 130, 'input_tokens' => 40, 'cached_input_tokens' => 70, 'output_tokens' => 20, 'model_calls' => 2, 'peak_context_tokens' => 80,
        ])->and($unknown)->toHaveKeys(['input_tokens', 'cached_input_tokens', 'output_tokens', 'model_calls', 'peak_context_tokens'])
            ->and($unknown['tokens'])->toBeNull()->and($unknown['input_tokens'])->toBeNull()
            ->and($unknown['cached_input_tokens'])->toBeNull()->and($unknown['output_tokens'])->toBeNull()
            ->and($unknown['model_calls'])->toBeNull()->and($unknown['peak_context_tokens'])->toBeNull();
    });
});
