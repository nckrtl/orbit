<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\AdoptGatewayReleaseAction;
use App\Actions\GatewayReleases\ConfigureGatewayReleaseAction;
use App\Actions\GatewayReleases\DeployGatewayReleaseAction;
use App\Actions\GatewayReleases\PrepareGatewayReleaseAction;
use App\Actions\GatewayReleases\RollbackGatewayReleaseAction;
use App\Actions\GatewayReleases\RunAutomaticGatewayReleaseAction;
use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseRuntime;
use App\Domain\GatewayReleases\GatewayReleaseVerifier;
use App\Infrastructure\GatewayReleases\ArtisanGatewayReleaseRuntime;
use App\Infrastructure\GatewayReleases\GatewayReleaseAdopter;
use App\Infrastructure\GatewayReleases\GatewayReleaseBuilder;
use App\Infrastructure\GatewayReleases\GatewayReleaseGuard;
use App\Infrastructure\GatewayReleases\GatewayReleasePromoter;
use App\Infrastructure\GatewayReleases\GatewayReleaseTickConfirmation;
use App\Infrastructure\GatewayReleases\GatewayRuntimeHandoff;
use App\Infrastructure\GatewayReleases\GatewaySchedulerHandoff;
use App\Infrastructure\GatewayReleases\HttpGatewayReleaseVerifier;
use App\Infrastructure\GatewayReleases\LocalGatewayReleaseRuntime;
use App\Infrastructure\GatewayReleases\SqliteGatewayReleaseDatabase;

/** A private property of a resolved object, so the test sees what the container wired. */
function wired(object $object, string $property): mixed
{
    return new ReflectionProperty($object, $property)->getValue($object);
}

beforeEach(function (): void {
    config([
        'orbit.home' => '/home/orbit/.orbit',
        'orbit.gateway_checkout' => '/home/orbit/orbit/apps/gateway',
        'orbit.gateway_releases.scheduler_drain_seconds' => 450,
        'orbit.gateway_releases.min_free_mb' => 2048,
        'orbit.gateway_releases.keep' => 4,
        'orbit.gateway_releases.snapshots_keep' => 3,
        'orbit.gateway_releases.tick_confirmation_seconds' => 240,
    ]);
});

describe('the Gateway release pipeline from the container', function (): void {
    it('resolves every release command entry point', function (string $action): void {
        expect(app($action))->toBeInstanceOf($action);
    })->with([
        PrepareGatewayReleaseAction::class,
        DeployGatewayReleaseAction::class,
        RollbackGatewayReleaseAction::class,
        AdoptGatewayReleaseAction::class,
        ConfigureGatewayReleaseAction::class,
        GatewayRuntimeHandoff::class,
    ]);

    it('wires deploy with the real promoter, guard, database, and runtime', function (): void {
        $deploy = app(DeployGatewayReleaseAction::class);
        $promoter = wired($deploy, 'promoter');
        $runtime = wired($promoter, 'runtime');
        $database = wired($deploy, 'database');
        $builder = wired($deploy, 'builder');

        expect(wired($deploy, 'guard'))->toBeInstanceOf(GatewayReleaseGuard::class)
            ->and($promoter)->toBeInstanceOf(GatewayReleasePromoter::class)
            ->and(wired($promoter, 'guard'))->toBeInstanceOf(GatewayReleaseGuard::class)
            ->and(wired($promoter, 'keptReleases'))->toBe(4)
            ->and(wired($promoter, 'verifier'))->toBeInstanceOf(HttpGatewayReleaseVerifier::class)
            ->and(wired($promoter, 'ticks'))->toBeInstanceOf(GatewayReleaseTickConfirmation::class)
            ->and(wired(app(RunAutomaticGatewayReleaseAction::class), 'ticks'))->toBeInstanceOf(GatewayReleaseTickConfirmation::class)
            ->and(wired(wired($promoter, 'ticks'), 'windowSeconds'))->toBe(240)
            ->and($runtime)->toBeInstanceOf(ArtisanGatewayReleaseRuntime::class)
            ->and(wired($runtime, 'fallback'))->toBeInstanceOf(LocalGatewayReleaseRuntime::class)
            ->and(wired($runtime, 'stepLock'))->toBe('/home/orbit/.orbit/gateway-release-step.lock')
            ->and(wired($runtime, 'timeout'))->toBe(450.0 + 330 + 600)
            ->and($database)->toBeInstanceOf(SqliteGatewayReleaseDatabase::class)
            ->and(wired($database, 'stepLock'))->toBe('/home/orbit/.orbit/gateway-release-step.lock')
            ->and(wired($database, 'keptSnapshots'))->toBe(3)
            ->and(wired($database, 'minimumFreeBytes'))->toBe(2048 * 1_048_576)
            ->and($builder)->toBeInstanceOf(GatewayReleaseBuilder::class)
            ->and(wired($builder, 'stepLock'))->toBe('/home/orbit/.orbit/gateway-release-step.lock')
            ->and(wired($builder, 'minimumFreeBytes'))->toBe(2048 * 1_048_576);
    });

    it('wires rollback with the guard and adopt with the in-process handoff and the deploy path', function (): void {
        $adopter = wired(app(AdoptGatewayReleaseAction::class), 'adopter');

        expect(wired(app(RollbackGatewayReleaseAction::class), 'guard'))->toBeInstanceOf(GatewayReleaseGuard::class)
            ->and($adopter)->toBeInstanceOf(GatewayReleaseAdopter::class)
            ->and(wired($adopter, 'runtime'))->toBeInstanceOf(LocalGatewayReleaseRuntime::class)
            ->and(wired($adopter, 'deploy'))->toBeInstanceOf(DeployGatewayReleaseAction::class)
            ->and(wired($adopter, 'guard'))->toBeInstanceOf(GatewayReleaseGuard::class)
            ->and(wired($adopter, 'verifier'))->toBeInstanceOf(HttpGatewayReleaseVerifier::class);
    });

    it('wires the handoff with the configured scheduler drain', function (): void {
        $scheduler = wired(app(GatewayRuntimeHandoff::class), 'scheduler');

        expect($scheduler)->toBeInstanceOf(GatewaySchedulerHandoff::class)
            ->and(wired($scheduler, 'drainSeconds'))->toBe(450)
            ->and(app(GatewayReleaseRuntime::class))->toBeInstanceOf(ArtisanGatewayReleaseRuntime::class)
            ->and(app(GatewayReleaseDatabase::class))->toBeInstanceOf(SqliteGatewayReleaseDatabase::class)
            ->and(app(GatewayReleaseVerifier::class))->toBeInstanceOf(HttpGatewayReleaseVerifier::class);
    });
});
