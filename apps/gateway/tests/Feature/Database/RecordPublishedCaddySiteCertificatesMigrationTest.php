<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Models\Node;
use Illuminate\Database\Migrations\Migration;

it('records a certificate for every role site that renders today and for nothing else', function (): void {
    $gateway = caddy_certificate_migration_node('gateway', [RoleName::Gateway->value => [LifecycleStatus::Active, null]]);
    $websocket = caddy_certificate_migration_node('websocket', [RoleName::WebSocket->value => [LifecycleStatus::Active, null]]);
    $retrying = caddy_certificate_migration_node('analytics', [RoleName::Analytics->value => [LifecycleStatus::Failed, 'converge:analytics-health']]);
    $removing = caddy_certificate_migration_node('leaving', [RoleName::WebSocket->value => [LifecycleStatus::Removing, null]]);
    $removalFailed = caddy_certificate_migration_node('stuck', [RoleName::Analytics->value => [LifecycleStatus::Failed, 'remove:analytics']]);
    $metrics = caddy_certificate_migration_node('metrics', [RoleName::Metrics->value => [LifecycleStatus::Active, null]]);
    $collector = caddy_certificate_migration_node('collector', []);
    app(ProxyCliState::class)->enable($collector->id, 'cache', 'http://127.0.0.1:8317', 'management', 'read', 'control');
    $migration = caddy_certificate_migration();
    $migration->down();

    $migration->up();

    $records = new CaddySiteCertificates;

    expect($records->published($websocket->id, CaddySiteCertificates::Websocket))->toBeTrue()
        ->and($records->published($retrying->id, CaddySiteCertificates::Analytics))->toBeTrue()
        ->and($records->published($gateway->id, CaddySiteCertificates::Metrics))->toBeTrue()
        ->and($records->published($collector->id, CaddySiteCertificates::ProxyCli))->toBeTrue()
        ->and($records->published($removing->id, CaddySiteCertificates::Websocket))->toBeFalse()
        ->and($records->published($removalFailed->id, CaddySiteCertificates::Analytics))->toBeFalse()
        ->and($records->published($metrics->id, CaddySiteCertificates::Metrics))->toBeFalse();

    $migration->down();

    expect($records->published($websocket->id, CaddySiteCertificates::Websocket))->toBeFalse()
        ->and(app(ProxyCliState::class)->enabled())->toBeTrue();
});

it('records no Metrics certificate on the Gateway while no Metrics role renders', function (): void {
    $gateway = caddy_certificate_migration_node('gateway', [RoleName::Gateway->value => [LifecycleStatus::Active, null]]);
    $migration = caddy_certificate_migration();
    $migration->down();

    $migration->up();

    expect(new CaddySiteCertificates()->published($gateway->id, CaddySiteCertificates::Metrics))->toBeFalse();
});

/** @param array<string, array{LifecycleStatus, ?string}> $roles */
function caddy_certificate_migration_node(string $name, array $roles): Node
{
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "{$name}.example.test",
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.'.(Node::query()->count() + 10),
    ]);

    foreach ($roles as $role => [$status, $failedStep]) {
        $node->roles()->create(['role' => $role, 'status' => $status, 'failed_step' => $failedStep]);
    }

    return $node;
}

function caddy_certificate_migration(): Migration
{
    return require base_path('database/migrations/2026_09_25_130000_record_published_caddy_site_certificates.php');
}
