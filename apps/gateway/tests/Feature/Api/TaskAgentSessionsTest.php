<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskAgentStream;
use App\Models\App as OrbitApp;
use App\Models\Node;
use App\Models\TaskAgentSession;
use App\Models\TaskGroup;

/** @return array{TaskGroup, TaskAgentSession} */
function agent_viewer_fixture(): array
{
    $node = Node::query()->create(['name' => 'agent-gateway', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.80', 'wireguard_ip' => '10.44.0.80']);
    test()->markAsGateway($node);
    test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    test()->postJson('/api/v1/tasks/enable')->assertOk();
    $app = OrbitApp::query()->create(['name' => 'viewer', 'slug' => 'viewer', 'repository_url' => 'git@example.test:viewer.git', 'default_branch' => 'main']);
    $group = TaskGroup::query()->create(['app_id' => $app->id, 'title' => 'Viewer', 'brief' => 'Read sessions']);
    $session = TaskAgentSession::query()->create(['task_group_id' => $group->id, 'node_id' => $node->id, 'role' => 'reviewer', 'thread_id' => 'thread-one']);

    return [$group, $session];
}

describe('task agent viewer', function (): void {
    it('lists persisted links after the workspace is removed', function (): void {
        [$group, $session] = agent_viewer_fixture();
        $this->getJson("/api/v1/task-groups/{$group->id}/agents")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.thread_id', 'thread-one')
            ->assertJsonPath('data.0.task_group_id', $group->id)->assertJsonPath('data.0.node_id', $session->node_id);
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
        $this->withHeader('Last-Event-ID', '-1')->getJson("/api/v1/task-groups/{$group->id}/agents/{$session->id}/stream")->assertUnprocessable();
        $this->flushHeaders();
        $session->update(['node_id' => null]);
        $this->getJson("/api/v1/task-groups/{$group->id}/agents/{$session->id}/stream")->assertStatus(409)->assertJsonPath('error.code', 'tasks.agent_unavailable');
    });

    it('relays scoped snapshots and events with resume IDs and redacts secrets', function (): void {
        [$group, $session] = agent_viewer_fixture();
        config()->set('orbit.t3.token', 'private-t3-bearer');
        $fake = new class implements TaskAgentStream
        {
            public ?int $cursor = null;

            public function events(Node $node, string $threadId, ?int $afterSequence): iterable
            {
                $this->cursor = $afterSequence;
                yield ['kind' => 'snapshot', 'snapshot' => ['snapshotSequence' => 8, 'thread' => ['id' => $threadId, 'messages' => [['text' => 'TOKEN=hidden private-t3-bearer']]]]];
                yield ['kind' => 'event', 'event' => ['sequence' => 9, 'aggregateId' => 'unrelated', 'payload' => ['text' => 'must-not-leak']]];
                yield ['kind' => 'event', 'event' => ['sequence' => 10, 'aggregateId' => $threadId, 'type' => 'thread.message-sent', 'payload' => ['text' => 'Visible progress']]];
                throw new RuntimeException('private-t3-bearer secret error');
            }
        };
        app()->instance(TaskAgentStream::class, $fake);
        $response = $this->withHeader('Last-Event-ID', '7')->get("/api/v1/task-groups/{$group->id}/agents/{$session->id}/stream");
        $response->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        $output = $response->streamedContent();
        expect($output)->toContain('id: 8', 'id: 10', 'Visible progress', '[REDACTED]', 'event: unavailable')
            ->not->toContain('hidden', 'private-t3-bearer', 'must-not-leak', 'secret error');
        expect($fake->cursor)->toBe(7);
    });
});
