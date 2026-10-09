<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Transfer\InstanceTransferStatus;
use App\Domain\Instances\Transfer\InstanceTransferStep;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\Schema;

it('creates the Instance transfer table and refuses to drop retained evidence', function (): void {
    expect(Schema::hasTable('instance_transfers'))->toBeTrue();

    $project = Project::query()->create([
        'name' => 'Transfer migration',
        'slug' => 'transfer-migration',
        'repository_url' => 'https://example.test/transfer-migration.git',
    ]);
    $node = Node::query()->create([
        'name' => 'transfer-migration',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => 'transfer-migration.example.test',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'web',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/apps/transfer-migration/web',
        'status' => InstanceState::Active,
    ]);
    InstanceTransfer::query()->create([
        'instance_id' => $instance->id,
        'source_node_id' => $node->id,
        'destination_node_id' => $node->id,
        'destination_name' => 'web',
        'destination_path' => '/srv/orbit/apps/transfer-migration/web',
        'destination_domain' => 'web.transfer-migration.dev.orbit',
        'source_layout' => 'checkout',
        'source_path' => '/srv/orbit/apps/transfer-migration/web',
        'source_route_id' => 1,
        'status' => InstanceTransferStatus::Completed,
        'current_step' => InstanceTransferStep::Completed,
    ]);

    $migration = require base_path(
        'database/migrations/2026_09_15_060000_create_app_instance_transfers_table.php',
    );

    expect(fn () => run_legacy_schema_migration($migration, 'down'))
        ->toThrow(RuntimeException::class, 'Cannot discard retained AppInstance transfer evidence.')
        ->and(Schema::hasTable('instance_transfers'))
        ->toBeTrue();
});

it('records the Routes with a web root that a transfer moved and refuses to drop that evidence', function (): void {
    $migration = require base_path('database/migrations/2026_10_23_000000_record_transfer_web_root_routes.php');
    $migration->down();
    expect(Schema::hasColumn('instance_transfers', 'web_root_route_ids'))->toBeFalse();
    $migration->up();

    $node = Node::query()->create([
        'name' => 'transfer-web-roots',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => 'transfer-web-roots.example.test',
    ]);
    $transfer = InstanceTransfer::query()->create([
        'source_node_id' => $node->id,
        'destination_node_id' => $node->id,
        'destination_name' => 'web',
        'destination_path' => '/srv/orbit/apps/transfer-web-roots/web',
        'destination_domain' => 'web.transfer-web-roots.dev.orbit',
        'source_layout' => 'checkout',
        'source_path' => '/srv/orbit/apps/transfer-web-roots/web',
        'source_route_id' => 1,
        'web_root_route_ids' => [2, 3],
        'status' => InstanceTransferStatus::Completed,
        'current_step' => InstanceTransferStep::Completed,
    ]);

    expect($transfer->refresh()->web_root_route_ids)->toBe([2, 3])
        ->and(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot discard retained Instance transfer web-root Route evidence.')
        ->and(Schema::hasColumn('instance_transfers', 'web_root_route_ids'))->toBeTrue();
});
