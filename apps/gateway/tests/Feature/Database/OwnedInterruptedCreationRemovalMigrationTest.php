<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('round-trips the receipt-backed interrupted creation eligibility guards', function (): void {
    $migration = require base_path('database/migrations/2026_10_10_000001_allow_owned_interrupted_creation_removal.php');
    $names = ['instance_removals_insert', 'instance_removal_members_insert', 'instances_removal_status_update'];
    $before = DB::table('sqlite_master')->whereIn('name', $names)->orderBy('name')->pluck('sql', 'name')->all();
    expect($before)->toHaveCount(3);
    foreach ($before as $sql) {
        expect($sql)->toContain('source_prepare_id IS NOT NULL', 'registration_request_id IS NULL', 'task_workspace_routed IS NOT 0');
    }
    $migration->down();
    foreach (DB::table('sqlite_master')->whereIn('name', $names)->pluck('sql') as $sql) {
        expect($sql)->not->toContain('source_prepare_id');
    }
    $migration->up();
    expect(DB::table('sqlite_master')->whereIn('name', $names)->orderBy('name')->pluck('sql', 'name')->all())->toBe($before);
});
