<?php

declare(strict_types=1);

use App\Domain\AppDev\AgentationEndpoint;
use App\Domain\AppDev\AgentationPortAllocator;
use App\Domain\AppDev\AgentationUrlProjection;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('builds the reserved Agentation origin and stored placeholder', function (): void {
    expect(AgentationEndpoint::origin('commander.test'))
        ->toBe('https://commander.test/__orbit/agentation')
        ->and(AgentationEndpoint::upstream(4749))
        ->toBe('127.0.0.1:4749')
        ->and(AgentationEndpoint::STORED_URL)
        ->toBe('https://{{app_instance.domain}}/__orbit/agentation')
        ->and(AgentationEndpoint::PATH)
        ->toBe('/__orbit/agentation')
        ->and(AgentationEndpoint::PORT)
        ->toBe(4747);
});

it('assigns the first Agentation port and reuses it', function (): void {
    $instance = agentation_projection_instance('main');

    expect(app(AgentationPortAllocator::class)->assign($instance))
        ->toBe(4747)
        ->and($instance->refresh()->agentation_port)
        ->toBe(4747)
        ->and(app(AgentationPortAllocator::class)->assign($instance->refresh()))
        ->toBe(4747);
});

it('skips ports already used on the destination Node', function (): void {
    $occupied = agentation_projection_instance('occupied');
    $occupied->forceFill(['agentation_port' => 4747])->save();
    $moving = agentation_projection_instance('moving');

    expect(app(AgentationPortAllocator::class)->nextAvailable($occupied->node_id, $moving->id))
        ->toBe(4748);
});

it('stores and forgets the AGENTATION_URL placeholder', function (): void {
    $instance = agentation_projection_instance('env');
    $projection = app(AgentationUrlProjection::class);
    $projection->project($instance);

    expect($instance->environmentValues()->where('env_key', AgentationEndpoint::URL_KEY)->sole()->env_value)
        ->toBe(AgentationEndpoint::STORED_URL);

    $projection->forget($instance);

    expect($instance->environmentValues()->where('env_key', AgentationEndpoint::URL_KEY)->exists())->toBeFalse();
});

function agentation_projection_instance(string $name): AppInstance
{
    $node = Node::query()->create([
        'name' => 'agentation-'.$name,
        'platform' => 'linux',
        'user' => 'orbit',
        'public_ssh_host' => '192.0.2.'.(80 + crc32($name) % 20),
        'status' => 'active',
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Agentation '.$name,
        'slug' => 'agentation-'.$name,
        'repository_url' => 'git@example.test:agentation-'.$name.'.git',
    ]);

    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $name,
        'environment' => 'development',
        'checkout_path' => '/apps/'.$name,
    ]);
}
