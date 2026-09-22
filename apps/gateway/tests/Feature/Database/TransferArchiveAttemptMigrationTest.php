<?php

declare(strict_types=1);

use App\Domain\AppInstances\Transfer\TransferArchiveAttempt;
use App\Domain\Nodes\ManagedUserAccount;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceTransfer;
use App\Models\Node;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('upgrades existing transfers without inventing archive ownership evidence', function (): void {
    $migration = require base_path('database/migrations/2026_09_22_110138_add_archive_attempt_to_app_instance_transfers.php');
    $migration->down();

    try {
        $transfer = archive_migration_transfer();
        $before = (array) DB::table('app_instance_transfers')->where('id', $transfer->id)->first();
        $migration->up();
        $after = (array) DB::table('app_instance_transfers')->where('id', $transfer->id)->first();

        expect($transfer->refresh()->archive_attempt)->toBeNull()
            ->and(Arr::except($after, ['archive_attempt']))->toBe($before);
    } finally {
        if (! Schema::hasColumn('app_instance_transfers', 'archive_attempt')) {
            $migration->up();
        }
    }
});

it('preserves pending archive identities during refused rollback and permits rollback after confirmed cleanup', function (): void {
    $migration = require base_path('database/migrations/2026_09_22_110138_add_archive_attempt_to_app_instance_transfers.php');
    $transfer = archive_migration_transfer();
    $attempt = TransferArchiveAttempt::create(
        $transfer,
        new ManagedUserAccount('source-user', 'source-group', '/srv/source-home'),
        new ManagedUserAccount('destination-user', 'destination-group', '/srv/destination-home'),
    );
    $transfer->update(['archive_attempt' => $attempt->toArray()]);
    $stored = DB::table('app_instance_transfers')->where('id', $transfer->id)->value('archive_attempt');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot discard pending transfer archive evidence.');
    expect(DB::table('app_instance_transfers')->where('id', $transfer->id)->value('archive_attempt'))->toBe($stored)
        ->and(TransferArchiveAttempt::fromArray($transfer->refresh()->archive_attempt, $transfer)->toArray())->toBe($attempt->toArray());

    $transfer->update(['archive_attempt' => null]);
    try {
        $migration->down();
        expect(Schema::hasColumn('app_instance_transfers', 'archive_attempt'))->toBeFalse();
    } finally {
        $migration->up();
    }
    expect($transfer->refresh()->archive_attempt)->toBeNull();
});

function archive_migration_transfer(): AppInstanceTransfer
{
    $app = OrbitApp::query()->create([
        'name' => 'Archive migration', 'slug' => 'archive-migration',
        'repository_url' => 'https://example.test/archive-migration.git',
    ]);
    $source = Node::query()->create(['name' => 'archive-migration-source', 'public_ssh_host' => '192.0.2.210']);
    $destination = Node::query()->create(['name' => 'archive-migration-destination', 'public_ssh_host' => '192.0.2.211']);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id, 'node_id' => $source->id, 'name' => 'web',
        'environment' => 'development', 'checkout_path' => '/srv/source-home/apps/web',
    ]);

    return AppInstanceTransfer::query()->create([
        'app_instance_id' => $instance->id,
        'source_node_id' => $source->id, 'destination_node_id' => $destination->id,
        'destination_name' => 'web', 'destination_path' => '/srv/destination-home/apps/web',
        'destination_domain' => 'web.archive.test', 'source_layout' => 'checkout',
        'source_path' => '/srv/source-home/apps/web', 'source_route_id' => 1,
        'status' => 'reserved', 'current_step' => 'reserved',
    ]);
}
