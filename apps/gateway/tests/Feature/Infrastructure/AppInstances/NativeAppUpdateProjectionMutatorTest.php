<?php

declare(strict_types=1);

use App\Actions\Apps\UpdateAppAction;
use App\Data\Apps\UpdateAppData;
use App\Domain\Apps\AppUpdateProjectionMutator;
use App\Domain\Apps\AppUpdateStatus;
use App\Domain\Routes\RouteProvenance;
use App\Infrastructure\Apps\NativeAppUpdateProjectionMutator;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\AppUpdate;
use App\Models\Route;
use App\Models\RouteTarget;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\Orb101AppUpdateFixture;

function orb028_update_data(): UpdateAppData
{
    return new UpdateAppData(
        typeProvided: false,
        type: null,
        slugProvided: true,
        slug: 'shop',
        repositoryUrlProvided: true,
        repositoryUrl: 'https://github.com/acme/site.git',
        defaultBranchProvided: true,
        defaultBranch: 'stable',
        rootProvided: false,
        root: null,
    );
}

function orb028_feature_route(Orb101AppUpdateFixture $fixture): Route
{
    $instance = $fixture->defaultInstance->replicate(['name']);
    $instance->fill(['name' => 'feature', 'checkout_path' => '/srv/orbit/apps/acme/feature', 'branch' => 'feature']);
    $instance->save();
    $route = $fixture->defaultRoute->replicate(['domain']);
    $route->fill(['domain' => 'feature.acme.test', 'status' => 'pending']);
    $route->save();
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);

    return $route;
}

