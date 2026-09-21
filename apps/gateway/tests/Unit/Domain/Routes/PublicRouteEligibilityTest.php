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

it('treats a terminal public-edge step as finished and earlier steps as in flight', function (): void {
    $eligibility = new PublicRouteEligibility;

    expect($eligibility->isInFlightReplacementStep(null))->toBeFalse()
        ->and($eligibility->isInFlightReplacementStep(RouteReplacementStep::PublicActivated))->toBeFalse()
        ->and($eligibility->isInFlightReplacementStep(RouteReplacementStep::IngressFirewall))->toBeFalse()
        ->and($eligibility->isInFlightReplacementStep(RouteReplacementStep::Cleanup))->toBeFalse()
        ->and($eligibility->isInFlightReplacementStep(RouteReplacementStep::PublicEdgeVerified))->toBeTrue()
        ->and($eligibility->isInFlightReplacementStep(RouteReplacementStep::IngressCaddy))->toBeTrue()
        ->and($eligibility->isInFlightReplacementStep(RouteReplacementStep::Reserved))->toBeTrue();
});
