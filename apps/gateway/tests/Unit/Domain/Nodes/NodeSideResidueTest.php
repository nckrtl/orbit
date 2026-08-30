<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeSideResidue;
use App\Domain\Nodes\RoleName;

it('always names the Metrics exporter footprint, whatever the role', function (): void {
    expect(new NodeSideResidue()->describe([]))
        ->toBe([
            'Metrics node exporter package, its Orbit systemd drop-in and its firewall rule for port 9100',
        ]);
});

it('names what each app role leaves behind', function (RoleName $role, string $expected): void {
    expect(new NodeSideResidue()->describe([$role]))->toContain($expected);
})->with([
    'app-prod caddy' => [RoleName::AppProd, 'Caddy site configuration and certificates for the app-prod role'],
    'app-prod firewall' => [RoleName::AppProd, 'Orbit firewall rules for the app-prod role'],
    'app-prod runtime' => [RoleName::AppProd, 'Managed PHP-FPM pools and instance checkouts'],
    'app-dev caddy' => [RoleName::AppDev, 'Caddy site configuration and certificates for the app-dev role'],
    'app-dev workspaces' => [RoleName::AppDev, 'Managed PHP-FPM pools, workspace checkouts and instance checkouts'],
    'metrics containers' => [RoleName::Metrics, 'Prometheus and Grafana containers and their named volumes'],
    'metrics marker' => [RoleName::Metrics, '/etc/orbit/metrics including the Orbit ownership marker'],
    'metrics firewall' => [RoleName::Metrics, 'Grafana upstream firewall rule'],
]);

it('merges several roles into one sorted list without repeating the exporter', function (): void {
    $lines = new NodeSideResidue()->describe([RoleName::AppProd, RoleName::Metrics]);
    $sorted = $lines;
    sort($sorted, SORT_STRING);

    expect($lines)
        ->toBe($sorted)
        ->and($lines)
        ->toBe(array_values(array_unique($lines)))
        ->and(count($lines))
        ->toBe(7);
});

it('leaves nothing behind for the roles that cannot be removed', function (RoleName $role): void {
    expect(new NodeSideResidue()->describe([$role]))->toHaveCount(1);
})->with([
    'gateway' => [RoleName::Gateway],
    'vpn' => [RoleName::Vpn],
]);

it('offers one follow-up that does not name a command Orbit may not ship', function (): void {
    expect(NodeSideResidue::FOLLOW_UP)
        ->toBe('Run the node-local Metrics cleanup on the node once it boots, or discard the node.');
});
