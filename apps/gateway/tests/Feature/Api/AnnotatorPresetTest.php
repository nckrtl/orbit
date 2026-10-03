<?php

declare(strict_types=1);

use App\Actions\Processes\RemoveProcessAction;
use App\Domain\AppDev\AgentationPortAllocator;
use App\Domain\AppDev\AgentationSiteProjection;
use App\Domain\Processes\ProcessEnvironmentProjection;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgentationSiteProjection;
use Tests\Support\FakeProcessEnvironmentProjection;
use Tests\Support\ProcessesApiFakeRuntimeManager;

beforeEach(function (): void {
    app()->instance(ProcessRuntimeManager::class, new ProcessesApiFakeRuntimeManager);
    $node = Node::query()->create(['name' => 'annotator-node', 'public_ssh_host' => '192.0.2.20', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3']);
    $node->roles()->create(['role' => 'app-dev', 'status' => LifecycleStatus::Active]);
    $this->node = $this->markAsGateway($node);
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $project = Project::query()->create(['name' => 'Annotations', 'slug' => 'annotations', 'repository_url' => 'git@example.test:annotations.git']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'main', 'environment' => 'development', 'checkout_path' => '/home/orbit/apps/annotations', 'source_is_laravel' => false, 'provisioning_step' => 'active', 'status' => 'active']);
    $this->payload = ['target_type' => 'instance', 'target_id' => $this->instance->id, 'name' => 'annotator', 'preset' => 'annotator', 'start' => true];
});

describe('annotator preset', function (): void {
    it('keeps the reservation across withdrawal failure or crash until removal retry confirms Caddy', function (bool $crash): void {
        $route = Route::query()->create(['project_id' => $this->instance->project_id, 'node_id' => $this->node->id, 'domain' => 'protected.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
        $route->update(['status' => 'active']);
        $id = $this->postJson('/api/v1/processes', $this->payload)->assertCreated()->json('data.id');
        $sites = app(AgentationSiteProjection::class);
        assert($sites instanceof FakeAgentationSiteProjection);
        $sites->onProject = function (Instance $instance) use ($crash): void {
            if ($instance->id !== $this->instance->id) {
                return;
            }
            expect($instance->refresh()->annotator_port)->toBe(4848);
            $configuration = new DevelopmentCaddyConfigRenderer()->render(new DevelopmentSiteRepository()->forNode($this->node));
            expect($configuration)->not->toContain('/__orbit/annotator');
            throw $crash ? new RuntimeException('Simulated crash after withdrawal before acknowledgement') : new ResourceOperationException('test.projection_failed', 'Caddy withdrawal failed.', 409);
        };
        expect(fn () => app(RemoveProcessAction::class)->execute(Process::query()->findOrFail($id)))
            ->toThrow($crash ? RuntimeException::class : ResourceOperationException::class);
        $pending = Process::query()->findOrFail($id);
        expect($pending->endpoint_withdrawal_started_at)->not->toBeNull()->and($pending->endpoint_withdrawn_at)->toBeNull()
            ->and($pending->desired_state->value)->toBe('stopped');
        $this->postJson('/api/v1/processes', $this->payload)->assertConflict()->assertJsonPath('error.code', 'process.removal_pending');
        $other = $this->instance->replicate();
        $other->fill(['name' => 'other', 'checkout_path' => '/home/orbit/apps/other', 'annotator_port' => null])->save();
        $this->postJson('/api/v1/processes', [...$this->payload, 'target_id' => $other->id])->assertCreated();
        expect($other->refresh()->annotator_port)->toBe(4849);
        $sites->onProject = null;
        $this->deleteJson('/api/v1/processes/'.$id)->assertOk();
        expect($this->instance->refresh()->annotator_port)->toBeNull()
            ->and(DB::table('annotation_port_assignments')->where('instance_id', $this->instance->id)->exists())->toBeFalse()
            ->and(app(AgentationPortAllocator::class)->nextAvailable($this->node->id, 0, 'annotator_port'))->toBe(4848);
    })->with([false, true]);

    it('projects siblings without converging or activating them when adding a stopped annotator and removing it', function (): void {
        $runtime = app(ProcessRuntimeManager::class);
        assert($runtime instanceof ProcessesApiFakeRuntimeManager);
        $this->postJson('/api/v1/processes', ['target_type' => 'instance', 'target_id' => $this->instance->id, 'name' => 'worker', 'runtime' => 'systemd', 'command' => ['/usr/bin/sleep', '60'], 'start' => true])->assertCreated();
        $runtime->convergedProcessIds = [];
        $id = $this->postJson('/api/v1/processes', [...$this->payload, 'start' => false])->assertCreated()->json('data.id');
        $this->deleteJson('/api/v1/processes/'.$id)->assertOk();
        expect($runtime->convergedProcessIds)->toBe([$id]);
        $projection = app(ProcessEnvironmentProjection::class);
        assert($projection instanceof FakeProcessEnvironmentProjection);
        expect($projection->projected)->toHaveCount(2);
    });

    it('creates idempotently, publishes the URL and releases the port and environment on removal', function (): void {
        $route = Route::query()->create(['project_id' => $this->instance->project_id, 'node_id' => $this->node->id, 'domain' => 'annotations.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
        $response = $this->postJson('/api/v1/processes', $this->payload)->assertCreated()
            ->assertJsonPath('data.runtime', 'systemd')->assertJsonPath('data.restart_policy', 'on-failure')
            ->assertJsonPath('data.keep_alive', false)->assertJsonPath('data.desired_state', 'running');
        $id = $response->json('data.id');
        expect($this->instance->refresh()->annotator_port)->toBe(4848)
            ->and($this->instance->environmentValues()->where('env_key', 'ANNOTATOR_URL')->sole()->env_value)->toBe('https://{{instance.domain}}/__orbit/annotator/annotations');
        $this->getJson('/api/v1/instances/'.$this->instance->id)->assertOk()
            ->assertJsonPath('data.annotator_port', 4848)->assertJsonPath('data.annotator_url', 'https://annotations.test/__orbit/annotator');
        Process::query()->whereKey($id)->update(['desired_state' => 'stopped']);
        $this->postJson('/api/v1/processes', $this->payload)->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.desired_state', 'stopped');
        $this->postJson('/api/v1/processes', [...$this->payload, 'name' => 'second'])->assertConflict()->assertJsonPath('error.code', 'process.preset_exists');
        $this->deleteJson('/api/v1/processes/'.$id)->assertOk();
        expect($this->instance->refresh()->annotator_port)->toBeNull()
            ->and($this->instance->environmentValues()->where('env_key', 'ANNOTATOR_URL')->exists())->toBeFalse();
        $this->getJson('/api/v1/instances/'.$this->instance->id)->assertOk()->assertJsonPath('data.annotator_url', null);
        $sites = app(AgentationSiteProjection::class);
        expect($sites)->toBeInstanceOf(FakeAgentationSiteProjection::class);
        assert($sites instanceof FakeAgentationSiteProjection);
        expect($sites->projected)->toContain($this->instance->id);
    });

    it('refuses caller-owned runtime configuration and keep-alive', function (string $field, mixed $value): void {
        $this->postJson('/api/v1/processes', [...$this->payload, $field => $value])->assertUnprocessable();
        expect(Process::query()->count())->toBe(0)->and($this->instance->refresh()->annotator_port)->toBeNull();
    })->with(['runtime' => ['runtime', 'docker'], 'command' => ['command', []], 'directory' => ['working_directory', '/tmp'], 'environment' => ['environment', []], 'keep alive' => ['keep_alive', true]]);

    it('refuses Node and production targets', function (): void {
        $this->postJson('/api/v1/processes', [...$this->payload, 'target_type' => 'node', 'target_id' => $this->node->id])->assertUnprocessable();
        $this->node->roles()->where('role', 'app-dev')->update(['role' => 'app-prod']);
        $this->instance->update(['checkout_path' => '/var/www/annotations/releases/initial', 'production_user' => 'orbit-annotations', 'production_home' => '/var/www/annotations']);
        $this->postJson('/api/v1/processes', $this->payload)->assertUnprocessable()->assertJsonPath('error.code', 'process.preset_target_invalid');
    });

    it('allocates per Node, retains an assignment, avoids Agentation and releases for reuse', function (): void {
        $allocator = app(AgentationPortAllocator::class);
        $this->instance->update(['agentation_port' => 4848]);
        expect($allocator->assign($this->instance, 'annotator_port'))->toBe(4849)
            ->and($allocator->assign($this->instance, 'annotator_port'))->toBe(4849);
        $other = $this->instance->replicate();
        $other->fill(['name' => 'other', 'checkout_path' => '/home/orbit/apps/other', 'annotator_port' => null, 'agentation_port' => null])->save();
        expect($allocator->assign($other, 'annotator_port'))->toBe(4850);
        $destination = Node::query()->create(['name' => 'destination', 'public_ssh_host' => '192.0.2.21', 'user' => 'orbit', 'platform' => 'linux']);
        expect($allocator->nextAvailable($destination->id, $this->instance->id, 'annotator_port'))->toBe(4848)
            ->and($allocator->nextAvailable($destination->id, $this->instance->id, 'annotator_port', [4848]))->toBe(4849);
        $allocator->release($this->instance, 'annotator_port');
        expect($allocator->nextAvailable($this->node->id, $other->id, 'annotator_port'))->toBe(4849);
    });
});
