<?php

declare(strict_types=1);

use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Dependencies\DependencyRequirementKind;
use App\Domain\AppInstances\Dependencies\DependencyScanResult;
use App\Domain\AppInstances\Dependencies\DependencyScope;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceDependencyEdge;
use App\Models\AppInstanceDependencyObservation;
use App\Models\AppInstanceDependencyResolution;
use App\Models\AppInstanceDependencyScanAttempt;
use App\Models\DependencyPackage;
use App\Models\Node;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function dependency_inventory_instance(string $name = 'web'): AppInstance
{
    $app = OrbitApp::query()->firstOrCreate(['slug' => 'dependency-inventory'], [
        'name' => 'Dependency inventory',
        'repository_url' => 'https://example.test/dependency-inventory.git',
    ]);
    $node = Node::query()->firstOrCreate(['name' => 'dependency-inventory'], [
        'public_ssh_host' => '192.0.2.180',
        'status' => 'active',
    ]);

    return $app->appInstances()->create([
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => '/srv/dependency-inventory/'.$name,
        'status' => 'active',
    ]);
}

function dependency_inventory_observation(
    AppInstance $instance,
    DependencyEcosystem $ecosystem = DependencyEcosystem::Npm,
    bool $present = true,
): AppInstanceDependencyObservation {
    return $instance->dependencyObservations()->create([
        'ecosystem' => $ecosystem,
        'present' => $present,
        'observed_at' => '2026-09-15 12:00:00',
        'project_root' => $instance->checkout_path,
        'source_reference' => 'release/2026-09-15',
        'format' => $present ? 'pnpm:9.0' : null,
        'file_hashes' => ['package.json' => $present ? str_repeat('a', 64) : null, 'pnpm-lock.yaml' => null],
    ]);
}

function dependency_inventory_resolution(
    AppInstanceDependencyObservation $observation,
    string $locator = 'widget@1.0.0(peer@2.0.0)',
    string $version = '1.0.0',
): AppInstanceDependencyResolution {
    $package = DependencyPackage::query()->firstOrCreate([
        'ecosystem' => $observation->ecosystem,
        'name' => 'widget',
    ]);

    return $observation->resolutions()->create([
        'dependency_package_id' => $package->id,
        'ecosystem' => $observation->ecosystem,
        'locator' => $locator,
        'version' => $version,
        'regular' => true,
        'development' => true,
        'source_reference' => 'feature/opaque-branch',
        'integrity' => 'sha512-fixture',
    ]);
}

/** @param array<string, mixed> $attributes */
function dependency_inventory_edge(AppInstanceDependencyObservation $observation, array $attributes = []): AppInstanceDependencyEdge
{
    return $observation->edges()->create(array_replace([
        'from_resolution_id' => null,
        'to_resolution_id' => null,
        'name' => 'widget-alias',
        'constraint' => 'npm:widget@^1',
        'kind' => DependencyRequirementKind::Dependency,
        'scope' => DependencyScope::Regular,
        'optional' => false,
    ], $attributes));
}

