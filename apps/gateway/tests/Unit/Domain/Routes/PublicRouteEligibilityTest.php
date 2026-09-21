<?php

declare(strict_types=1);

use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Routes\RouteReplacementStep;
use App\Models\Route;

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

it('treats a terminal public-edge step as idle only when the Route has no failure evidence', function (): void {
    $eligibility = new PublicRouteEligibility;
    $idle = new Route(['replacement_step' => RouteReplacementStep::IngressFirewall]);
    $failed = new Route([
        'replacement_step' => RouteReplacementStep::IngressFirewall,
        'failed_step' => 'ingress-firewall',
        'error_code' => 'route.public_edge_failed',
    ]);

    expect($eligibility->isIdleForEnvironmentOwner($idle))->toBeTrue()
        ->and($eligibility->isIdleForEnvironmentOwner($failed))->toBeFalse()
        ->and($eligibility->isIdleForEnvironmentOwner(new Route(['replacement_step' => RouteReplacementStep::IngressCaddy])))
        ->toBeFalse();
});
