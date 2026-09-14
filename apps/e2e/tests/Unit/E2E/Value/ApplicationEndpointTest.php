<?php

declare(strict_types=1);

use App\E2E\Value\ApplicationEndpoint;

describe('ApplicationEndpoint', function (): void {
    it('prefers a valid domain and uses hostname only when domain is absent', function (): void {
        expect(ApplicationEndpoint::fromRecord(['domain' => 'e2e-prod.orbit.test']))
            ->toBe('e2e-prod.orbit.test')
            ->and(ApplicationEndpoint::fromRecord([
                'domain' => 'e2e-prod.orbit.test',
                'hostname' => 'ignored.orbit',
            ]))
            ->toBe('e2e-prod.orbit.test')
            ->and(ApplicationEndpoint::fromRecord(['hostname' => 'e2e-dev.orbit']))
            ->toBe('e2e-dev.orbit')
            ->and(ApplicationEndpoint::fromRecord([
                'route' => ['domain' => 'from-route.orbit'],
            ]))
            ->toBe('from-route.orbit')
            ->and(ApplicationEndpoint::fromRecord([
                'route' => ['hostname' => 'from-route-hostname.orbit'],
            ]))
            ->toBe('from-route-hostname.orbit');
    });

    it('refuses a present invalid domain without falling back', function (array $record): void {
        expect(fn () => ApplicationEndpoint::fromRecord($record))
            ->toThrow(InvalidArgumentException::class, 'The application endpoint is invalid.');
    })->with([
        'empty domain' => [['domain' => '', 'hostname' => 'e2e-prod.orbit.test']],
        'malformed domain' => [['domain' => 'Not_A_Domain', 'hostname' => 'e2e-prod.orbit.test']],
        'null domain' => [['domain' => null, 'hostname' => 'e2e-prod.orbit.test']],
        'nested invalid domain' => [['route' => ['domain' => '', 'hostname' => 'e2e-prod.orbit.test']]],
    ]);

    it('matches placement records that carry domain, hostname, or both', function (): void {
        $base = [
            'layout',
            'instance_id',
            'user',
            'home',
            'checkout_path',
            'effective_root',
            'environment_path',
            'database_path',
            'service',
            'socket',
            'current_target',
        ];

        expect(ApplicationEndpoint::placementKeysMatch($base, array_fill_keys([...$base, 'hostname'], 'x')))
            ->toBeTrue()
            ->and(ApplicationEndpoint::placementKeysMatch($base, array_fill_keys([...$base, 'domain'], 'x')))
            ->toBeTrue()
            ->and(ApplicationEndpoint::placementKeysMatch($base, array_fill_keys([...$base, 'domain', 'hostname'], 'x')))
            ->toBeTrue()
            ->and(ApplicationEndpoint::placementKeysMatch($base, array_fill_keys($base, 'x')))
            ->toBeFalse();
    });
});
