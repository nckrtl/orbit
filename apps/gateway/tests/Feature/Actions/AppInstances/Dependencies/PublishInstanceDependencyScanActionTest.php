<?php

declare(strict_types=1);

use App\Actions\AppInstances\Dependencies\PublishInstanceDependencyScanAction;
use App\Actions\AppInstances\Dependencies\ReadInstanceDependencyScanAction;
use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Dependencies\DependencyGraph;
use App\Domain\AppInstances\Dependencies\DependencyIdentity;
use App\Domain\AppInstances\Dependencies\DependencyInventoryState;
use App\Domain\AppInstances\Dependencies\DependencyRequirement;
use App\Domain\AppInstances\Dependencies\DependencyRequirementKind;
use App\Domain\AppInstances\Dependencies\DependencyResolution;
use App\Domain\AppInstances\Dependencies\DependencyScanResult;
use App\Domain\AppInstances\Dependencies\DependencyScope;
use App\Domain\AppInstances\Dependencies\DependencySnapshot;
use App\Domain\AppInstances\Dependencies\DependencySource;
use App\Domain\AppInstances\Dependencies\InstanceDependencyScanResult;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceDependencyObservation;
use App\Models\AppInstanceDependencyScanAttempt;
use App\Models\AppInstanceRemoval;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function dependency_publication_instance(string $name = 'web'): AppInstance
{
    $app = OrbitApp::query()->firstOrCreate(['slug' => 'dependency-publication'], [
        'name' => 'Dependency publication',
        'repository_url' => 'https://example.test/dependency-publication.git',
    ]);
    $node = Node::query()->firstOrCreate(['name' => 'dependency-publication'], [
        'public_ssh_host' => '192.0.2.180',
        'status' => 'active',
    ]);

    return $app->appInstances()->create([
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => '/srv/dependency-publication/'.$name,
        'status' => 'active',
    ]);
}