describe('native Project Route preparation', function (): void {
    it('rolls back every candidate pointer and target when preparation or its journal fails', function (string $failure): void {
        $fixture = Orb101AppUpdateFixture::bind($this);
        orb028_feature_route($fixture);
        $fixture->defaultInstance->environmentValues()->create(['env_key' => 'APP_URL', 'env_value' => 'https://acme.test']);
        app()->instance(SshExecutor::class, new AppDevFakeSshExecutor);
        app()->instance(AppUpdateProjectionMutator::class, app(NativeAppUpdateProjectionMutator::class));
        $routes = Route::query()->orderBy('id')->get()->toArray();
        $targets = RouteTarget::query()->orderBy('id')->get()->toArray();
        $trigger = match ($failure) {
            'candidate' => <<<'SQL'
                CREATE TEMP TRIGGER route_preparation_failure BEFORE INSERT ON routes
                WHEN NEW.replaces_route_id IS NOT NULL
                    AND EXISTS (SELECT 1 FROM routes WHERE replaces_route_id IS NOT NULL)
                BEGIN SELECT RAISE(ABORT, 'Injected second candidate failure.'); END
                SQL,
            'target' => <<<'SQL'
                CREATE TEMP TRIGGER route_preparation_failure BEFORE INSERT ON route_targets
                WHEN (SELECT replaces_route_id FROM routes WHERE id = NEW.route_id) IS NOT NULL
                    AND EXISTS (SELECT 1 FROM route_targets JOIN routes ON routes.id = route_targets.route_id
                        WHERE routes.replaces_route_id IS NOT NULL)
                BEGIN SELECT RAISE(ABORT, 'Injected second target failure.'); END
                SQL,
            'journal' => <<<'SQL'
                CREATE TEMP TRIGGER route_preparation_failure BEFORE UPDATE ON app_updates
                WHEN json_extract(NEW.evidence, '$.slug') IS NOT NULL
                BEGIN SELECT RAISE(ABORT, 'Injected Route journal failure.'); END
                SQL,
        };
        DB::unprepared($trigger);

        try {
            expect(fn () => app(UpdateAppAction::class)->execute($fixture->app, orb028_update_data()))
                ->toThrow(QueryException::class);
        } finally {
            DB::unprepared('DROP TRIGGER route_preparation_failure');
        }

        expect(Route::query()->orderBy('id')->get()->toArray())->toBe($routes);
        expect(RouteTarget::query()->orderBy('id')->get()->toArray())->toBe($targets);
        expect($fixture->defaultInstance->environmentValues()->sole()->env_value)->toBe('https://acme.test');
        expect($fixture->sources->originRestores)->toBe(['/srv/orbit/apps/acme/default', '/srv/orbit/apps/acme/feature']);
        expect($fixture->sources->restoredBranches)->toBe([['instance_id' => $fixture->defaultInstance->id, 'branch' => 'main']]);
        expect($fixture->defaultInstance->refresh()->branch)->toBe('main');
        expect($fixture->app->refresh()->slug)->toBe('acme');
        expect($fixture->app->repository_url)->toBe('git@github.com:acme/site.git');
        expect(AppUpdate::query()->sole()->evidence)->not->toHaveKey('slug');
        expect(AppUpdate::query()->sole()->status)->toBe(AppUpdateStatus::RolledBack);
    })->with(['candidate', 'target', 'journal']);

    it('commits recoverable candidates together and reuses them on an interrupted preparation retry', function (): void {
        $fixture = Orb101AppUpdateFixture::bind($this);
        $feature = orb028_feature_route($fixture);
        $production = $fixture->defaultInstance->replicate(['name']);
        $production->fill([
            'name' => 'production', 'environment' => 'production', 'source_layout' => 'release',
            'checkout_path' => '/srv/orbit/apps/acme/releases/initial',
        ]);
        $production->save();
        $explicit = $fixture->defaultRoute->replicate(['domain']);
        $explicit->fill([
            'domain' => 'custom.example.test', 'provenance' => RouteProvenance::Explicit,
            'generation_basis_node_id' => null, 'status' => 'pending',
        ]);
        $explicit->save();
        $explicit->targets()->create(['app_instance_id' => $production->id, 'position' => 0]);
        $explicit->update(['status' => 'active']);
        $fixture->defaultInstance->environmentValues()->create(['env_key' => 'APP_URL', 'env_value' => 'https://acme.test']);
        app()->instance(SshExecutor::class, new AppDevFakeSshExecutor);
        app()->instance(AppUpdateProjectionMutator::class, app(NativeAppUpdateProjectionMutator::class));
        DB::unprepared(<<<'SQL'
            CREATE TEMP TRIGGER pause_project_publication BEFORE UPDATE ON apps
            WHEN NEW.slug = 'shop'
            BEGIN SELECT RAISE(ABORT, 'Injected publication interruption.'); END
            SQL);

        try {
            expect(fn () => app(UpdateAppAction::class)->execute($fixture->app, orb028_update_data()))
                ->toThrow(QueryException::class);
            $update = AppUpdate::query()->sole();
            $prepared = $update->evidence['slug']['routes'];
            $candidateIds = array_column($prepared, 'replacement_id');

            expect($update->status)->toBe(AppUpdateStatus::Publishing);
            expect(array_column($prepared, 'route_id'))->toBe([$fixture->defaultRoute->id, $feature->id]);
            expect($prepared[0]['previous_env'])->toBe('https://acme.test');
            expect(Route::query()->whereNotNull('replaces_route_id')->orderBy('id')->pluck('id')->all())->toBe($candidateIds);
            expect($fixture->defaultRoute->refresh()->replaced_by_route_id)->toBe($candidateIds[0]);
            expect($feature->refresh()->replaced_by_route_id)->toBe($candidateIds[1]);
            expect(RouteTarget::query()->whereIn('route_id', $candidateIds)->orderBy('route_id')->pluck('position')->all())->toBe([0, 0]);

            $update->update(['status' => AppUpdateStatus::Preflighted]);
            expect(fn () => app(UpdateAppAction::class)->execute($fixture->app, orb028_update_data()))
                ->toThrow(QueryException::class);

            expect($update->refresh()->status)->toBe(AppUpdateStatus::Publishing);
            expect($update->evidence['slug']['routes'])->toBe($prepared);
            expect(Route::query()->whereNotNull('replaces_route_id')->orderBy('id')->pluck('id')->all())->toBe($candidateIds);
        } finally {
            DB::unprepared('DROP TRIGGER pause_project_publication');
        }

        app(UpdateAppAction::class)->execute($fixture->app, orb028_update_data());

        expect(AppUpdate::query()->sole()->status)->toBe(AppUpdateStatus::Complete);
        expect(Route::query()->orderBy('domain')->pluck('domain')->all())
            ->toBe(['custom.example.test', 'feature.shop.test', 'shop.test']);
        expect(Route::query()->whereIn('id', $candidateIds)->orderBy('id')->pluck('id')->all())->toBe($candidateIds);
        expect($explicit->refresh()->replaced_by_route_id)->toBeNull();
        expect($fixture->sources->switchedInstances)->toBe([$fixture->defaultInstance->id]);
        expect($fixture->sources->originMutations)->toBe(['/srv/orbit/apps/acme/default', '/srv/orbit/apps/acme/feature']);
    });
});
