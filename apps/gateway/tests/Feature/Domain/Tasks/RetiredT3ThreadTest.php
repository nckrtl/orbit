<?php

declare(strict_types=1);

use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\PrunePendingTaskThreads;
use App\Infrastructure\Tasks\Pi\PiDriver;
use App\Models\AgentThread;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

describe('retired T3 task history', function (): void {
    it('registers only Pi and exposes no T3 metrics or archive commands', function (): void {
        $drivers = app(AgentDriverRegistry::class);
        expect($drivers->get('pi'))->toBeInstanceOf(PiDriver::class);
        expect(fn () => $drivers->get('t3'))->toThrow(AgentDriverException::class, 'The recorded agent driver is unavailable.');
        expect(Artisan::all())->not->toHaveKey('tasks:collect-t3-metrics')->not->toHaveKey('tasks:archive-threads');
    });

    it('lists stored metrics and refuses the transcript without contacting T3', function (): void {
        $node = Node::query()->create(['name' => 'history-gateway', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.80', 'wireguard_ip' => '10.44.0.80']);
        $this->markAsGateway($node);
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
        $this->postJson('/api/v1/extensions/tasks/enable')->assertOk();
        $project = Project::query()->create(['name' => 'history', 'slug' => 'history', 'repository_url' => 'git@example.test:history.git', 'default_branch' => 'main', 'apps' => fixture_apps(null)]);
        $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Finished T3 work', 'brief' => 'Keep the record.', 'status' => 'completed']);
        $thread = AgentThread::query()->create([
            'task_group_id' => $group->id, 'node_id' => $node->id, 'driver' => 't3', 'runtime_key' => 'node:'.$node->id,
            'external_id' => 'stored-t3-thread', 'role' => 'reviewer', 'model' => 'claude-opus', 'effort' => 'high', 'state' => 'done',
            'tokens' => 1500, 'input_tokens' => 1000, 'cached_input_tokens' => 200, 'output_tokens' => 300,
            'model_calls' => 2, 'peak_context_tokens' => 900, 'lines_added' => 10, 'lines_deleted' => 3,
            't3_event_sequence' => 42, 't3_input_tokens' => 1000, 't3_metrics_initialized' => true,
            't3_metrics_final_at' => now(),
        ]);
        $stored = $thread->refresh()->getAttributes();
        Http::fake();

        $this->getJson("/api/v1/task-groups/{$group->id}/agents")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.driver', 't3')->assertJsonPath('data.0.external_id', 'stored-t3-thread')
            ->assertJsonPath('data.0.tokens', 1500)->assertJsonPath('data.0.input_tokens', 1000)
            ->assertJsonPath('data.0.cached_input_tokens', 200)->assertJsonPath('data.0.output_tokens', 300)
            ->assertJsonPath('data.0.model_calls', 2)->assertJsonPath('data.0.peak_context_tokens', 900)
            ->assertJsonPath('data.0.lines_added', 10)->assertJsonPath('data.0.lines_deleted', 3);
        $this->getJson("/api/v1/task-groups/{$group->id}/agents/{$thread->id}/stream")
            ->assertStatus(409)->assertJsonPath('error.code', 'tasks.agent_transcript_unavailable');

        app(PrunePendingTaskThreads::class)->run();
        Http::assertNothingSent();
        expect($thread->fresh()->getAttributes())->toBe($stored);

        // History has the same explicit error even after its original Node is gone.
        $thread->update(['node_id' => null]);
        $this->getJson("/api/v1/task-groups/{$group->id}/agents/{$thread->id}/stream")
            ->assertStatus(409)->assertJsonPath('error.code', 'tasks.agent_transcript_unavailable');
        $other = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Other', 'brief' => 'Other']);
        $this->getJson("/api/v1/task-groups/{$other->id}/agents/{$thread->id}/stream")->assertNotFound();
        Http::assertNothingSent();
    });
});
