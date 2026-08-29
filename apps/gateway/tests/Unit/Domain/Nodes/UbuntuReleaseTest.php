<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\UbuntuRelease;

it('defines the supported Ubuntu release policy in deterministic order', function (): void {
    expect(UbuntuRelease::baseReleases())
        ->toBe([UbuntuRelease::Noble, UbuntuRelease::Resolute])
        ->and(UbuntuRelease::supportedCodenames())
        ->toBe(['noble', 'resolute'])
        ->and(UbuntuRelease::requirementText())
        ->toBe('Orbit requires Ubuntu 24.04 Noble or Ubuntu 26.04 Resolute.')
        ->and(UbuntuRelease::requirementTextFor([UbuntuRelease::Resolute]))
        ->toBe('Orbit requires Ubuntu 26.04 Resolute.')
        ->and(UbuntuRelease::Noble->label())
        ->toBe('Ubuntu 24.04 Noble')
        ->and(UbuntuRelease::Resolute->label())
        ->toBe('Ubuntu 26.04 Resolute');
});

it('restricts production infrastructure roles to Resolute', function (): void {
    expect(UbuntuRelease::forRole(RoleName::Gateway))
        ->toBe([UbuntuRelease::Resolute])
        ->and(UbuntuRelease::forRole(RoleName::Vpn))
        ->toBe([UbuntuRelease::Resolute])
        ->and(UbuntuRelease::forRole(RoleName::AppProd))
        ->toBe([UbuntuRelease::Resolute])
        ->and(UbuntuRelease::forRole(RoleName::AppDev))
        ->toBe([UbuntuRelease::Noble, UbuntuRelease::Resolute]);
});
