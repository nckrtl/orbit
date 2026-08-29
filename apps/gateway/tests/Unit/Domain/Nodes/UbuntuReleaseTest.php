<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\UbuntuRelease;

it('defines the supported Ubuntu release policy in deterministic order', function (): void {
    expect(UbuntuRelease::baseReleases())
        ->toBe([UbuntuRelease::Resolute])
        ->and(UbuntuRelease::supportedCodenames())
        ->toBe(['resolute'])
        ->and(UbuntuRelease::unsupportedText('ubuntu', 'unsupported'))
        ->toBe('Node operating system [ubuntu/unsupported] is not supported.')
        ->and(UbuntuRelease::unsupportedText('ubuntu', 'unsafe value'))
        ->toBe('Node operating system [unknown/unknown] is not supported.')
        ->and(UbuntuRelease::unsupportedTextFromOutput(
            "Node operating system [debian/resolute] is not supported.\n",
        ))
        ->toBe('Node operating system [debian/resolute] is not supported.')
        ->and(UbuntuRelease::unsupportedTextFromOutput('untrusted remote output'))
        ->toBe('Node operating system [unknown/unknown] is not supported.');
});

it('restricts production infrastructure roles to Resolute', function (): void {
    expect(UbuntuRelease::forRole(RoleName::Gateway))
        ->toBe([UbuntuRelease::Resolute])
        ->and(UbuntuRelease::forRole(RoleName::Vpn))
        ->toBe([UbuntuRelease::Resolute])
        ->and(UbuntuRelease::forRole(RoleName::AppProd))
        ->toBe([UbuntuRelease::Resolute])
        ->and(UbuntuRelease::forRole(RoleName::AppDev))
        ->toBe([UbuntuRelease::Resolute]);
});
