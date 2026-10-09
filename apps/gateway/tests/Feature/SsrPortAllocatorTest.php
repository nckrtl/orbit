<?php

declare(strict_types=1);

use App\Data\Instances\InstanceData;
use App\Domain\AppDev\AgentationPortAllocator;
use App\Domain\AppDev\SsrPortAllocator;
use App\Domain\AppDev\VitePortRuntime;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeVitePortRuntime;

function ssr_port_node(string $name, bool $production = false): Node
{
    $node = Node::query()->create(['name' => $name, 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.20']);
    orbit_test_set_app_placement_role($node, $production);

    return $node;
}

function ssr_port_instance(Node $node, string $name): Instance
{
    $project = Project::query()->firstOrCreate(['slug' => 'recall'], ['name' => 'Recall', 'repository_url' => 'git@example.test:recall.git']);

    return Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name, 'checkout_path' => "/apps/recall/{$name}"]);
}

it('gives two development Instances on one Node distinct SSR ports and skips ports a live listener holds', function (): void {
    $node = ssr_port_node('shared');
    $runtime = new FakeVitePortRuntime;
    $runtime->occupied[$node->id] = range(SsrPortAllocator::FIRST_PORT, 13719);
    app()->instance(VitePortRuntime::class, $runtime);
    $first = ssr_port_instance($node, 'task-a');
    $second = ssr_port_instance($node, 'task-b');
    $allocator = app(SsrPortAllocator::class);

    expect($allocator->assign($first))->toBe(13720)
        ->and($allocator->assign($second))->toBe(13721)
        ->and($allocator->assign($first->refresh()))->toBe(13720)
        ->and($first->ssr_port)->toBe(13720)
        ->and($second->refresh()->ssr_port)->toBe(13721)
        ->and(InstanceData::fromModel($second)->toArray())->toMatchArray(['ssr_port' => 13721]);
});

it('skips Vite, annotation, and common service ports assigned on the Node', function (): void {
    $node = ssr_port_node('shared');
    $other = ssr_port_instance($node, 'other');
    DB::table('vite_port_assignments')->insert(['instance_id' => $other->id, 'node_id' => $node->id, 'port' => SsrPortAllocator::FIRST_PORT]);
    $other->forceFill(['agentation_port' => 13715])->save();
    app(AgentationPortAllocator::class)->retain($other, 'agentation_port');
    $runtime = Mockery::mock(VitePortRuntime::class);
    $runtime->shouldReceive('selectPort')->withArgs(fn (Node $n, int $preferred, array $excluded): bool => $n->id === $node->id
        && $preferred === SsrPortAllocator::FIRST_PORT
        && in_array(SsrPortAllocator::FIRST_PORT, $excluded, true)
        && in_array(13715, $excluded, true)
        && in_array(6379, $excluded, true))->once()->andReturn(13716);
    app()->instance(VitePortRuntime::class, $runtime);

    expect(app(SsrPortAllocator::class)->assign(ssr_port_instance($node, 'main')))->toBe(13716);
});

it('allows the same SSR port on two Nodes and assigns none to production', function (): void {
    $source = ssr_port_node('source');
    $destination = ssr_port_node('destination');
    $production = ssr_port_node('production', production: true);
    $allocator = app(SsrPortAllocator::class);

    expect($allocator->assign(ssr_port_instance($source, 'main')))->toBe(SsrPortAllocator::FIRST_PORT)
        ->and($allocator->assign(ssr_port_instance($destination, 'next')))->toBe(SsrPortAllocator::FIRST_PORT)
        ->and($allocator->assign($prod = ssr_port_instance($production, 'prod')))->toBeNull()
        ->and($prod->refresh()->ssr_port)->toBeNull()
        ->and(DB::table('ssr_port_assignments')->where('node_id', $production->id)->count())->toBe(0);
});

it('retains both placements until transfer cleanup and releases the assignment on removal', function (): void {
    $source = ssr_port_node('source');
    $destination = ssr_port_node('destination');
    $runtime = new FakeVitePortRuntime;
    $runtime->occupied[$destination->id] = [SsrPortAllocator::FIRST_PORT];
    app()->instance(VitePortRuntime::class, $runtime);
    $instance = ssr_port_instance($source, 'main');
    $allocator = app(SsrPortAllocator::class);

    expect($allocator->assign($instance))->toBe(SsrPortAllocator::FIRST_PORT)
        ->and($allocator->assign($instance, $destination))->toBe(13715)
        ->and($instance->refresh()->ssr_port)->toBe(SsrPortAllocator::FIRST_PORT)
        ->and(DB::table('ssr_port_assignments')->where('instance_id', $instance->id)->count())->toBe(2);

    $allocator->release($instance, $source);
    expect(DB::table('ssr_port_assignments')->where('instance_id', $instance->id)->pluck('node_id')->all())->toBe([$destination->id]);

    DB::table('instances')->where('id', $instance->id)->delete();
    expect(DB::table('ssr_port_assignments')->count())->toBe(0);
});
