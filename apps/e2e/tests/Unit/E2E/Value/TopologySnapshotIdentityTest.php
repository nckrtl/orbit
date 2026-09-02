<?php

declare(strict_types=1);

use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologySnapshotIdentity;

describe('TopologySnapshotIdentity', function (): void {
    it('gives the repository primary checkout its own unnamespaced resources', function (): void {
        $primary = TopologySnapshotIdentity::primary();

        expect($primary->network())
            ->toBe('oe-topo-snap')
            ->and($primary->instancePrefix())
            ->toBe('orbit-e2e-topology-snapshot-')
            ->and($primary->instance('gateway'))
            ->toBe('orbit-e2e-topology-snapshot-gateway')
            ->and($primary->slot)
            ->toBe(1)
            ->and($primary->isPrimary())
            ->toBeTrue();
    });

    it('gives the live validation clone its own namespaced resources', function (): void {
        $live = TopologySnapshotIdentity::live();

        expect($live->network())
            ->toBe('oe-l-topo-snap')
            ->and($live->instancePrefix())
            ->toBe('orbit-e2e-live-topology-snapshot-')
            ->and($live->instance('gateway'))
            ->toBe('orbit-e2e-live-topology-snapshot-gateway')
            ->and($live->slot)
            ->toBe(200)
            ->and($live->isPrimary())
            ->toBeFalse();
    });

    it('lists every topology role in profile order', function (): void {
        expect(TopologySnapshotIdentity::primary()->instances())
            ->toBe([
                'orbit-e2e-topology-snapshot-gateway',
                'orbit-e2e-topology-snapshot-app-dev',
                'orbit-e2e-topology-snapshot-app-prod',
            ])
            ->and(TopologySnapshotIdentity::live()->instances())
            ->toBe([
                'orbit-e2e-live-topology-snapshot-gateway',
                'orbit-e2e-live-topology-snapshot-app-dev',
                'orbit-e2e-live-topology-snapshot-app-prod',
            ])
            ->and(TopologyProfile::ROLES)
            ->toBe(['gateway', 'app-dev', 'app-prod']);
    });

    it('keeps the retired physical identities available only for explicit migration', function (): void {
        expect(TopologySnapshotIdentity::retiredForNamespace(null)->network())
            ->toBe('oe-standby')
            ->and(TopologySnapshotIdentity::retiredForNamespace(null)->instance('gateway'))
            ->toBe('orbit-e2e-standby-gateway')
            ->and(TopologySnapshotIdentity::retiredForNamespace('live')->network())
            ->toBe('oe-live-standby')
            ->and(TopologySnapshotIdentity::retiredForNamespace('live')->instance('gateway'))
            ->toBe('orbit-e2e-live-standby-gateway')
            ->and(TopologySnapshotIdentity::known())
            ->not->toContain(
                TopologySnapshotIdentity::retiredForNamespace(null),
                TopologySnapshotIdentity::retiredForNamespace('live'),
            );
    });

    it('resolves the primary namespace from empty or null input', function (?string $namespace): void {
        expect(TopologySnapshotIdentity::forNamespace($namespace))
            ->toEqual(TopologySnapshotIdentity::primary());
    })->with([
        'empty string' => [''],
        'null' => [null],
    ]);

    it('resolves the live namespace by name', function (): void {
        expect(TopologySnapshotIdentity::forNamespace('live'))->toEqual(TopologySnapshotIdentity::live());
    });

    it('rejects an unknown namespace and lists the allowed ones', function (): void {
        expect(fn () => TopologySnapshotIdentity::forNamespace('nope'))
            ->toThrow(InvalidArgumentException::class, '(empty), live');
    });

    it('lists both known identities with distinct slots and networks', function (): void {
        $known = TopologySnapshotIdentity::known();

        expect($known)
            ->toEqual([TopologySnapshotIdentity::primary(), TopologySnapshotIdentity::live()])
            ->and(array_unique(array_map(
                static fn (TopologySnapshotIdentity $identity): int => $identity->slot,
                $known,
            )))
            ->toHaveCount(count($known))
            ->and(array_unique(array_map(
                static fn (TopologySnapshotIdentity $identity): string => $identity->network(),
                $known,
            )))
            ->toHaveCount(count($known));
    });

    it('rejects an unknown topology role', function (): void {
        expect(fn () => TopologySnapshotIdentity::primary()->instance('unknown'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('keeps every topology snapshot network inside the managed Incus bridge interface limit', function (): void {
        foreach (TopologySnapshotIdentity::known() as $identity) {
            $network = $identity->network();

            expect($network)
                ->toMatch('/\Aoe-[a-z0-9](?:[a-z0-9-]{0,10}[a-z0-9])?\z/D')
                ->and(strlen($network))
                ->toBeLessThanOrEqual(15);
        }
    });
});
