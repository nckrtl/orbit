<?php

declare(strict_types=1);

use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function legacy_per_app_transfer(bool $cutover = false): InstanceTransfer
{
    $project = Project::query()->create(['name' => 'Transfer', 'slug' => 'transfer', 'repository_url' => 'https://example.test/transfer.git']);
    $node = Node::query()->create(['name' => 'migration', 'public_ssh_host' => 'migration.example.test', 'status' => 'active']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'main', 'checkout_path' => '/srv/orbit/apps/project/main', 'app_runtime' => ['web' => ['annotator_store_identity' => false]]]);
    $instance->processes()->create(['app' => 'web', 'name' => 'annotator', 'runtime' => 'systemd', 'working_directory' => $instance->checkout_path, 'runtime_config' => ['preset' => 'annotator'], 'desired_state' => 'running', 'status' => 'active', 'restart_policy' => 'always']);

    return InstanceTransfer::query()->create([
        'instance_id' => $instance->id, 'source_node_id' => $node->id, 'destination_node_id' => $node->id,
        'destination_name' => 'main', 'destination_path' => '/srv/orbit/apps/project/main', 'destination_domain' => 'web.project.test',
        'source_path' => $instance->checkout_path, 'source_layout' => 'checkout', 'source_route_id' => 11,
        'destination_route_id' => 12, 'source_router_node_id' => $node->id, 'status' => 'in_progress',
        'current_step' => $cutover ? 'cutover' : 'source-paused', 'cutover_at' => $cutover ? now() : null,
        'imported_environment_keys' => ['IMPORTED'],
    ]);
}

it('per-app transfer migrates legacy precutover ownership and retries through rollback instead of checkout store staging', function (): void {
    $transfer = legacy_per_app_transfer();
    Schema::table('instance_transfers', static fn (Blueprint $table) => $table->dropColumn('app_journal'));
    $migration = require base_path('database/migrations/2026_10_19_000003_add_app_journal_to_instance_transfers.php');
    $migration->up();
    expect($transfer->refresh()->app_journal['web'])->toBe([
        'source_route_id' => 11, 'destination_route_id' => 12, 'destination_domain' => 'web.project.test',
        'source_router_node_id' => $transfer->source_node_id, 'source_app_identity' => false, 'imported_environment_keys' => ['IMPORTED'],
        'annotator' => ['source_store' => '/var/lib/orbit/annotator/instance-'.$transfer->instance_id],
    ])->and($transfer->recovery_evidence['rollback_pending'])->toBeTrue();
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot discard per-app transfer ownership.');
});

it('per-app transfer migration preserves closed history without reopening abandoned attempts', function (string $status): void {
    $transfer = legacy_per_app_transfer();
    $transfer->update(['status' => $status, 'current_step' => $status === 'completed' ? 'completed' : 'reserved', 'imported_environment_keys' => [], 'recovery_evidence' => null]);
    Schema::table('instance_transfers', static fn (Blueprint $table) => $table->dropColumn('app_journal'));
    $migration = require base_path('database/migrations/2026_10_19_000003_add_app_journal_to_instance_transfers.php');
    $migration->up();
    expect($transfer->refresh()->recovery_evidence)->toBeNull()->and(InstanceTransfer::query()->open()->exists())->toBeFalse();
})->with(['completed', 'failed']);

it('per-app transfer refuses to guess legacy cutover annotator ownership during upgrade', function (): void {
    legacy_per_app_transfer(cutover: true);
    Schema::table('instance_transfers', static fn (Blueprint $table) => $table->dropColumn('app_journal'));
    $migration = require base_path('database/migrations/2026_10_19_000003_add_app_journal_to_instance_transfers.php');
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Complete cutover annotator transfers');
    expect(Schema::hasColumn('instance_transfers', 'app_journal'))->toBeFalse();
});
