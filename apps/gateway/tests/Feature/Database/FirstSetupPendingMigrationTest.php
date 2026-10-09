<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

it('marks only unfinished instance:create rows, never task workspaces or active Instances', function (): void {
    $migration = require base_path('database/migrations/2026_10_19_000001_record_instance_first_setup_pending.php');
    $node = Node::query()->create([
        'name' => 'app-dev',
        'status' => 'active',
        'public_ssh_host' => 'app-dev.test',
        'wireguard_ip' => '10.44.0.91',
    ]);
    $project = Project::query()->create([
        'name' => 'Shop',
        'slug' => 'shop',
        'repository_url' => 'https://example.test/shop.git',
        'apps' => fixture_apps(null),
    ]);
    $instance = static fn (string $name, InstanceState $status, ?bool $routed): Instance => Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'environment' => 'development',
        'checkout_path' => "/srv/shop/{$name}",
        'status' => $status,
        'task_workspace_routed' => $routed,
    ]);
    $unfinished = $instance('feature', InstanceState::SourceResolved, null);
    $reserved = $instance('reserved', InstanceState::Reserved, null);
    $active = $instance('default', InstanceState::Active, null);
    $unroutedWorkspace = $instance('task-1', InstanceState::SourceResolved, false);
    $routedWorkspace = $instance('task-2', InstanceState::SourceResolved, true);

    $migration->down();
    $migration->up();

    $pending = DB::table('instances')->pluck('first_setup_pending', 'id')->map(static fn (mixed $value): bool => (bool) $value);
    expect($pending[$unfinished->id])->toBeTrue()
        ->and($pending[$reserved->id])->toBeTrue()
        ->and($pending[$active->id])->toBeFalse()
        ->and($pending[$unroutedWorkspace->id])->toBeFalse()
        ->and($pending[$routedWorkspace->id])->toBeFalse();
});
