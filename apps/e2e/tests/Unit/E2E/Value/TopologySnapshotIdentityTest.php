<?php

declare(strict_types=1);

use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologySnapshotIdentity;

describe('TopologySnapshotIdentity', function (): void {
    it('gives the repository one persistent topology snapshot', function (): void {
        $primary = TopologySnapshotIdentity::primary();

        expect($primary->network())
            ->toBe('oe-topo-snap')
            ->and($primary->instancePrefix())
            ->toBe('orbit-e2e-topology-snapshot-')
            ->and($primary->instance('gateway'))
            ->toBe('orbit-e2e-topology-snapshot-gateway')
            ->and($primary->slot)
            ->toBe(1);
    });

    it('lists every topology role in profile order', function (): void {
        expect(TopologySnapshotIdentity::primary()->instances())
            ->toBe([
                'orbit-e2e-topology-snapshot-gateway',
                'orbit-e2e-topology-snapshot-app-dev',
                'orbit-e2e-topology-snapshot-app-prod',
            ])
            ->and(TopologyProfile::ROLES)
            ->toBe(['gateway', 'app-dev', 'app-prod']);
    });

    it('keeps the retired physical identity available only for explicit migration', function (): void {
        expect(TopologySnapshotIdentity::retired()->network())
            ->toBe('oe-standby')
            ->and(TopologySnapshotIdentity::retired()->instance('gateway'))
            ->toBe('orbit-e2e-standby-gateway');
    });

    it('rejects an unknown topology role', function (): void {
        expect(fn () => TopologySnapshotIdentity::primary()->instance('unknown'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('keeps every topology snapshot network inside the managed Incus bridge interface limit', function (): void {
        $network = TopologySnapshotIdentity::primary()->network();

        expect($network)
            ->toMatch('/\Aoe-[a-z0-9](?:[a-z0-9-]{0,10}[a-z0-9])?\z/D')
            ->and(strlen($network))
            ->toBeLessThanOrEqual(15);
    });
});
