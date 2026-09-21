<?php

declare(strict_types=1);

use App\Infrastructure\Tasks\T3\T3Stream as TaskAgentStream;
use App\Models\AgentThread;
use App\Models\App as OrbitApp;
use App\Models\Node;
use App\Models\TaskGroup;

/** @return array{TaskGroup, AgentThread} */
function agent_viewer_fixture(): array
{
    $node = Node::query()->create(['name' => 'agent-gateway', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.80', 'wireguard_ip' => '10.44.0.80']);
    test()->markAsGateway($node);
    test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    test()->postJson('/api/v1/tasks/enable')->assertOk();
    $app = OrbitApp::query()->create(['name' => 'viewer', 'slug' => 'viewer', 'repository_url' => 'git@example.test:viewer.git', 'default_branch' => 'main']);
    $group = TaskGroup::query()->create(['app_id' => $app->id, 'title' => 'Viewer', 'brief' => 'Read sessions']);
    $session = AgentThread::query()->create(['task_group_id' => $group->id, 'node_id' => $node->id, 'role' => 'reviewer', 'model' => 'claude-opus', 'effort' => 'high', 'external_id' => 'thread-one', 'driver' => 't3', 'runtime_key' => 'node:'.$node->id]);

    return [$group, $session];
}

describe('task agent viewer', function (): void {
    it('lists persisted links after the workspace is removed', function (): void {
        [$group, $session] = agent_viewer_fixture();
        $this->getJson("/api/v1/task-groups/{$group->id}/agents")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.external_id', 'thread-one')
            ->assertJsonPath('data.0.task_group_id', $group->id)->assertJsonPath('data.0.node_id', $session->node_id)
            ->assertJsonPath('data.0.model', 'claude-opus')->assertJsonPath('data.0.effort', 'high');
        $this->postJson('/api/v1/tasks/disable')->assertOk();
        $this->getJson("/api/v1/task-groups/{$group->id}/agents")->assertStatus(409)->assertJsonPath('error.code', 'tasks.disabled');
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
        $other = TaskGroup::query()->create(['app_id' => $group->app_id, 'title' => 'Other', 'brief' => 'Other']);
        $this->getJson("/api/v1/task-groups/{$other->id}/agents/{$session->id}/stream")->assertNotFound();
        $this->withHeader('Last-Event-ID', 'bad cursor')->getJson("/api/v1/task-groups/{$group->id}/agents/{$session->id}/stream")->assertUnprocessable();
        $this->flushHeaders();
        $session->update(['node_id' => null]);
        $this->getJson("/api/v1/task-groups/{$group->id}/agents/{$session->id}/stream")->assertStatus(409)->assertJsonPath('error.code', 'tasks.agent_unavailable');
    });

    it('relays scoped snapshots and events with resume IDs and redacts secrets', function (): void {
        [$group, $session] = agent_viewer_fixture();
        $session->update(['state' => 'done', 'tokens' => 500, 'observation_version' => 7]);
        config()->set('orbit.t3.token', 'private-t3-bearer');
        $fake = new class implements TaskAgentStream
        {
            public ?int $cursor = null;

            public function events(Node $node, string $threadId, ?int $afterSequence): iterable
            {
                $this->cursor = $afterSequence;
                yield ['kind' => 'snapshot', 'snapshot' => ['snapshotSequence' => 8, 'thread' => ['id' => $threadId, 'session' => ['status' => 'failed', 'lastError' => 'Turn failed.'], 'messages' => [['text' => 'TOKEN=hidden private-t3-bearer']]]]];
                yield ['kind' => 'event', 'event' => ['sequence' => 9, 'aggregateId' => 'unrelated', 'payload' => ['text' => 'must-not-leak']]];
                yield ['kind' => 'event', 'event' => ['sequence' => 10, 'aggregateId' => $threadId, 'type' => 'thread.message-sent', 'payload' => ['id' => 'm2', 'role' => 'assistant', 'text' => 'Visible progress']]];
                yield ['kind' => 'event', 'event' => ['sequence' => 11, 'aggregateId' => $threadId, 'type' => 'thread.message-sent', 'payload' => ['messageId' => 'm2', 'role' => 'assistant', 'text' => ' appended', 'streaming' => true]]];
                yield ['kind' => 'event', 'event' => ['sequence' => 12, 'aggregateId' => $threadId, 'type' => 'thread.turn-start-requested', 'payload' => []]];
                yield ['kind' => 'event', 'event' => ['sequence' => 13, 'aggregateId' => $threadId, 'type' => 'thread.activity-appended', 'payload' => ['activity' => ['id' => 'a1', 'kind' => 'approval', 'payload' => ['requestId' => 'request-1']]]]];
                yield ['kind' => 'event', 'event' => ['sequence' => 14, 'aggregateId' => $threadId, 'type' => 'thread.activity-appended', 'payload' => ['activity' => ['id' => 'a2', 'kind' => 'approval.resolved', 'payload' => ['requestId' => 'request-1']]]]];
                yield ['kind' => 'event', 'event' => ['sequence' => 15, 'aggregateId' => $threadId, 'type' => 'thread.session-set', 'payload' => ['session' => ['status' => 'ready']]]];
                throw new RuntimeException('private-t3-bearer secret error');
            }
        };
        app()->instance(TaskAgentStream::class, $fake);
        $response = $this->withHeader('Last-Event-ID', '7')->get("/api/v1/task-groups/{$group->id}/agents/{$session->id}/stream");
        $response->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        $output = $response->streamedContent();
        expect($output)->toContain('id: 8', 'id: 10', 'Visible progress', '[REDACTED]', 'event: unavailable')
            ->not->toContain('hidden', 'private-t3-bearer', 'must-not-leak', 'secret error');
        expect($fake->cursor)->toBeNull();
        $events = [];
        foreach (explode("\n", $output) as $line) {
            if (str_starts_with($line, 'data: ')) {
                $events[] = json_decode(substr($line, 6), true, 512, JSON_THROW_ON_ERROR);
            }
        }
        expect(array_column($events, 'kind'))->toBe(['snapshot', 'entry', 'entry', 'state', 'entry', 'entry', 'state'])
            ->and($events[2]['entry']['text'])->toBe('Visible progress appended')
            ->and($events[1])->not->toHaveKey('entries')
            ->and($events[3]['state'])->toBe('working')
            ->and($events[4]['state'])->toBe('working')
            ->and($events[4]['input_requests'])->toBe([])
            ->and($events[5]['state'])->toBe('working')
            ->and($events[5]['input_requests'])->toBe([])
            ->and($events[6]['state'])->toBe('done');
        expect($session->fresh()->state->value)->toBe('done')
            ->and($session->fresh()->tokens)->toBe(500)
            ->and($session->fresh()->observation_version)->toBe(7)
            ->and($session->fresh()->error)->toBeNull()
            ->and($session->fresh()->observation_error)->toBeNull();
    });
});
