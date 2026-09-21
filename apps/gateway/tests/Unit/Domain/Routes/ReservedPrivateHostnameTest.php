<?php

declare(strict_types=1);

use App\Domain\ProxyCli\ProxyCliHostname;
use App\Domain\Routes\ReservedPrivateHostname;
use App\Domain\Shared\ResourceOperationException;

describe(ReservedPrivateHostname::class, function (): void {
    it('reserves the platform private hostnames', function (): void {
        expect(ReservedPrivateHostname::NAMES)->toBe(['gateway.orbit', 'metrics.orbit', 'reverb.orbit', 'analytics.orbit', ProxyCliHostname::Value]);
    });

    it('reserves the collector hostname and leaves the apex free', function (): void {
        expect(ProxyCliHostname::Value)
            ->toBe('collector.proxycli.orbit')
            ->and(ProxyCliHostname::Apex)
            ->toBe('proxycli.orbit')
            ->and(ReservedPrivateHostname::NAMES)
            ->toContain(ProxyCliHostname::Value)
            ->not
            ->toContain(ProxyCliHostname::Apex);

        ReservedPrivateHostname::assertAvailable(ProxyCliHostname::Apex);
    });

    it('refuses reserved private hostnames', function (string $domain): void {
        expect(fn () => ReservedPrivateHostname::assertAvailable($domain))
            ->toThrow(function (ResourceOperationException $exception) use ($domain): void {
                expect($exception->errorCode)
                    ->toBe('route.domain_conflict')
                    ->and($exception->getMessage())
                    ->toBe("Route domain [{$domain}] is reserved.");
            });
    })->with([
        'gateway' => ['gateway.orbit'],
        'metrics' => ['metrics.orbit'],
        'reverb' => ['reverb.orbit'],
        'analytics' => ['analytics.orbit'],
        'proxycli collector' => [ProxyCliHostname::Value],
    ]);

    it('allows other private hostnames', function (string $domain): void {
        ReservedPrivateHostname::assertAvailable($domain);

        expect($domain)->not->toBeEmpty();
    })->with([
        'executor.orbit',
        'grafana.internal',
        'foo.bar',
        'something.test',
        'proxycli apex' => [ProxyCliHostname::Apex],
    ]);
});
