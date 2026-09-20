<?php

declare(strict_types=1);

use App\Actions\Apps\UpdateAppAction;
use App\Data\Apps\UpdateAppData;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Apps\AppUpdateStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\AppInstanceEnvironmentValue;
use App\Models\AppUpdate;
use App\Models\Route;
use Tests\Support\Orb101AppUpdateFixture;

beforeEach(function (): void {
    $this->fixture = Orb101AppUpdateFixture::bind($this);
});

function orb101_update_data(
    ?string $slug = null,
    ?string $repositoryUrl = null,
    ?string $defaultBranch = null,
    ?string $root = null,
): UpdateAppData {
    return new UpdateAppData(
        typeProvided: false,
        type: null,
        slugProvided: $slug !== null,
        slug: $slug,
        repositoryUrlProvided: $repositoryUrl !== null,
        repositoryUrl: $repositoryUrl,
        defaultBranchProvided: $defaultBranch !== null,
        defaultBranch: $defaultBranch,
        rootProvided: $root !== null,
        root: $root,
    );
}

describe('UpdateAppAction', function (): void {
    it('preserves an explicit default-instance branch selection that matched the old default', function (): void {
        $this->fixture->defaultInstance->update(['branch_override' => 'main']);

        app(UpdateAppAction::class)->execute(
            $this->fixture->app,
            orb101_update_data(defaultBranch: 'stable'),
        );

        expect($this->fixture->app->refresh()->default_branch)
            ->toBe('stable')
            ->and($this->fixture->defaultInstance->refresh()->branch)
            ->toBe('main')
            ->and($this->fixture->defaultInstance->branch_override)
            ->toBe('main')
            ->and($this->fixture->sources->switchedInstances)
            ->toBe([]);
    });

    it('refuses a default-branch update before publication when a source cannot switch', function (): void {
        $this->fixture->sources->refuseDefaultBranchPreflight = true;

        expect(fn () => app(UpdateAppAction::class)->execute(
            $this->fixture->app,
            orb101_update_data(defaultBranch: 'stable'),
        ))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('app.source_switch_failed');
        });

        expect($this->fixture->app->refresh()->default_branch)
            ->toBe('main')
            ->and($this->fixture->defaultInstance->refresh()->branch)
            ->toBe('main')
            ->and(AppUpdate::query()->sole()->status)
            ->toBe(AppUpdateStatus::RolledBack);
    });

    it('resumes an identical interrupted default-branch retry without mixed state', function (): void {
        $first = app(UpdateAppAction::class)->execute(
            $this->fixture->app,
            orb101_update_data(defaultBranch: 'stable'),
        );

        expect($first->default_branch)->toBe('stable');

        AppUpdate::query()->latest('id')->first()?->update([
            'status' => AppUpdateStatus::Prepared,
        ]);

        $second = app(UpdateAppAction::class)->execute(
            $this->fixture->app->refresh(),
            orb101_update_data(defaultBranch: 'stable'),
        );

        expect($second->default_branch)
            ->toBe('stable')
            ->and($this->fixture->defaultInstance->refresh()->branch)
            ->toBe('stable')
            ->and($this->fixture->sources->switchedInstances)
            ->toHaveCount(1);
    });

    it('resumes an access-URL retry from recorded origin evidence without repeating Git mutation', function (): void {
        $url = 'https://github.com/acme/site.git';
        app(UpdateAppAction::class)->execute(
            $this->fixture->app,
            orb101_update_data(repositoryUrl: $url),
        );

        expect($this->fixture->sources->originMutations)->toBe(['/srv/orbit/apps/acme/default']);

        AppUpdate::query()->latest('id')->first()?->update([
            'status' => AppUpdateStatus::Prepared,
        ]);

        app(UpdateAppAction::class)->execute(
            $this->fixture->app->refresh(),
            orb101_update_data(repositoryUrl: $url),
        );

        expect($this->fixture->sources->originMutations)
            ->toBe(['/srv/orbit/apps/acme/default'])
            ->and($this->fixture->app->refresh()->repository_url)
            ->toBe($url);
    });

    it('rolls back changed origins and keeps the old App record authoritative', function (): void {
        $this->fixture->sources->failOriginChange = true;

        expect(fn () => app(UpdateAppAction::class)->execute(
            $this->fixture->app,
            orb101_update_data(repositoryUrl: 'https://github.com/acme/site.git'),
        ))->toThrow(ResourceOperationException::class);

        expect($this->fixture->app->refresh()->repository_url)
            ->toBe('git@github.com:acme/site.git')
            ->and($this->fixture->app->repository_identity)
            ->toBe('github.com/acme/site')
            ->and(AppUpdate::query()->sole()->status)
            ->toBe(AppUpdateStatus::RolledBack);
    });

    it('rolls back slug Route runtime and Laravel URL changes before publication', function (): void {
        $this->fixture->defaultInstance->environmentValues()->create([
            'env_key' => 'APP_URL',
            'env_value' => 'https://acme.test',
        ]);
        $this->fixture->projections->failSlugPrepare = true;
        $oldRouteId = $this->fixture->defaultRoute->id;

        expect(fn () => app(UpdateAppAction::class)->execute(
            $this->fixture->app,
            orb101_update_data(slug: 'shop'),
        ))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('app.slug_prepare_failed');
        });

        expect($this->fixture->app->refresh()->slug)
            ->toBe('acme')
            ->and($this->fixture->defaultRoute->refresh()->id)
            ->toBe($oldRouteId)
            ->and($this->fixture->defaultRoute->domain)
            ->toBe('acme.test')
            ->and(AppInstanceEnvironmentValue::query()->where('env_key', 'APP_URL')->value('env_value'))
            ->toBe('https://acme.test')
            ->and(AppUpdate::query()->sole()->status)
            ->toBe(AppUpdateStatus::RolledBack);
    });

    it('completes a slug update when Laravel application configuration errors', function (): void {
        $this->fixture->projections->applicationErrorOnUrl = true;

        $app = app(UpdateAppAction::class)->execute(
            $this->fixture->app,
            orb101_update_data(slug: 'shop'),
        );

        expect($app->slug)
            ->toBe('shop')
            ->and(Route::query()->where('app_id', $app->id)->value('domain'))
            ->toBe('shop.test')
            ->and(AppUpdate::query()->latest('id')->first()?->status)
            ->toBe(AppUpdateStatus::Complete);
    });

    it('reconciles inherited web roots without deploying production', function (): void {
        $override = AppInstance::query()->create([
            'app_id' => $this->fixture->app->id,
            'node_id' => $this->fixture->node->id,
            'name' => 'docs',
            'environment' => 'development',
            'source_layout' => AppInstanceSourceLayout::Checkout->value,
            'checkout_path' => '/srv/orbit/apps/acme/docs',
            'root' => 'docs/public',
            'branch' => 'main',
            'status' => AppInstanceState::Active,
        ]);
        $production = AppInstance::query()->create([
            'app_id' => $this->fixture->app->id,
            'node_id' => $this->fixture->node->id,
            'name' => 'prod',
            'environment' => 'production',
            'source_layout' => 'release',
            'checkout_path' => '/srv/acme/releases/20260915',
            'production_home' => '/srv/acme',
            'root' => null,
            'branch' => 'release',
            'deployment_branch' => 'release',
            'starting_commit' => str_repeat('d', 40),
            'status' => AppInstanceState::Active,
        ]);

        app(UpdateAppAction::class)->execute(
            $this->fixture->app,
            orb101_update_data(root: 'web/public'),
        );

        expect($this->fixture->app->refresh()->root)
            ->toBe('web/public')
            ->and($override->refresh()->root)
            ->toBe('docs/public')
            ->and($production->refresh()->deployment_branch)
            ->toBe('release')
            ->and($production->checkout_path)
            ->toBe('/srv/acme/releases/20260915')
            ->and($this->fixture->projections->runtimeProjections)
            ->toContain([
                'instance_id' => $this->fixture->defaultInstance->id,
                'root' => 'web/public',
                'validated' => true,
                'preserved_tuning' => true,
            ])
            ->and($this->fixture->projections->runtimeProjections)
            ->toContain([
                'instance_id' => $production->id,
                'root' => '/srv/acme/current/web/public',
                'validated' => true,
                'preserved_tuning' => true,
            ]);
    });

    it('refuses a conflicting update while one update is incomplete', function (): void {
        AppUpdate::query()->create([
            'app_id' => $this->fixture->app->id,
            'status' => AppUpdateStatus::Prepared,
            'fingerprint' => orb101_update_data(defaultBranch: 'stable')->fingerprint(),
            'requested_default_branch' => 'stable',
            'previous_slug' => 'acme',
            'previous_repository_url' => $this->fixture->app->repository_url,
            'previous_default_branch' => 'main',
            'previous_root' => 'public',
            'evidence' => [],
        ]);

        expect(fn () => app(UpdateAppAction::class)->execute(
            $this->fixture->app,
            orb101_update_data(slug: 'shop'),
        ))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('app.update_in_progress');
        });

        expect($this->fixture->app->refresh()->slug)->toBe('acme');
    });
});
