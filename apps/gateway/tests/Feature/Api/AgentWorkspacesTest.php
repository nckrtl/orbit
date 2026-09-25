<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\TaskGroup;

function agent_workspace_node(string $name, string $address): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => $address,
        'wireguard_ip' => $address,
        'ssh_host_fingerprint' => 'SHA256:test',
    ]);
}

function agent_workspace_group(Node $node, string $name, TaskGroupStatus $status, ?string $start = null): AppInstance
{
    $app = OrbitApp::query()->firstOrCreate(['slug' => 'agent-workspaces'], [
        'name' => 'Agent workspaces',
        'repository_url' => 'git@example.test:agent-workspaces.git',
        'default_branch' => 'main',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => "/home/orbit/apps/agent-workspaces/{$name}",
        'status' => 'source_resolved',
        'starting_commit' => $start,
    ]);
    $group = TaskGroup::query()->create(['app_id' => $app->id, 'title' => $name, 'brief' => 'Brief', 'status' => $status]);
    $group->taskable()->associate($instance);
    $group->save();

    return $instance;
}

describe('agent workspaces endpoint', function (): void {
    beforeEach(function (): void {
        $this->node = agent_workspace_node('workspace-node', '10.44.0.41');
        $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
        app(TaskExtensionState::class)->enable();
    });

    it('lists the checkouts of unfinished task groups on the calling Node', function (): void {
        $start = str_repeat('a1', 20);
        $running = agent_workspace_group($this->node, 'task-1', TaskGroupStatus::Running, $start);
        $planning = agent_workspace_group($this->node, 'task-2', TaskGroupStatus::Backlog, 'not-a-commit');
        agent_workspace_group($this->node, 'task-3', TaskGroupStatus::Completed);
        agent_workspace_group(agent_workspace_node('other-node', '10.44.0.42'), 'task-4', TaskGroupStatus::Running);

        $this->getJson('/api/v1/agent/workspaces')->assertOk()->assertExactJsonStructure(['data', 'meta' => ['request_id']])->assertJsonPath('data', [
            ['instance_id' => $running->id, 'path' => '/home/orbit/apps/agent-workspaces/task-1', 'base' => 'main', 'start' => $start],
            ['instance_id' => $planning->id, 'path' => '/home/orbit/apps/agent-workspaces/task-2', 'base' => 'main', 'start' => null],
        ]);
    });

    it('lists nothing while the tasks extension is disabled', function (): void {
        agent_workspace_group($this->node, 'task-5', TaskGroupStatus::Running);
        app(TaskExtensionState::class)->disable();

        $this->getJson('/api/v1/agent/workspaces')->assertOk()->assertJsonPath('data', []);
    });

    it('lists at most 64 checkouts', function (): void {
        foreach (range(1, 66) as $index) {
            agent_workspace_group($this->node, "task-many-{$index}", TaskGroupStatus::Running);
        }

        expect($this->getJson('/api/v1/agent/workspaces')->assertOk()->json('data'))->toHaveCount(64);
    });

    it('refuses an unmanaged Node and an unknown peer', function (): void {
        $this->node->update(['platform' => 'windows']);
        $this->getJson('/api/v1/agent/workspaces')->assertForbidden()->assertJsonPath('error.code', 'agent.node_ineligible');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.91']);
        $this->getJson('/api/v1/agent/workspaces')->assertForbidden()->assertJsonPath('error.code', 'peer.identity_unknown');
    });
});
