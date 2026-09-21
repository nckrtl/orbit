<?php

declare(strict_types=1);

use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Routes\RouteReplacementStep;

it('treats a null replacement step as public activation not reached', function (): void {
    $eligibility = new PublicRouteEligibility;

    expect($eligibility->publicActivationReached(null))->toBeFalse()
        ->and($eligibility->publicActivationReached(RouteReplacementStep::PublicEdgeVerified))->toBeFalse()
        ->and($eligibility->publicActivationReached(RouteReplacementStep::PublicActivated))->toBeTrue()
        ->and($eligibility->publicActivationReached(RouteReplacementStep::IngressFirewall))->toBeTrue()
        ->and($eligibility->publicActivationReached(RouteReplacementStep::Cleanup))->toBeTrue();
});
