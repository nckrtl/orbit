<?php

declare(strict_types=1);

use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Dependencies\DependencyGraph;
use App\Domain\AppInstances\Dependencies\DependencyInventoryState;
use App\Domain\AppInstances\Dependencies\DependencyScanResult;
use App\Domain\AppInstances\Dependencies\DependencySnapshot;
use App\Domain\AppInstances\Dependencies\DependencySource;
use App\Domain\AppInstances\Dependencies\InstanceDependencyScanResult;

describe('dependency scan results', function (): void {
    it('distinguishes verified absence from a present project with no packages', function (bool $present, DependencyInventoryState $state): void {
        $time = new DateTimeImmutable('2026-09-15T12:00:00Z');
        $source = new DependencySource('/srv/app/releases/r1', 'r1', [
            'composer.json' => $present ? hash('sha256', '{}') : null,
            'composer.lock' => $present ? hash('sha256', '{"packages":[]}') : null,
        ], $present ? 'composer' : null);
        $snapshot = new DependencySnapshot(DependencyEcosystem::Composer, $source, $time, $present ? new DependencyGraph(DependencyEcosystem::Composer, [], []) : null);

        $result = DependencyScanResult::refreshed($snapshot);

        expect($result->state())->toBe($state);
        expect($result->succeeded())->toBeTrue();
        expect($result->snapshot)->toBe($snapshot);
        expect($result->snapshot->source->projectRoot)->toBe('/srv/app/releases/r1');
        expect($result->snapshot->source->reference)->toBe('r1');
        expect($result->snapshot->source->fileHashes)->toBe($source->fileHashes);
        expect($result->attemptedAt)->toBe($time);
    })->with(['absent' => [false, DependencyInventoryState::Absent], 'empty project' => [true, DependencyInventoryState::Present]]);

    it('retains the last observation and its source time after a failed scan', function (bool $present): void {
        $observedAt = new DateTimeImmutable('2026-09-14T12:00:00Z');
        $attemptedAt = new DateTimeImmutable('2026-09-15T12:00:00Z');
        $snapshot = new DependencySnapshot(DependencyEcosystem::Npm, new DependencySource('/srv/app', 'commit-a', [], $present ? 'npm:3' : null), $observedAt, $present ? new DependencyGraph(DependencyEcosystem::Npm, [], []) : null);

        $result = DependencyScanResult::failed(DependencyEcosystem::Npm, $attemptedAt, 'dependencies.source_changed', $snapshot);

        expect($result->state())->toBe(DependencyInventoryState::Stale);
        expect($result->succeeded())->toBeFalse();
        expect($result->snapshot)->toBe($snapshot);
        expect($result->snapshot->observedAt)->toBe($observedAt);
        expect($result->attemptedAt)->toBe($attemptedAt);
        expect($result->errorCode)->toBe('dependencies.source_changed');
    })->with(['previously absent' => false, 'previously present' => true]);

    it('reports unknown on first scan failure and partial failure for the instance', function (): void {
        $time = new DateTimeImmutable('2026-09-15T12:00:00Z');
        $composer = DependencyScanResult::refreshed(new DependencySnapshot(DependencyEcosystem::Composer, new DependencySource('/srv/app', null, [], null), $time, null));
        $javascript = DependencyScanResult::failed(DependencyEcosystem::Npm, $time, 'dependencies.unsupported_format');

        $result = new InstanceDependencyScanResult(42, $composer, $javascript);

        expect($result->succeeded())->toBeFalse();
        expect($result->composer->state())->toBe(DependencyInventoryState::Absent);
        expect($result->javascript->state())->toBe(DependencyInventoryState::Unknown);
        expect($result->javascript->snapshot)->toBeNull();
    });

    it('rejects a failed scan with no error code', function (): void {
        expect(fn () => DependencyScanResult::failed(DependencyEcosystem::Composer, new DateTimeImmutable, ''))
            ->toThrow(InvalidArgumentException::class, 'A failed scan requires an error code');
    });

    it('rejects retaining another ecosystem snapshot', function (): void {
        $time = new DateTimeImmutable;
        $snapshot = new DependencySnapshot(DependencyEcosystem::Composer, new DependencySource('/srv/app', null, [], null), $time, null);

        expect(fn () => DependencyScanResult::failed(DependencyEcosystem::Npm, $time, 'dependencies.unreadable_source', $snapshot))
            ->toThrow(InvalidArgumentException::class, 'A retained snapshot must belong to the scanned ecosystem');
    });

    it('rejects a snapshot of a different ecosystem graph', function (): void {
        expect(fn () => new DependencySnapshot(DependencyEcosystem::Composer, new DependencySource('/srv/app', null, [], 'npm:3'), new DateTimeImmutable, new DependencyGraph(DependencyEcosystem::Npm, [], [])))
            ->toThrow(InvalidArgumentException::class, 'The snapshot and graph ecosystems must match');
    });

    it('rejects scan outcomes in the wrong ecosystem slots', function (): void {
        $failure = DependencyScanResult::failed(DependencyEcosystem::Npm, new DateTimeImmutable, 'dependencies.unreadable_source');

        expect(fn () => new InstanceDependencyScanResult(42, $failure, $failure))
            ->toThrow(InvalidArgumentException::class, 'Instance scans require Composer and JavaScript outcomes');
    });
});