describe('dependency inventory persistence', function (): void {
    it('round trips multiple versions, peer contexts and overlapping paths across shared identities', function (): void {
        $instance = dependency_inventory_instance();
        $observation = dependency_inventory_observation($instance);
        $first = dependency_inventory_resolution($observation);
        $second = dependency_inventory_resolution($observation, 'widget@1.0.0(peer@3.0.0)');
        $third = dependency_inventory_resolution($observation, 'widget@2.0.0', '2.0.0');
        $other = dependency_inventory_observation(dependency_inventory_instance('worker'));
        dependency_inventory_resolution($other);
        $composer = dependency_inventory_observation($instance, DependencyEcosystem::Composer);
        $branch = dependency_inventory_resolution($composer, 'widget:dev-main', 'dev-main');
        $direct = dependency_inventory_edge($observation, ['to_resolution_id' => $first->id]);
        dependency_inventory_edge($observation, ['to_resolution_id' => $first->id, 'scope' => DependencyScope::Development]);
        $transitive = dependency_inventory_edge($observation, ['from_resolution_id' => $third->id, 'to_resolution_id' => $first->id]);
        $peer = dependency_inventory_edge($observation, [
            'from_resolution_id' => $second->id,
            'kind' => DependencyRequirementKind::Peer,
            'optional' => true,
        ]);

        $stored = $observation->fresh(['resolutions.package', 'edges']);

        expect($stored->resolutions->pluck('locator')->all())->toBe([
            'widget@1.0.0(peer@2.0.0)', 'widget@1.0.0(peer@3.0.0)', 'widget@2.0.0',
        ]);
        expect($stored->resolutions->pluck('version')->all())->toBe(['1.0.0', '1.0.0', '2.0.0']);
        expect($stored->resolutions->every(fn (AppInstanceDependencyResolution $resolution): bool => $resolution->regular && $resolution->development))->toBeTrue();
        expect($first->package->resolutions()->count())->toBe(4);
        expect($branch->fresh()->version)->toBe('dev-main');
        expect($direct->fresh()->fromResolution)->toBeNull();
        expect($direct->fresh()->name)->toBe('widget-alias');
        expect($direct->fresh()->constraint)->toBe('npm:widget@^1');
        expect($direct->fresh()->toResolution->is($first))->toBeTrue();
        expect($transitive->fresh()->fromResolution->is($third))->toBeTrue();
        expect($first->incomingRequirements()->count())->toBe(3);
        expect($second->requirements()->sole()->is($peer))->toBeTrue();
        expect($peer->fresh()->toResolution)->toBeNull();
        expect($peer->fresh()->kind)->toBe(DependencyRequirementKind::Peer);
        expect($peer->fresh()->optional)->toBeTrue();
        expect($stored->edges->pluck('scope')->all())->toContain(DependencyScope::Regular, DependencyScope::Development);
        expect($stored->appInstance->is($instance))->toBeTrue();
        expect($first->observation->is($observation))->toBeTrue();
        expect($direct->observation->is($observation))->toBeTrue();
        $this->assertDatabaseCount('dependency_packages', 2);
    });

    it('distinguishes unknown, verified absence and an empty present project while retaining failed attempts', function (): void {
        $instance = dependency_inventory_instance();
        expect($instance->dependencyObservations()->exists())->toBeFalse();
        $firstFailure = $instance->dependencyScanAttempts()->create([
            'ecosystem' => DependencyEcosystem::Npm,
            'attempted_at' => '2026-09-15 11:00:00',
            'error_code' => 'source_unavailable',
        ]);
        expect($instance->dependencyObservations()->exists())->toBeFalse();
        $absent = dependency_inventory_observation($instance, present: false);
        $empty = dependency_inventory_observation($instance, DependencyEcosystem::Composer);
        $instance->dependencyScanAttempts()->create([
            'ecosystem' => DependencyEcosystem::Npm,
            'attempted_at' => '2026-09-15 12:00:00',
            'error_code' => null,
        ]);
        $instance->dependencyScanAttempts()->create([
            'ecosystem' => DependencyEcosystem::Npm,
            'attempted_at' => '2026-09-15 13:00:00',
            'error_code' => 'source_changed',
        ]);

        expect($absent->fresh()->present)->toBeFalse();
        expect($empty->fresh()->present)->toBeTrue();
        expect($empty->resolutions()->exists())->toBeFalse();
        expect($absent->fresh()->observed_at)->toBeInstanceOf(CarbonImmutable::class);
        expect($absent->fresh()->observed_at->format('Y-m-d H:i:s'))->toBe('2026-09-15 12:00:00');
        expect($absent->fresh()->file_hashes)->toBe(['package.json' => null, 'pnpm-lock.yaml' => null]);
        expect($instance->dependencyScanAttempts()->orderBy('id')->pluck('error_code')->all())
            ->toBe(['source_unavailable', null, 'source_changed']);
        expect($firstFailure->fresh()->attempted_at)->toBeInstanceOf(CarbonImmutable::class);
        expect($firstFailure->appInstance->is($instance))->toBeTrue();
    });

    it('rejects duplicate package, observation and resolution identities', function (): void {
        $instance = dependency_inventory_instance();
        $observation = dependency_inventory_observation($instance);
        $resolution = dependency_inventory_resolution($observation);

        expect(fn () => DependencyPackage::query()->create(['ecosystem' => 'npm', 'name' => 'widget']))->toThrow(QueryException::class);
        expect(fn () => dependency_inventory_observation($instance))->toThrow(QueryException::class);
        expect(fn () => dependency_inventory_resolution($observation, $resolution->locator, '99.0.0'))->toThrow(QueryException::class);
    });

    it('rejects duplicate edges even with root or unresolved endpoints', function (bool $from, bool $to): void {
        $observation = dependency_inventory_observation(dependency_inventory_instance());
        $resolution = dependency_inventory_resolution($observation);
        $attributes = ['from_resolution_id' => $from ? $resolution->id : null, 'to_resolution_id' => $to ? $resolution->id : null];
        dependency_inventory_edge($observation, $attributes);

        expect(fn () => dependency_inventory_edge($observation, $attributes))->toThrow(QueryException::class);
    })->with([[false, false], [false, true], [true, false], [true, true]]);

    it('keeps differing requirement semantics as distinct edges', function (array $difference): void {
        $observation = dependency_inventory_observation(dependency_inventory_instance());
        dependency_inventory_edge($observation);
        dependency_inventory_edge($observation, $difference);

        expect($observation->edges()->count())->toBe(2);
    })->with([
        'scope' => [['scope' => DependencyScope::Development]],
        'kind' => [['kind' => DependencyRequirementKind::Peer]],
        'optional' => [['optional' => true]],
        'alias' => [['name' => 'another-alias']],
        'constraint' => [['constraint' => '^2']],
    ]);

    it('rejects resolution and package references from different ecosystems', function (): void {
        $instance = dependency_inventory_instance();
        $npm = dependency_inventory_observation($instance);
        $composer = dependency_inventory_observation($instance, DependencyEcosystem::Composer);
        $resolution = dependency_inventory_resolution($npm);
        $composerResolution = dependency_inventory_resolution($composer);

        expect(fn () => $resolution->update(['observation_id' => $composer->id]))->toThrow(QueryException::class);
        expect(fn () => $resolution->fresh()->update(['dependency_package_id' => $composerResolution->dependency_package_id]))->toThrow(QueryException::class);
    });

    it('rejects cross-observation and missing edge endpoints', function (string $field, bool $missing): void {
        $instance = dependency_inventory_instance();
        $observation = dependency_inventory_observation($instance);
        $other = dependency_inventory_observation(dependency_inventory_instance('other'));
        $foreign = dependency_inventory_resolution($other);

        expect(fn () => dependency_inventory_edge($observation, [$field => $missing ? $foreign->id + 100 : $foreign->id]))
            ->toThrow(QueryException::class);
    })->with([
        ['from_resolution_id', false], ['to_resolution_id', false],
        ['from_resolution_id', true], ['to_resolution_id', true],
    ]);

    it('rejects orphan observations, attempts and package resolutions', function (): void {
        $instance = dependency_inventory_instance();
        $observation = dependency_inventory_observation($instance);
        $resolution = dependency_inventory_resolution($observation);

        expect(fn () => $observation->update(['app_instance_id' => $instance->id + 100]))->toThrow(QueryException::class);
        expect(fn () => AppInstanceDependencyScanAttempt::query()->create([
            'app_instance_id' => $instance->id + 100, 'ecosystem' => 'npm', 'attempted_at' => now(),
        ]))->toThrow(QueryException::class);
        expect(fn () => $resolution->update(['dependency_package_id' => $resolution->dependency_package_id + 100]))->toThrow(QueryException::class);
    });

    it('deletes only the removed instance inventory and retains the shared catalog', function (): void {
        $removed = dependency_inventory_instance();
        $retained = dependency_inventory_instance('retained');
        $first = dependency_inventory_observation($removed);
        $second = dependency_inventory_observation($retained);
        $firstResolution = dependency_inventory_resolution($first);
        $secondResolution = dependency_inventory_resolution($second);
        dependency_inventory_edge($first, ['to_resolution_id' => $firstResolution->id]);
        $retainedEdge = dependency_inventory_edge($second, ['to_resolution_id' => $secondResolution->id]);
        foreach ([$removed, $retained] as $instance) {
            $instance->dependencyScanAttempts()->create(['ecosystem' => 'npm', 'attempted_at' => now(), 'error_code' => 'source_changed']);
        }

        $removed->delete();

        $this->assertModelMissing($first);
        $this->assertModelMissing($firstResolution);
        $this->assertModelExists($second);
        $this->assertModelExists($secondResolution);
        $this->assertModelExists($retainedEdge);
        $this->assertDatabaseCount('app_instance_dependency_edges', 1);
        $this->assertDatabaseCount('app_instance_dependency_scan_attempts', 1);
        expect(fn () => $secondResolution->package->delete())->toThrow(QueryException::class);
        $retained->delete();
        $this->assertDatabaseCount('dependency_packages', 1);
        $this->assertDatabaseCount('app_instance_dependency_observations', 0);
        $this->assertDatabaseCount('app_instance_dependency_resolutions', 0);
        $this->assertDatabaseCount('app_instance_dependency_edges', 0);
        $this->assertDatabaseCount('app_instance_dependency_scan_attempts', 0);
    });

    it('rolls back an interrupted graph replacement without losing the previous graph or other ecosystem', function (): void {
        $instance = dependency_inventory_instance();
        $observation = dependency_inventory_observation($instance);
        $other = dependency_inventory_observation($instance, DependencyEcosystem::Composer);
        $resolution = dependency_inventory_resolution($observation);
        $edge = dependency_inventory_edge($observation, ['to_resolution_id' => $resolution->id]);

        expect(fn () => DB::transaction(function () use ($observation): void {
            $observation->resolutions()->delete();
            $observation->update(['present' => false, 'observed_at' => '2026-09-15 14:00:00']);
            throw new RuntimeException('Interrupted publication');
        }))->toThrow(RuntimeException::class, 'Interrupted publication');

        $this->assertModelExists($resolution);
        $this->assertModelExists($edge);
        $this->assertModelExists($other);
        expect($observation->fresh()->present)->toBeTrue();
        expect($observation->fresh()->observed_at->format('Y-m-d H:i:s'))->toBe('2026-09-15 12:00:00');
    });

    it('rolls the inventory migration back and reapplies it without changing instance state', function (): void {
        $instance = dependency_inventory_instance();
        $before = $instance->fresh()->getAttributes();
        $observation = dependency_inventory_observation($instance);
        $resolution = dependency_inventory_resolution($observation);
        dependency_inventory_edge($observation, ['to_resolution_id' => $resolution->id]);
        $instance->dependencyScanAttempts()->create(['ecosystem' => 'npm', 'attempted_at' => now()]);
        $migration = require base_path('database/migrations/2026_09_15_200000_create_instance_dependency_inventory.php');

        $migration->down();
        try {
            foreach (['dependency_packages', 'app_instance_dependency_observations', 'app_instance_dependency_resolutions', 'app_instance_dependency_edges', 'app_instance_dependency_scan_attempts'] as $table) {
                expect(Schema::hasTable($table))->toBeFalse();
            }
            expect($instance->fresh()->getAttributes())->toBe($before);
        } finally {
            $migration->up();
        }

        $new = dependency_inventory_observation($instance);
        dependency_inventory_resolution($new);
        $this->assertDatabaseCount('app_instance_dependency_resolutions', 1);
        expect($instance->fresh()->getAttributes())->toBe($before);
    });

    it('round trips named provenance without raw source or process fields', function (): void {
        $observation = dependency_inventory_observation(dependency_inventory_instance());
        $resolution = dependency_inventory_resolution($observation);

        expect($observation->fresh()->project_root)->toBe('/srv/dependency-inventory/web');
        expect($observation->fresh()->source_reference)->toBe('release/2026-09-15');
        expect($observation->fresh()->file_hashes)->toBe(['package.json' => str_repeat('a', 64), 'pnpm-lock.yaml' => null]);
        expect($observation->fresh()->format)->toBe('pnpm:9.0');
        expect($resolution->fresh()->source_reference)->toBe('feature/opaque-branch');
        expect($resolution->fresh()->integrity)->toBe('sha512-fixture');
        foreach ([$observation, $resolution, new AppInstanceDependencyScanAttempt] as $model) {
            foreach (['stdout', 'stderr', 'metadata', 'source_url', 'manifest', 'lockfile', 'credentials'] as $field) {
                expect($model->isFillable($field))->toBeFalse();
                expect(Schema::hasColumn($model->getTable(), $field))->toBeFalse();
            }
        }
    });

    it('rejects credential-bearing or URL source references before saving', function (string $reference): void {
        $observation = dependency_inventory_observation(dependency_inventory_instance());
        $resolution = dependency_inventory_resolution($observation);

        expect(fn () => $observation->update(['source_reference' => $reference]))->toThrow(InvalidArgumentException::class);
        expect(fn () => $resolution->update(['source_reference' => $reference]))->toThrow(InvalidArgumentException::class);
        expect($observation->fresh()->source_reference)->toBe('release/2026-09-15');
        expect($resolution->fresh()->source_reference)->toBe('feature/opaque-branch');
    })->with(['https://user:secret@example.test/repo', 'git@example.test:repo', 'https://example.test/repo?token=secret', "branch\nsecret", '']);

    it('rejects malformed file provenance before saving', function (array $hashes): void {
        $observation = dependency_inventory_observation(dependency_inventory_instance());

        expect(fn () => $observation->update(['file_hashes' => $hashes]))->toThrow(InvalidArgumentException::class);
        expect($observation->fresh()->file_hashes)->toBe(['package.json' => str_repeat('a', 64), 'pnpm-lock.yaml' => null]);
    })->with([
        'source contents' => [['package.json' => '{"private":"secret"}']],
        'outside root' => [['../package.json' => str_repeat('a', 64)]],
        'authenticated url' => [['https://user:secret@example.test/package.json' => null]],
        'wrong type' => [['package.json' => ['token' => 'secret']]],
        'numeric key' => [[0 => str_repeat('a', 64)]],
    ]);

    it('persists failed scan contract codes unchanged', function (string $errorCode): void {
        $instance = dependency_inventory_instance();
        $result = DependencyScanResult::failed(
            DependencyEcosystem::Npm,
            new DateTimeImmutable('2026-09-15T12:00:00Z'),
            $errorCode,
        );

        $attempt = $instance->dependencyScanAttempts()->create([
            'ecosystem' => $result->ecosystem,
            'attempted_at' => $result->attemptedAt,
            'error_code' => $result->errorCode,
        ])->fresh();

        expect($attempt->error_code)->toBe($errorCode);
        expect($attempt->ecosystem)->toBe(DependencyEcosystem::Npm);
        expect($attempt->attempted_at->format('Y-m-d H:i:s'))->toBe('2026-09-15 12:00:00');
    })->with([
        'dependencies.source_changed',
        'dependencies.unsupported_format',
        'dependencies.unreadable_source',
        'dependencies.'.str_repeat('a', 115),
    ]);

    it('rejects unsafe or malformed attempt errors', function (string $errorCode): void {
        $instance = dependency_inventory_instance();

        expect(fn () => $instance->dependencyScanAttempts()->create([
            'ecosystem' => 'npm', 'attempted_at' => now(), 'error_code' => $errorCode,
        ]))->toThrow(InvalidArgumentException::class);
        $this->assertDatabaseCount('app_instance_dependency_scan_attempts', 0);
    })->with([
        'process text' => 'Failed fetching https://user:secret@example.test',
        'url' => 'https://example.test',
        'credentials' => 'user:secret@example.test',
        'newline' => "dependencies.source_changed\n",
        'empty' => '',
        'too long' => 'dependencies.'.str_repeat('a', 116),
        'leading dot' => '.dependencies.source_changed',
        'trailing dot' => 'dependencies.source_changed.',
        'empty segment' => 'dependencies..source_changed',
    ]);

    it('rejects missing file provenance instead of storing an incomplete observation', function (): void {
        $observation = dependency_inventory_observation(dependency_inventory_instance());

        expect(fn () => $observation->update(['file_hashes' => null]))->toThrow(InvalidArgumentException::class);
        expect($observation->fresh()->file_hashes)->toBe(['package.json' => str_repeat('a', 64), 'pnpm-lock.yaml' => null]);
    });
});
