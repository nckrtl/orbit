<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

    $recovery->down();
    $cleanup->down();

    try {
        expect(fn () => $migration->down())
            ->toThrow(
                RuntimeException::class,
                "Cannot roll back while AppInstance registrations are incomplete: {$instance->id}",
            )
            ->and(Schema::hasColumns('app_instances', $columns))
            ->toBeTrue()
            ->and(DB::table('app_instances')->where('id', $instance->id)->value('registration_request_id'))
            ->not->toBeNull();
    } finally {
        DB::table('app_instances')
            ->where('id', $instance->id)
            ->update([
                'registration_completed_at' => now(),
                'registration_relocation_state' => 'relocated',
            ]);
        $migration->down();
        $migration->up();
        $cleanup->up();
        $recovery->up();
    }
});

it('refuses to discard source identity while verified original cleanup is incomplete', function (
    string $checkpoint,
): void {
    $instance = orb105_registration_migration_instance($checkpoint);
    $migration = orb105_cleanup_identity_migration();
    $columns = ['registration_source_device', 'registration_source_inode'];

    try {
        expect(fn () => $migration->down())
            ->toThrow(
                RuntimeException::class,
                "Cannot roll back while AppInstance source cleanup is incomplete: {$instance->id}",
            )
            ->and(Schema::hasColumns('app_instances', $columns))
            ->toBeTrue()
            ->and($instance->refresh()->registration_source_device)
            ->toBe(41)
            ->and($instance->registration_source_inode)
            ->toBe(42);
    } finally {
        $instance->update(['registration_relocation_state' => 'relocated']);
        $migration->down();
        $migration->up();
    }
})->with(['destination verified' => 'destination_verified', 'original cleanup' => 'original_cleanup']);

it('refuses to discard durable manual migration recovery', function (): void {
    $instance = orb105_registration_migration_instance('relocated');
    $instance->update([
        'registration_migration_recovery' => [
            'app_instance' => ['name' => '13.x'],
            'route' => ['id' => 41, 'hostname' => 'preserved.test', 'provenance' => 'explicit'],
        ],
    ]);
    $migration = orb105_migration_recovery_migration();

    try {
        expect(fn () => $migration->down())
            ->toThrow(
                RuntimeException::class,
                "Cannot roll back while AppInstance migration recovery is incomplete: {$instance->id}",
            )
            ->and(Schema::hasColumn('app_instances', 'registration_migration_recovery'))
            ->toBeTrue()
            ->and($instance->refresh()->registration_migration_recovery)
            ->not->toBeNull();
    } finally {
        $instance->update(['registration_migration_recovery' => null]);
        $migration->down();
        $migration->up();
    }
});

function orb105_registration_migration_instance(string $checkpoint): AppInstance
{
    $node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.105',
        'wireguard_ip' => '10.44.0.105',
        'user' => 'orbit',
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);

    return AppInstance::query()->create([
        'app_id' => $app->id,
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