function dependency_publication_snapshot(
    DependencyEcosystem $ecosystem = DependencyEcosystem::Npm,
    string $time = '2026-09-15 12:00:00 UTC',
    string $version = '1.0.0',
): DependencySnapshot {
    $identity = new DependencyIdentity($ecosystem, 'widget');

    return new DependencySnapshot(
        $ecosystem,
        new DependencySource('/srv/dependency-publication/web', 'release/reviewed', ['package.json' => str_repeat('a', 64)], 'fixture:1'),
        new DateTimeImmutable($time),
        new DependencyGraph($ecosystem, [
            new DependencyResolution('widget(peer@2)', $identity, $version, true, true, 'feature/branch', 'sha512-fixture'),
            new DependencyResolution('widget(peer@3)', $identity, $version, false, true),
            new DependencyResolution('widget@2', $identity, '2.0.0', true, false),
        ], [
            new DependencyRequirement(null, 'widget(peer@2)', 'alias', '^1', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement(null, 'widget(peer@2)', 'alias', '^1', DependencyRequirementKind::Dependency, DependencyScope::Development),
            new DependencyRequirement('widget@2', 'widget(peer@2)', 'widget', '^1', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement('widget(peer@3)', null, 'peer', '^3', DependencyRequirementKind::Peer, DependencyScope::Development, true),
            new DependencyRequirement(null, null, 'platform', '*', DependencyRequirementKind::Dependency, DependencyScope::Regular),
        ]),
    );
}

function dependency_publication_failure(string $code = 'dependencies.incomplete_source'): DependencyScanResult
{
    return DependencyScanResult::failed(DependencyEcosystem::Npm, new DateTimeImmutable('2026-09-15 13:00:00 UTC'), $code);
}

describe('dependency snapshot replacement', function (): void {
    it('round trips graph contracts and keeps repeated scans free of duplicate usage', function (): void {
        $instance = dependency_publication_instance();
        $snapshot = dependency_publication_snapshot();
        $publisher = app(PublishInstanceDependencyScanAction::class);
        $publisher->execute($instance->id, DependencyScanResult::refreshed($snapshot));

        $result = $publisher->execute($instance->id, DependencyScanResult::refreshed($snapshot));

        expect($result->snapshot)->toEqual($snapshot);
        expect($result->state())->toBe(DependencyInventoryState::Present);
        $this->assertDatabaseCount('dependency_packages', 1);
        $this->assertDatabaseCount('app_instance_dependency_observations', 1);
        $this->assertDatabaseCount('app_instance_dependency_resolutions', 3);
        $this->assertDatabaseCount('app_instance_dependency_edges', 5);
        $this->assertDatabaseCount('app_instance_dependency_scan_attempts', 2);
        expect(app(ReadInstanceDependencyScanAction::class)->execute($instance->id, DependencyEcosystem::Npm))->toEqual($result);
    });

    it('removes obsolete usage while preserving another instance and shared identities', function (): void {
        $instance = dependency_publication_instance();
        $other = dependency_publication_instance('worker');
        $snapshot = dependency_publication_snapshot();
        $publisher = app(PublishInstanceDependencyScanAction::class);
        $publisher->execute($instance->id, DependencyScanResult::refreshed($snapshot));
        $publisher->execute($other->id, DependencyScanResult::refreshed($snapshot));
        $replacement = new DependencySnapshot($snapshot->ecosystem, $snapshot->source, new DateTimeImmutable('2026-09-15 14:00:00 UTC'), new DependencyGraph(
            $snapshot->ecosystem,
            [new DependencyResolution('replacement', new DependencyIdentity($snapshot->ecosystem, 'replacement'), 'dev-main', true, true)],
            [],
        ));

        $result = $publisher->execute($instance->id, DependencyScanResult::refreshed($replacement));

        expect($result->snapshot)->toEqual($replacement);
        expect(app(ReadInstanceDependencyScanAction::class)->execute($other->id, DependencyEcosystem::Npm)?->snapshot)->toEqual($snapshot);
        $this->assertDatabaseCount('dependency_packages', 2);
        $this->assertDatabaseCount('app_instance_dependency_resolutions', 4);
        $this->assertDatabaseCount('app_instance_dependency_edges', 5);
    });

    it('clears previous usage for verified absence and present empty projects', function (bool $present): void {
        $instance = dependency_publication_instance();
        $previous = dependency_publication_snapshot();
        $publisher = app(PublishInstanceDependencyScanAction::class);
        $publisher->execute($instance->id, DependencyScanResult::refreshed($previous));
        $snapshot = new DependencySnapshot(
            DependencyEcosystem::Npm,
            new DependencySource('/srv/project', null, ['package.json' => $present ? str_repeat('b', 64) : null], $present ? 'fixture:1' : null),
            new DateTimeImmutable('2026-09-15 13:00:00 UTC'),
            $present ? new DependencyGraph(DependencyEcosystem::Npm, [], []) : null,
        );

        $result = $publisher->execute($instance->id, DependencyScanResult::refreshed($snapshot));

        expect($result->state())->toBe($present ? DependencyInventoryState::Present : DependencyInventoryState::Absent);
        expect($result->snapshot)->toEqual($snapshot);
        $this->assertDatabaseCount('app_instance_dependency_resolutions', 0);
        $this->assertDatabaseCount('app_instance_dependency_edges', 0);
        $this->assertDatabaseCount('dependency_packages', 1);
    })->with(['verified absence' => false, 'empty project' => true]);
});

describe('latest attempt and retained observations', function (): void {
    it('preserves observation and attempt instants across caller timezones', function (): void {
        $instance = dependency_publication_instance();
        $publisher = app(PublishInstanceDependencyScanAction::class);
        $snapshot = dependency_publication_snapshot(time: '2026-09-15 14:00:00 +02:00');

        $success = $publisher->execute($instance->id, DependencyScanResult::refreshed($snapshot));
        $failure = $publisher->execute($instance->id, DependencyScanResult::failed(DependencyEcosystem::Npm, new DateTimeImmutable('2026-09-15 15:00:00 +02:00'), 'dependencies.unreadable_source'));

        expect($success->snapshot?->observedAt->format('c'))->toBe('2026-09-15T12:00:00+00:00');
        expect($failure->attemptedAt->format('c'))->toBe('2026-09-15T13:00:00+00:00');
        expect($failure->snapshot?->observedAt)->toEqual($snapshot->observedAt);
    });

    it('distinguishes never scanned and first failure without inventing absence', function (): void {
        $instance = dependency_publication_instance();
        $reader = app(ReadInstanceDependencyScanAction::class);
        expect($reader->execute($instance->id, DependencyEcosystem::Npm))->toBeNull();

        $result = app(PublishInstanceDependencyScanAction::class)->execute($instance->id, dependency_publication_failure());

        expect($result->state())->toBe(DependencyInventoryState::Unknown);
        expect($reader->execute($instance->id, DependencyEcosystem::Npm))->toEqual($result);
        $this->assertDatabaseCount('app_instance_dependency_observations', 0);
        $this->assertDatabaseHas('app_instance_dependency_scan_attempts', ['error_code' => 'dependencies.incomplete_source']);
    });

    it('retains the stored snapshot and provenance instead of trusting a failure supplied snapshot', function (): void {
        $instance = dependency_publication_instance();
        $snapshot = dependency_publication_snapshot();
        $publisher = app(PublishInstanceDependencyScanAction::class);
        $publisher->execute($instance->id, DependencyScanResult::refreshed($snapshot));
        $previousRow = AppInstanceDependencyObservation::query()->sole()->getAttributes();
        $failure = DependencyScanResult::failed(DependencyEcosystem::Npm, new DateTimeImmutable('2026-09-15 13:00:00 UTC'), 'dependencies.source_changed', dependency_publication_snapshot(version: 'untrusted'));

        $result = $publisher->execute($instance->id, $failure);

        expect($result->snapshot)->toEqual($snapshot);
        expect($result->state())->toBe(DependencyInventoryState::Stale);
        expect($result->attemptedAt)->toEqual($failure->attemptedAt);
        expect($result->errorCode)->toBe('dependencies.source_changed');
        expect(AppInstanceDependencyObservation::query()->sole()->getAttributes())->toBe($previousRow);
    });

    it('keeps previously verified absence stale after failure and clears failure on recovery', function (): void {
        $instance = dependency_publication_instance();
        $snapshot = new DependencySnapshot(DependencyEcosystem::Npm, new DependencySource('/srv/project', null, ['package.json' => null], null), new DateTimeImmutable('2026-09-15 12:00:00 UTC'), null);
        $publisher = app(PublishInstanceDependencyScanAction::class);
        $publisher->execute($instance->id, DependencyScanResult::refreshed($snapshot));
        $failed = $publisher->execute($instance->id, dependency_publication_failure());
        expect($failed->state())->toBe(DependencyInventoryState::Stale);
        expect($failed->snapshot)->toEqual($snapshot);

        $recovered = $publisher->execute($instance->id, DependencyScanResult::refreshed(dependency_publication_snapshot(time: '2026-09-15 14:00:00 UTC')));

        expect($recovered->state())->toBe(DependencyInventoryState::Present);
        expect($recovered->errorCode)->toBeNull();
        expect(app(ReadInstanceDependencyScanAction::class)->execute($instance->id, DependencyEcosystem::Npm))->toEqual($recovered);
    });

    it('uses recorded attempt order when timestamps match', function (): void {
        $instance = dependency_publication_instance();
        $publisher = app(PublishInstanceDependencyScanAction::class);
        $publisher->execute($instance->id, dependency_publication_failure('dependencies.unreadable_source'));
        $publisher->execute($instance->id, dependency_publication_failure('dependencies.unsupported_format'));

        $result = app(ReadInstanceDependencyScanAction::class)->execute($instance->id, DependencyEcosystem::Npm);

        expect($result?->errorCode)->toBe('dependencies.unsupported_format');
    });

    it('retains successful Composer publication when JavaScript fails', function (): void {
        $instance = dependency_publication_instance();
        $publisher = app(PublishInstanceDependencyScanAction::class);
        $npmSnapshot = dependency_publication_snapshot();
        $publisher->execute($instance->id, DependencyScanResult::refreshed($npmSnapshot));
        $composerSnapshot = dependency_publication_snapshot(DependencyEcosystem::Composer, version: 'dev-main');

        $composer = $publisher->execute($instance->id, DependencyScanResult::refreshed($composerSnapshot));
        $npm = $publisher->execute($instance->id, dependency_publication_failure());

        expect((new InstanceDependencyScanResult($instance->id, $composer, $npm))->succeeded())->toBeFalse();
        expect($composer->snapshot)->toEqual($composerSnapshot);
        expect($npm->snapshot)->toEqual($npmSnapshot);
        expect($npm->state())->toBe(DependencyInventoryState::Stale);
        $this->assertDatabaseCount('app_instance_dependency_observations', 2);
        $this->assertDatabaseCount('dependency_packages', 2);
    });
});

describe('publication failure and removal races', function (): void {
    it('rolls back a failed graph write and records failure outside the replacement transaction', function (): void {
        $instance = dependency_publication_instance();
        $publisher = app(PublishInstanceDependencyScanAction::class);
        $previous = dependency_publication_snapshot();
        $publisher->execute($instance->id, DependencyScanResult::refreshed($previous));
        $snapshot = dependency_publication_snapshot(time: '2026-09-15 14:00:00 UTC', version: 'changed');
        $replacement = new DependencySnapshot($snapshot->ecosystem, $snapshot->source, $snapshot->observedAt, new DependencyGraph(
            $snapshot->ecosystem,
            [...$snapshot->graph->resolutions, new DependencyResolution('new', new DependencyIdentity($snapshot->ecosystem, 'new-package'), '1', true, false)],
            [...$snapshot->graph->requirements, $snapshot->graph->requirements[0]],
        ));
        $oldRow = AppInstanceDependencyObservation::query()->sole()->getAttributes();

        $result = $publisher->execute($instance->id, DependencyScanResult::refreshed($replacement));

        expect($result->errorCode)->toBe('dependencies.persistence_failed');
        expect($result->state())->toBe(DependencyInventoryState::Stale);
        expect($result->snapshot)->toEqual($previous);
        expect(AppInstanceDependencyObservation::query()->sole()->getAttributes())->toBe($oldRow);
        $this->assertDatabaseCount('dependency_packages', 1);
        $this->assertDatabaseCount('app_instance_dependency_resolutions', 3);
        $this->assertDatabaseCount('app_instance_dependency_edges', 5);
        $this->assertDatabaseCount('app_instance_dependency_scan_attempts', 2);
    });

    it('does not commit replacement if recording its successful attempt fails', function (): void {
        $instance = dependency_publication_instance();
        $publisher = app(PublishInstanceDependencyScanAction::class);
        $previous = dependency_publication_snapshot();
        $publisher->execute($instance->id, DependencyScanResult::refreshed($previous));
        DB::unprepared("CREATE TEMP TRIGGER reject_success_attempt BEFORE INSERT ON app_instance_dependency_scan_attempts WHEN NEW.error_code IS NULL BEGIN SELECT RAISE(ABORT, 'injected write failure'); END");

        try {
            $result = $publisher->execute($instance->id, DependencyScanResult::refreshed(dependency_publication_snapshot(version: 'changed')));
        } finally {
            DB::unprepared('DROP TRIGGER reject_success_attempt');
        }

        expect($result->snapshot)->toEqual($previous);
        expect($result->errorCode)->toBe('dependencies.persistence_failed');
        $this->assertDatabaseCount('app_instance_dependency_scan_attempts', 2);
    });

    it('throws when the database cannot record even the failure', function (): void {
        $instance = dependency_publication_instance();
        DB::unprepared("CREATE TEMP TRIGGER reject_all_attempts BEFORE INSERT ON app_instance_dependency_scan_attempts BEGIN SELECT RAISE(ABORT, 'injected write failure'); END");

        try {
            expect(fn () => app(PublishInstanceDependencyScanAction::class)->execute($instance->id, DependencyScanResult::refreshed(dependency_publication_snapshot())))
                ->toThrow(QueryException::class);
        } finally {
            DB::unprepared('DROP TRIGGER reject_all_attempts');
        }

        $this->assertDatabaseCount('app_instance_dependency_observations', 0);
        $this->assertDatabaseCount('app_instance_dependency_scan_attempts', 0);
        $this->assertDatabaseCount('dependency_packages', 0);
    });

    it('records bounded failure after invalid provenance without persisting unsafe input', function (): void {
        $instance = dependency_publication_instance();
        $snapshot = new DependencySnapshot(DependencyEcosystem::Npm, new DependencySource('/srv/project', 'https://user:secret@example.test', [], null), new DateTimeImmutable('2026-09-15 12:00:00 UTC'), null);

        $result = app(PublishInstanceDependencyScanAction::class)->execute($instance->id, DependencyScanResult::refreshed($snapshot));

        expect($result->errorCode)->toBe('dependencies.persistence_failed');
        expect($result->state())->toBe(DependencyInventoryState::Unknown);
        $this->assertDatabaseCount('app_instance_dependency_observations', 0);
        expect(AppInstanceDependencyScanAttempt::query()->sole()->error_code)->toBe('dependencies.persistence_failed');
    });

    it('refuses a collected graph when removal starts before publication', function (bool $markedRemoving): void {
        $instance = dependency_publication_instance();
        $publisher = app(PublishInstanceDependencyScanAction::class);
        $previous = dependency_publication_snapshot();
        $publisher->execute($instance->id, DependencyScanResult::refreshed($previous));
        $instance->app->update(['repository_identity' => 'example.test/dependency-publication']);
        $instance->update(['root' => 'public', 'branch' => 'main', 'starting_commit' => str_repeat('a', 40)]);
        $instance->node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
        $route = Route::query()->create([
            'app_id' => $instance->app_id,
            'node_id' => $instance->node_id,
            'generation_basis_node_id' => $instance->node_id,
            'domain' => 'dependency-publication.test',
            'provenance' => 'generated',
            'publication' => 'private',
            'status' => 'pending',
        ]);
        $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => 'active']);
        $removal = AppInstanceRemoval::query()->create([
            'id' => (string) Str::uuid(),
            'requested_app_instance_id' => $instance->id,
            'requested_name' => $instance->name,
            'force' => false,
            'inventory_digest' => str_repeat('d', 64),
            'total' => 1,
            'status' => 'removing',
            'current_step' => 'source_preparation',
        ]);
        $removal->members()->create([
            'position' => 0,
            'app_instance_id' => $instance->id,
            'app_id' => $instance->app_id,
            'node_id' => $instance->node_id,
            'name' => $instance->name,
            'environment' => $instance->environment,
            'source_layout' => $instance->source_layout,
            'checkout_path' => $instance->checkout_path,
            'linked_worktree_paths' => [],
            'source_digest' => str_repeat('d', 64),
            'route_id' => $route->id,
            'repository_identity' => 'example.test/dependency-publication',
            'root' => 'public',
            'branch' => 'main',
            'starting_commit' => str_repeat('a', 40),
            'source_commit' => str_repeat('a', 40),
            'common_repository_path' => $instance->checkout_path,
            'source_identity' => '1:100',
        ]);
        if ($markedRemoving) {
            AppInstance::query()->whereKey($instance->id)->update(['status' => 'removing']);
        } else {
            $removal->update([
                'status' => 'failed',
                'failed_step' => 'source_preparation',
                'error_code' => 'instance.source_preparation_failed',
            ]);
        }

        $result = $publisher->execute($instance->id, DependencyScanResult::refreshed(dependency_publication_snapshot(version: 'changed')));

        expect($result->errorCode)->toBe('dependencies.instance_unavailable');
        expect($result->state())->toBe(DependencyInventoryState::Stale);
        expect($result->snapshot)->toEqual($previous);
    })->with(['removing status' => true, 'retained failed removal' => false]);

    it('never recreates deleted instance inventory from a late success or failure', function (bool $success): void {
        $instance = dependency_publication_instance();
        $other = dependency_publication_instance('worker');
        $publisher = app(PublishInstanceDependencyScanAction::class);
        $snapshot = dependency_publication_snapshot();
        $publisher->execute($instance->id, DependencyScanResult::refreshed($snapshot));
        $publisher->execute($other->id, DependencyScanResult::refreshed($snapshot));
        $instance->delete();

        $result = $publisher->execute($instance->id, $success ? DependencyScanResult::refreshed($snapshot) : dependency_publication_failure());

        expect($result->errorCode)->toBe('dependencies.instance_unavailable');
        expect($result->snapshot)->toBeNull();
        expect(app(ReadInstanceDependencyScanAction::class)->execute($other->id, DependencyEcosystem::Npm)?->snapshot)->toEqual($snapshot);
        $this->assertDatabaseCount('app_instance_dependency_observations', 1);
        $this->assertDatabaseCount('app_instance_dependency_resolutions', 3);
        $this->assertDatabaseCount('app_instance_dependency_scan_attempts', 1);
        $this->assertDatabaseCount('dependency_packages', 1);
    })->with(['late success' => true, 'late failure' => false]);
});
