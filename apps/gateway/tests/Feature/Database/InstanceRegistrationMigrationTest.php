<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function (): void {
    owned_interrupted_creation_removal_migration()->down();
    restore_app_era_instance_leftovers_for_migration_test();
});
afterEach(function (): void {
    drop_app_era_instance_leftovers_for_migration_test();
    owned_interrupted_creation_removal_migration()->up();
});

it('refuses to discard base registration evidence while an operation is incomplete', function (): void {
    $instance = orb105_registration_migration_instance('reserved');
    $cleanup = orb105_cleanup_identity_migration();
    $recovery = orb105_migration_recovery_migration();
    $migration = orb105_registration_evidence_migration();
    $columns = [
        'registration_original_path',
        'registration_request_id',
        'registration_relocation_state',
        'registration_authoritative_path',
        'registration_completed_at',
    ];

    run_legacy_schema_migration($recovery, 'down');
    run_legacy_schema_migration($cleanup, 'down');

    try {
        expect(fn () => run_legacy_schema_migration($migration, 'down'))
            ->toThrow(
                RuntimeException::class,
                "Cannot roll back while AppInstance registrations are incomplete: {$instance->id}",
            )
            ->and(Schema::hasColumns('instances', $columns))
            ->toBeTrue()
            ->and(DB::table('instances')->where('id', $instance->id)->value('registration_request_id'))
            ->not->toBeNull();
    } finally {
        DB::table('instances')
            ->where('id', $instance->id)
            ->update([
                'registration_completed_at' => now(),
                'registration_relocation_state' => 'relocated',
            ]);
        run_legacy_schema_migration($migration, 'down');
        run_legacy_schema_migration($migration, 'up');
        run_legacy_schema_migration($cleanup, 'up');
        run_legacy_schema_migration($recovery, 'up');
    }
});

it('refuses to discard source identity while verified original cleanup is incomplete', function (
    string $checkpoint,
): void {
    $instance = orb105_registration_migration_instance($checkpoint);
    $migration = orb105_cleanup_identity_migration();
    $columns = ['registration_source_device', 'registration_source_inode'];

    try {
        expect(fn () => run_legacy_schema_migration($migration, 'down'))
            ->toThrow(
                RuntimeException::class,
                "Cannot roll back while AppInstance source cleanup is incomplete: {$instance->id}",
            )
            ->and(Schema::hasColumns('instances', $columns))
            ->toBeTrue()
            ->and($instance->refresh()->registration_source_device)
            ->toBe(41)
            ->and($instance->registration_source_inode)
            ->toBe(42);
    } finally {
        $instance->update(['registration_relocation_state' => 'relocated']);
        run_legacy_schema_migration($migration, 'down');
        run_legacy_schema_migration($migration, 'up');
    }
})->with(['destination verified' => 'destination_verified', 'original cleanup' => 'original_cleanup']);

it('refuses to discard durable manual migration recovery', function (): void {
    $instance = orb105_registration_migration_instance('relocated');
    DB::table('instances')->where('id', $instance->id)->update([
        'registration_migration_recovery' => json_encode([
            'app_instance' => ['name' => '13.x'],
            'route' => ['id' => 41, 'domain' => 'preserved.test', 'provenance' => 'explicit'],
        ], JSON_THROW_ON_ERROR),
    ]);
    $migration = orb105_migration_recovery_migration();

    try {
        expect(fn () => run_legacy_schema_migration($migration, 'down'))
            ->toThrow(
                RuntimeException::class,
                "Cannot roll back while AppInstance migration recovery is incomplete: {$instance->id}",
            )
            ->and(Schema::hasColumn('instances', 'registration_migration_recovery'))
            ->toBeTrue()
            ->and($instance->refresh()->registration_migration_recovery)
            ->not->toBeNull();
    } finally {
        DB::table('instances')->where('id', $instance->id)->update([
            'registration_migration_recovery' => null,
        ]);
        run_legacy_schema_migration($migration, 'down');
        run_legacy_schema_migration($migration, 'up');
    }
});

function orb105_registration_migration_instance(string $checkpoint): Instance
{
    $node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.105',
        'wireguard_ip' => '10.44.0.105',
        'user' => 'orbit',
    ]);
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main',
        'apps' => fixture_apps('public'),
    ]);

    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'default',
        'checkout_path' => '/srv/orbit/apps/acme/default',
        'registration_original_path' => '/work/acme',
        'registration_request_id' => (string) Str::uuid(),
        'registration_relocation_state' => $checkpoint,
        'registration_authoritative_path' => $checkpoint === 'reserved'
            ? '/work/acme'
            : '/srv/orbit/apps/acme/default',
        'registration_source_device' => 41,
        'registration_source_inode' => 42,
        'status' => 'reserved',
    ]);
}

function orb105_registration_evidence_migration(): object
{
    return require base_path(
        'database/migrations/2026_09_09_000000_add_app_instance_registration_evidence.php',
    );
}

function orb105_cleanup_identity_migration(): object
{
    return require base_path(
        'database/migrations/2026_09_09_104800_add_registration_cleanup_identity_to_app_instances.php',
    );
}

function orb105_migration_recovery_migration(): object
{
    return require base_path(
        'database/migrations/2026_09_09_120000_add_registration_migration_recovery_to_app_instances.php',
    );
}
