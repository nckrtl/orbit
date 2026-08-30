<?php

declare(strict_types=1);

use App\E2E\Value\StandbyIdentity;
use App\E2E\Value\TopologyProfile;

describe('StandbyIdentity', function (): void {
    it('gives the repository primary checkout its own unnamespaced resources', function (): void {
        $primary = StandbyIdentity::primary();

        expect($primary->network())
            ->toBe('oe-standby')
            ->and($primary->instancePrefix())
            ->toBe('orbit-e2e-standby-')
            ->and($primary->instance('gateway'))
            ->toBe('orbit-e2e-standby-gateway')
            ->and($primary->slot)
            ->toBe(1)
            ->and($primary->isPrimary())
            ->toBeTrue();
    });

    it('gives the live validation clone its own namespaced resources', function (): void {
        $live = StandbyIdentity::live();

        expect($live->network())
            ->toBe('oe-live-standby')
            ->and($live->instancePrefix())
            ->toBe('orbit-e2e-live-standby-')
            ->and($live->instance('gateway'))
            ->toBe('orbit-e2e-live-standby-gateway')
            ->and($live->slot)
            ->toBe(200)
            ->and($live->isPrimary())
            ->toBeFalse();
    });

    it('lists every topology role in profile order', function (): void {
        expect(StandbyIdentity::primary()->instances())
            ->toBe([
                'orbit-e2e-standby-gateway',
                'orbit-e2e-standby-app-dev',
                'orbit-e2e-standby-app-prod',
            ])
            ->and(StandbyIdentity::live()->instances())
            ->toBe([
                'orbit-e2e-live-standby-gateway',
                'orbit-e2e-live-standby-app-dev',
                'orbit-e2e-live-standby-app-prod',
            ])
            ->and(TopologyProfile::ROLES)
            ->toBe(['gateway', 'app-dev', 'app-prod']);
    });

    it('resolves the primary namespace from empty or null input', function (?string $namespace): void {
        expect(StandbyIdentity::forNamespace($namespace))
            ->toEqual(StandbyIdentity::primary());
    })->with([
        'empty string' => [''],
        'null' => [null],
    ]);

    it('resolves the live namespace by name', function (): void {
        expect(StandbyIdentity::forNamespace('live'))->toEqual(StandbyIdentity::live());
    });

    it('rejects an unknown namespace and lists the allowed ones', function (): void {
        expect(fn () => StandbyIdentity::forNamespace('nope'))
            ->toThrow(InvalidArgumentException::class, '(empty), live');
    });

    it('lists both known identities with distinct slots and networks', function (): void {
        $known = StandbyIdentity::known();

        expect($known)
            ->toEqual([StandbyIdentity::primary(), StandbyIdentity::live()])
            ->and(array_unique(array_map(static fn (StandbyIdentity $identity): int => $identity->slot, $known)))
            ->toHaveCount(count($known))
            ->and(array_unique(array_map(
                static fn (StandbyIdentity $identity): string => $identity->network(),
                $known,
            )))
            ->toHaveCount(count($known));
    });

    it('rejects an unknown topology role', function (): void {
        expect(fn () => StandbyIdentity::primary()->instance('unknown'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('keeps every standby network inside the managed Incus bridge interface limit', function (): void {
        foreach (StandbyIdentity::known() as $identity) {
            $network = $identity->network();

            expect($network)
                ->toMatch('/\Aoe-[a-z0-9](?:[a-z0-9-]{0,10}[a-z0-9])?\z/D')
                ->and(strlen($network))
                ->toBeLessThanOrEqual(15);
        }
    });
});
