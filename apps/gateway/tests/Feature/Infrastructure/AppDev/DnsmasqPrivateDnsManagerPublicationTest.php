<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevDnsConfigRenderer;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Infrastructure\AppDev\PrivateDnsAnswerCatalog;
use App\Models\Node;
use Tests\Support\PrivateDnsPublishHarness;

it('publishes records and the requester catalog idempotently', function (): void {
    $harness = new PrivateDnsPublishHarness;
    $node = orb258_published_peer();

    try {
        $manager = $harness->manager();
        $manager->converge();
        $records = file_get_contents($harness->recordsPath());
        $catalog = file_get_contents($harness->catalogPath());
        $manager->converge();

        expect($records)
            ->toBe($harness->recordsPath() !== '' ? file_get_contents($harness->recordsPath()) : '')
            ->toContain('address=/.peer.test/10.44.0.30')
            ->and($catalog)
            ->toBe(file_get_contents($harness->catalogPath()))
            ->and(json_decode((string) $catalog, true)['requesters'] ?? [])
            ->toBe(['10.44.0.30' => $node->id])
            ->and($harness->serviceCalls())
            ->toBe(['restart dnsmasq', 'is-active --quiet dnsmasq']);
    } finally {
        $harness->cleanup();
    }
});

it('leaves the working records and catalog in place when validation fails', function (): void {
    $harness = new PrivateDnsPublishHarness;
    orb258_published_peer();
    $previousRecords = "# Managed by Orbit.\nhost-record=gateway.orbit,10.44.0.1\n";
    $previousCatalog = "{\"requesters\":{},\"records\":{\"gateway.orbit\":\"10.44.0.1\"},\"suffixes\":{},\"overrides\":{}}\n";

    try {
        $harness->putRecords($previousRecords);
        $harness->putCatalog($previousCatalog);
        $harness->markActive();
        $harness->failValidation();

        expect(fn () => $harness->manager()->converge())
            ->toThrow(RuntimeConvergenceException::class);

        expect(file_get_contents($harness->recordsPath()))
            ->toBe($previousRecords)
            ->and(file_get_contents($harness->catalogPath()))
            ->toBe($previousCatalog);
    } finally {
        $harness->cleanup();
    }
});

it('restores the previous working service after a restart failure and republishes on retry', function (): void {
    $harness = new PrivateDnsPublishHarness;
    orb258_published_peer();
    $previousRecords = "# Managed by Orbit.\nhost-record=gateway.orbit,10.44.0.1\n";
    $previousCatalog = "{\"requesters\":{},\"records\":{\"gateway.orbit\":\"10.44.0.1\"},\"suffixes\":{},\"overrides\":{}}\n";

    try {
        $harness->putRecords($previousRecords);
        $harness->putCatalog($previousCatalog);
        $harness->markActive();
        $harness->failRestart();

        expect(fn () => $harness->manager()->converge())
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('app-dev.dns_config_failed');
            });

        expect(file_get_contents($harness->recordsPath()))
            ->toBe($previousRecords)
            ->and(file_get_contents($harness->catalogPath()))
            ->toBe($previousCatalog);

        $harness->clearRestartFailure();
        $harness->manager()->converge();
        $expected = new AppDevDnsConfigRenderer(new AppDevSiteRepository)->render();

        expect(file_get_contents($harness->recordsPath()))
            ->toBe($expected)
            ->and(PrivateDnsAnswerCatalog::fromPublished(
                json_decode((string) file_get_contents($harness->catalogPath()), true) ?? [],
            )->suffixes)
            ->toHaveKey('peer.test');
    } finally {
        $harness->cleanup();
    }
});

function orb258_published_peer(): Node
{
    $node = Node::query()->create([
        'name' => 'published-peer',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => 'peer.test',
        'public_ssh_host' => '192.0.2.30',
        'wireguard_ip' => '10.44.0.30',
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);

    return $node;
}
