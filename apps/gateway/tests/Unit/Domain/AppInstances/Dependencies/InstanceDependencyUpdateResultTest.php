<?php

declare(strict_types=1);

use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Dependencies\DependencyGraph;
use App\Domain\AppInstances\Dependencies\DependencyInventoryState;
use App\Domain\AppInstances\Dependencies\DependencyScanResult;
use App\Domain\AppInstances\Dependencies\DependencySnapshot;
use App\Domain\AppInstances\Dependencies\DependencySource;
use App\Domain\AppInstances\Dependencies\DependencyUpdateStepResult;
use App\Domain\AppInstances\Dependencies\DependencyUpdateStepStatus;
use App\Domain\AppInstances\Dependencies\InstanceDependencyScanResult;
use App\Domain\AppInstances\Dependencies\InstanceDependencyUpdateResult;

function successful_dependency_inventory(int $instanceId = 42): InstanceDependencyScanResult
{
    $time = new DateTimeImmutable('2026-09-15T12:00:00Z');

    return new InstanceDependencyScanResult(
        $instanceId,
        DependencyScanResult::refreshed(new DependencySnapshot(DependencyEcosystem::Composer, new DependencySource('/srv/app', null, [], 'composer'), $time, new DependencyGraph(DependencyEcosystem::Composer, [], []))),
        DependencyScanResult::refreshed(new DependencySnapshot(DependencyEcosystem::Npm, new DependencySource('/srv/app', null, [], 'npm:3'), $time, new DependencyGraph(DependencyEcosystem::Npm, [], []))),
    );
}

