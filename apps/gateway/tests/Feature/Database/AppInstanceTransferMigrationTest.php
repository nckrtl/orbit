<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Transfer\AppInstanceTransferStatus;
use App\Domain\AppInstances\Transfer\AppInstanceTransferStep;
use App\Domain\Shared\LifecycleStatus;
use App\Models\AppInstanceTransfer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\Schema;

it('creates the Instance transfer table and refuses to drop retained evidence', function (): void {
    expect(Schema::hasTable('instance_transfers'))->toBeTrue();

    $app = Project::query()->create([
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
        'project_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'web',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/apps/transfer-migration/web',
        'status' => AppInstanceState::Active,
    ]);
    AppInstanceTransfer::query()->create([
        'instance_id' => $instance->id,
        'source_node_id' => $node->id,
        'destination_node_id' => $node->id,
        'destination_name' => 'web',
        'destination_path' => '/srv/orbit/apps/transfer-migration/web',
        'destination_domain' => 'web.transfer-migration.dev.orbit',
        'source_layout' => 'checkout',
        'source_path' => '/srv/orbit/apps/transfer-migration/web',
        'source_route_id' => 1,
        'status' => AppInstanceTransferStatus::Completed,
        'current_step' => AppInstanceTransferStep::Completed,
    ]);

    $migration = require base_path(
        'database/migrations/2026_09_15_060000_create_app_instance_transfers_table.php',
    );

    expect(fn () => run_legacy_schema_migration($migration, 'down'))
        ->toThrow(RuntimeException::class, 'Cannot discard retained AppInstance transfer evidence.')
        ->and(Schema::hasTable('instance_transfers'))
        ->toBeTrue();
});
