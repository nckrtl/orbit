<?php

declare(strict_types=1);

use App\Domain\AppInstances\Transfer\TransferDestinationAttempt;
use App\Domain\Nodes\ManagedUserAccount;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceTransfer;
use App\Models\Node;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('preserves legacy transfers without adopting their destination paths during upgrade', function (): void {
    $migration = require base_path('database/migrations/2026_09_22_114913_add_destination_attempt_to_app_instance_transfers.php');
    $migration->down();

    try {
        $transfer = destination_migration_transfer();
        $before = (array) DB::table('app_instance_transfers')->where('id', $transfer->id)->first();
        $migration->up();
        $after = (array) DB::table('app_instance_transfers')->where('id', $transfer->id)->first();

        expect($transfer->refresh()->destination_attempt)->toBeNull()
            ->and(Arr::except($after, ['destination_attempt']))->toBe($before);
    } finally {
        if (! Schema::hasColumn('app_instance_transfers', 'destination_attempt')) {
            $migration->up();
        }
    }
});

it('retains destination ownership through a refused rollback until its lifecycle clears the evidence', function (): void {
    $migration = require base_path('database/migrations/2026_09_22_114913_add_destination_attempt_to_app_instance_transfers.php');
    $transfer = destination_migration_transfer();
    $attempt = TransferDestinationAttempt::create($transfer, new ManagedUserAccount('orbit', 'orbit', '/srv/managed-home'))
        ->acquiring()->withReceipt(['root' => '1:1', 'parent' => '1:2', 'scope' => '1:3', 'checkout' => '1:4']);
    $transfer->update(['destination_attempt' => $attempt->toArray()]);
    $stored = DB::table('app_instance_transfers')->where('id', $transfer->id)->value('destination_attempt');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot discard transfer destination ownership evidence.');
    expect(DB::table('app_instance_transfers')->where('id', $transfer->id)->value('destination_attempt'))->toBe($stored)
        ->and(TransferDestinationAttempt::fromArray($transfer->refresh()->destination_attempt, $transfer)->toArray())->toBe($attempt->toArray());

    $transfer->update(['destination_attempt' => null]);
    try {
        $migration->down();
        expect(Schema::hasColumn('app_instance_transfers', 'destination_attempt'))->toBeFalse();
    } finally {
        $migration->up();
    }
});

function destination_migration_transfer(): AppInstanceTransfer
{
    $app = OrbitApp::query()->create([
        'name' => 'Destination migration', 'slug' => 'destination-migration',
        'repository_url' => 'https://example.test/destination-migration.git',
    ]);
    $source = Node::query()->create(['name' => 'destination-migration-source', 'public_ssh_host' => '192.0.2.210']);
    $destination = Node::query()->create(['name' => 'destination-migration-target', 'public_ssh_host' => '192.0.2.211']);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id, 'node_id' => $source->id, 'name' => 'web',
        'environment' => 'development', 'checkout_path' => '/srv/source-home/apps/web',
    ]);

    return AppInstanceTransfer::query()->create([
        'app_instance_id' => $instance->id,
        'source_node_id' => $source->id, 'destination_node_id' => $destination->id,
        'destination_name' => 'web', 'destination_path' => '/srv/destination-home/apps/web',
        'destination_domain' => 'web.destination.test', 'source_layout' => 'checkout',
        'source_path' => '/srv/source-home/apps/web', 'source_route_id' => 1,
        'status' => 'reserved', 'current_step' => 'reserved',
    ]);
}