describe('dependency update results', function (): void {
    it('requires completed package steps and successful inventory refresh for success', function (): void {
        $result = new InstanceDependencyUpdateResult(42, DependencyUpdateStepResult::succeeded(DependencyEcosystem::Composer), DependencyUpdateStepResult::succeeded(DependencyEcosystem::Npm), successful_dependency_inventory());

        expect($result->succeeded())->toBeTrue();
        expect($result->mayHaveMutated())->toBeTrue();
    });

    it('allows an absent ecosystem to complete without claiming mutation', function (): void {
        $inventory = successful_dependency_inventory();
        $absent = DependencyScanResult::refreshed(new DependencySnapshot(DependencyEcosystem::Npm, new DependencySource('/srv/app', null, ['package.json' => null, 'package-lock.json' => null], null), new DateTimeImmutable, null));
        $javascript = DependencyUpdateStepResult::absent(DependencyEcosystem::Npm);

        $result = new InstanceDependencyUpdateResult(42, DependencyUpdateStepResult::succeeded(DependencyEcosystem::Composer), $javascript, new InstanceDependencyScanResult(42, $inventory->composer, $absent));

        expect($result->succeeded())->toBeTrue();
        expect($javascript->mayHaveMutated)->toBeFalse();
        expect($javascript->status)->toBe(DependencyUpdateStepStatus::Absent);
    });

    it('preserves completed work and possible partial mutation when JavaScript fails', function (): void {
        $result = new InstanceDependencyUpdateResult(42, DependencyUpdateStepResult::succeeded(DependencyEcosystem::Composer), DependencyUpdateStepResult::failed(DependencyEcosystem::Npm, 'dependencies.update_failed', true), successful_dependency_inventory());

        expect($result->succeeded())->toBeFalse();
        expect($result->mayHaveMutated())->toBeTrue();
        expect($result->composer->status)->toBe(DependencyUpdateStepStatus::Succeeded);
        expect($result->javascript->status)->toBe(DependencyUpdateStepStatus::Failed);
        expect($result->javascript->mayHaveMutated)->toBeTrue();
        expect($result->inventory->succeeded())->toBeTrue();
    });

    it('reports a failed Composer step separately from JavaScript not run', function (bool $mayHaveMutated): void {
        $result = new InstanceDependencyUpdateResult(42, DependencyUpdateStepResult::failed(DependencyEcosystem::Composer, 'dependencies.update_failed', $mayHaveMutated), DependencyUpdateStepResult::notRun(DependencyEcosystem::Npm), successful_dependency_inventory());

        expect($result->succeeded())->toBeFalse();
        expect($result->mayHaveMutated())->toBe($mayHaveMutated);
        expect($result->javascript->status)->toBe(DependencyUpdateStepStatus::NotRun);
        expect($result->javascript->mayHaveMutated)->toBeFalse();
    })->with(['failed before start' => false, 'failed after start' => true]);

    it('does not report success when the post-update scan fails', function (): void {
        $previous = successful_dependency_inventory();
        $inventory = new InstanceDependencyScanResult(42, $previous->composer, DependencyScanResult::failed(DependencyEcosystem::Npm, new DateTimeImmutable('2026-09-15T13:00:00Z'), 'dependencies.invalid_lockfile', $previous->javascript->snapshot));

        $result = new InstanceDependencyUpdateResult(42, DependencyUpdateStepResult::succeeded(DependencyEcosystem::Composer), DependencyUpdateStepResult::succeeded(DependencyEcosystem::Npm), $inventory);

        expect($result->succeeded())->toBeFalse();
        expect($result->composer->completed())->toBeTrue();
        expect($result->javascript->completed())->toBeTrue();
        expect($result->inventory->javascript->state())->toBe(DependencyInventoryState::Stale);
    });

    it('reports missing post-update inventory as incomplete', function (): void {
        $result = new InstanceDependencyUpdateResult(42, DependencyUpdateStepResult::succeeded(DependencyEcosystem::Composer), DependencyUpdateStepResult::succeeded(DependencyEcosystem::Npm), null);

        expect($result->succeeded())->toBeFalse();
        expect($result->mayHaveMutated())->toBeTrue();
    });

    it('represents production preflight refusal without package mutation', function (): void {
        $result = new InstanceDependencyUpdateResult(42, DependencyUpdateStepResult::notRun(DependencyEcosystem::Composer), DependencyUpdateStepResult::notRun(DependencyEcosystem::Npm), null, 'dependencies.production_update_forbidden');

        expect($result->succeeded())->toBeFalse();
        expect($result->mayHaveMutated())->toBeFalse();
        expect($result->inventory)->toBeNull();
        expect($result->errorCode)->toBe('dependencies.production_update_forbidden');
    });

    it('preserves an operation failure even if package work and inventory succeeded', function (): void {
        $result = new InstanceDependencyUpdateResult(42, DependencyUpdateStepResult::succeeded(DependencyEcosystem::Composer), DependencyUpdateStepResult::succeeded(DependencyEcosystem::Npm), successful_dependency_inventory(), 'dependencies.constraints_changed');

        expect($result->succeeded())->toBeFalse();
    });

    it('rejects post-update inventory belonging to another instance', function (): void {
        expect(fn () => new InstanceDependencyUpdateResult(42, DependencyUpdateStepResult::succeeded(DependencyEcosystem::Composer), DependencyUpdateStepResult::succeeded(DependencyEcosystem::Npm), successful_dependency_inventory(43)))
            ->toThrow(InvalidArgumentException::class, 'Post-update inventory must belong to the updated instance');
    });

    it('rejects update outcomes in the wrong ecosystem slots', function (): void {
        $step = DependencyUpdateStepResult::notRun(DependencyEcosystem::Composer);

        expect(fn () => new InstanceDependencyUpdateResult(42, $step, $step, null))
            ->toThrow(InvalidArgumentException::class, 'Instance updates require Composer and JavaScript outcomes');
    });

    it('requires an error code on a failed package step', function (): void {
        expect(fn () => DependencyUpdateStepResult::failed(DependencyEcosystem::Composer, '', true))
            ->toThrow(InvalidArgumentException::class, 'A failed update step requires an error code');
    });
});
