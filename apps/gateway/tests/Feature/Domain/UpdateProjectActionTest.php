<?php

declare(strict_types=1);

use App\Actions\Projects\UpdateProjectAction;
use App\Data\Projects\UpdateProjectData;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Instances\DevelopmentInstanceConfigurator;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceEnvironmentWriteResult;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectUpdateProjectionMutator;
use App\Domain\Projects\ProjectUpdateStatus;
use App\Domain\Routes\RouteDomainProjector;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Projects\NativeProjectUpdateProjectionMutator;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\ProjectUpdate;
use App\Models\Route;
use Tests\Support\Orb101ProjectUpdateFixture;

beforeEach(function (): void {
    $this->fixture = Orb101ProjectUpdateFixture::bind($this);
});

function orb101_update_data(
    ?string $slug = null,
    ?string $repositoryUrl = null,
    ?string $defaultBranch = null,
    ?string $root = null,
): UpdateProjectData {
    return new UpdateProjectData(
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

describe('UpdateProjectAction', function (): void {
    it('preserves an explicit default-instance branch selection that matched the old default', function (): void {
        $this->fixture->defaultInstance->update(['branch_override' => 'main']);

        app(UpdateProjectAction::class)->execute(
            $this->fixture->project,
            orb101_update_data(defaultBranch: 'stable'),
        );

        expect($this->fixture->project->refresh()->default_branch)
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

        expect(fn () => app(UpdateProjectAction::class)->execute(
            $this->fixture->project,
            orb101_update_data(defaultBranch: 'stable'),
        ))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('project.source_switch_failed');
        });

        expect($this->fixture->project->refresh()->default_branch)
            ->toBe('main')
            ->and($this->fixture->defaultInstance->refresh()->branch)
            ->toBe('main')
            ->and(ProjectUpdate::query()->sole()->status)
            ->toBe(ProjectUpdateStatus::RolledBack);
    });

    it('resumes an identical interrupted default-branch retry without mixed state', function (): void {
        $first = app(UpdateProjectAction::class)->execute(
            $this->fixture->project,
            orb101_update_data(defaultBranch: 'stable'),
        );

        expect($first->default_branch)->toBe('stable');

        ProjectUpdate::query()->latest('id')->first()?->update([
            'status' => ProjectUpdateStatus::Prepared,
        ]);

        $second = app(UpdateProjectAction::class)->execute(
            $this->fixture->project->refresh(),
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
        app(UpdateProjectAction::class)->execute(
            $this->fixture->project,
            orb101_update_data(repositoryUrl: $url),
        );

        expect($this->fixture->sources->originMutations)->toBe(['/srv/orbit/apps/acme/default']);

        ProjectUpdate::query()->latest('id')->first()?->update([
            'status' => ProjectUpdateStatus::Prepared,
        ]);

        app(UpdateProjectAction::class)->execute(
            $this->fixture->project->refresh(),
            orb101_update_data(repositoryUrl: $url),
        );

        expect($this->fixture->sources->originMutations)
            ->toBe(['/srv/orbit/apps/acme/default'])
            ->and($this->fixture->project->refresh()->repository_url)
            ->toBe($url);
    });

    it('rolls back changed origins and keeps the old App record authoritative', function (): void {
        $this->fixture->sources->failOriginChange = true;

        expect(fn () => app(UpdateProjectAction::class)->execute(
            $this->fixture->project,
            orb101_update_data(repositoryUrl: 'https://github.com/acme/site.git'),
        ))->toThrow(ResourceOperationException::class);

        expect($this->fixture->project->refresh()->repository_url)
            ->toBe('git@github.com:acme/site.git')
            ->and($this->fixture->project->repository_identity)
            ->toBe('github.com/acme/site')
            ->and(ProjectUpdate::query()->sole()->status)
            ->toBe(ProjectUpdateStatus::RolledBack);
    });

    it('rolls back slug Route runtime and Laravel URL changes before publication', function (): void {
        $this->fixture->defaultInstance->environmentValues()->create([
            'env_key' => 'APP_URL',
            'env_value' => 'https://acme.test',
        ]);
        $this->fixture->projections->failSlugPrepare = true;
        $oldRouteId = $this->fixture->defaultRoute->id;

        expect(fn () => app(UpdateProjectAction::class)->execute(
            $this->fixture->project,
            orb101_update_data(slug: 'shop'),
        ))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('project.slug_prepare_failed');
        });

        expect($this->fixture->project->refresh()->slug)
            ->toBe('acme')
            ->and($this->fixture->defaultRoute->refresh()->id)
            ->toBe($oldRouteId)
            ->and($this->fixture->defaultRoute->domain)
            ->toBe('acme.test')
            ->and(InstanceEnvironmentValue::query()->where('env_key', 'APP_URL')->value('env_value'))
            ->toBe('https://acme.test')
            ->and(ProjectUpdate::query()->sole()->status)
            ->toBe(ProjectUpdateStatus::RolledBack);
    });

    it('projects generated routes and retries a native slug projection failure per Instance', function (): void {
        $docs = Instance::query()->create([
            'project_id' => $this->fixture->project->id,
            'node_id' => $this->fixture->node->id,
            'name' => 'docs',
            'environment' => 'development',
            'source_layout' => InstanceSourceLayout::Checkout->value,
            'checkout_path' => '/srv/orbit/apps/acme/docs',
            'branch' => 'main',
            'starting_commit' => str_repeat('b', 40),
            'source_is_laravel' => true,
            'provisioning_step' => 'active',
            'status' => InstanceState::Active,
        ]);
        $this->fixture->defaultInstance->update([
            'source_is_laravel' => true,
            'provisioning_step' => 'active',
        ]);
        $this->fixture->defaultInstance->environmentValues()->create([
            'env_key' => 'APP_URL',
            'env_value' => 'https://acme.test',
        ]);
        $docs->environmentValues()->create([
            'env_key' => 'APP_URL',
            'env_value' => 'https://docs.acme.test',
        ]);
        $docsRoute = Route::query()->create([
            'project_id' => $this->fixture->project->id,
            'node_id' => $this->fixture->node->id,
            'generation_basis_node_id' => $this->fixture->node->id,
            'domain' => 'docs.acme.test',
            'provenance' => 'generated',
            'publication' => 'private',
        ]);
        $docsRoute->targets()->create(['instance_id' => $docs->id, 'position' => 0]);
        $docsRoute->update(['status' => 'active']);
        $basisNode = Node::query()->create([
            'name' => 'slug-targetless-basis',
            'status' => 'active',
            'public_ssh_host' => '192.0.2.81',
            'wireguard_ip' => '10.44.0.81',
            'tld' => 'preview',
        ]);
        $targetlessRoute = Route::query()->create([
            'project_id' => $this->fixture->project->id,
            'node_id' => $basisNode->id,
            'generation_basis_node_id' => $basisNode->id,
            'domain' => 'acme.preview',
            'provenance' => 'generated',
            'publication' => 'private',
        ]);
        $targetlessRouteId = $targetlessRoute->id;

        $routeProjection = Mockery::mock(RouteDomainProjector::class)->shouldIgnoreMissing();
        $renderedDomains = [];
        $routeProjection->shouldReceive('prepareWorkloadCaddy')->andReturnUsing(
            function (Instance $instance, Route $current, Route $candidate) use (&$renderedDomains): void {
                $sites = (new DevelopmentSiteRepository)->forNode($instance->node);
                expect($sites->pluck('domain'))->toContain($candidate->domain);
                $renderedDomains[] = $candidate->domain;
            },
        );
        app()->instance(RouteDomainProjector::class, $routeProjection);
        app()->instance(DevelopmentRouteProjector::class, Mockery::mock(DevelopmentRouteProjector::class)->shouldIgnoreMissing());
        app()->instance(DevelopmentProjectionOperationLock::class, Mockery::mock(DevelopmentProjectionOperationLock::class)
            ->shouldReceive('run')->andReturnUsing(fn (Closure $operation): mixed => $operation())->getMock());
        app()->instance(InstanceEnvironmentOperationLock::class, Mockery::mock(InstanceEnvironmentOperationLock::class)
            ->shouldReceive('run')->andReturnUsing(fn (array $ids, Closure $operation): mixed => $operation())->getMock());
        app()->instance(InstanceOperationPreflight::class, Mockery::mock(InstanceOperationPreflight::class)->shouldIgnoreMissing());
        app()->instance(InstanceEnvironmentWriter::class, Mockery::mock(InstanceEnvironmentWriter::class)
            ->shouldReceive('write')->andReturnUsing(
                function (InstanceEnvironmentContext $context, string $contents): InstanceEnvironmentWriteResult {
                    expect($context->routeDomain)
                        ->toBe(Route::query()->findOrFail($context->routeId)->domain)
                        ->toBeIn(['web.shop.test', 'web.docs.shop.test']);

                    return InstanceEnvironmentWriteResult::changed();
                },
            )->getMock());

        $failed = false;
        $configurator = Mockery::mock(DevelopmentInstanceConfigurator::class)->shouldIgnoreMissing();
        $configurator->shouldReceive('configureLaravelUrl')->andReturnUsing(
            function (Instance $instance, string $url) use ($docs, &$failed): void {
                if ($instance->is($docs) && ! $failed) {
                    $failed = true;
                    throw new RuntimeException('Laravel URL configuration failed.');
                }
            },
        );
        app()->instance(DevelopmentInstanceConfigurator::class, $configurator);
        app()->instance(ProjectUpdateProjectionMutator::class, app(NativeProjectUpdateProjectionMutator::class));

        $data = orb101_update_data(slug: 'shop');
        expect(fn () => app(UpdateProjectAction::class)->execute($this->fixture->project, $data))
            ->toThrow(function (ResourceOperationException $exception) use ($docs): void {
                expect($exception->errorCode)->toBe('project.slug_projection_failed')
                    ->and($exception->getMessage())->toContain((string) $docs->id);
            });

        expect($this->fixture->project->refresh()->slug)
            ->toBe('acme')
            ->and(ProjectUpdate::query()->latest('id')->value('status'))
            ->toBe(ProjectUpdateStatus::Publishing)
            ->and($renderedDomains)
            ->toContain('web.shop.test');

        app(UpdateProjectAction::class)->execute($this->fixture->project->refresh(), $data);

        expect($this->fixture->project->refresh()->slug)
            ->toBe('shop')
            ->and(Route::query()->where('project_id', $this->fixture->project->id)->where('status', 'active')->pluck('domain')->all())
            ->toBe(['web.shop.test', 'web.docs.shop.test'])
            ->and(Route::query()->whereKey($targetlessRouteId)->exists())
            ->toBeFalse()
            ->and(Route::query()->where('project_id', $this->fixture->project->id)->where('domain', 'web.shop.preview')->value('status'))
            ->toBe(RouteStatus::Pending)
            ->and(Route::query()->where('project_id', $this->fixture->project->id)->where('domain', 'web.shop.preview')->value('generation_basis_node_id'))
            ->toBe($basisNode->id)
            ->and(Route::query()->where('project_id', $this->fixture->project->id)->where('domain', 'web.shop.preview')->value('replacement_step'))
            ->toBeNull()
            ->and(Route::query()->where('project_id', $this->fixture->project->id)->where('domain', 'web.shop.preview')->firstOrFail()->targets()->exists())
            ->toBeFalse()
            ->and(Route::query()->where('project_id', $this->fixture->project->id)->whereNotNull('replaces_route_id')->count())
            ->toBe(0)
            ->and(Route::query()->where('project_id', $this->fixture->project->id)->where('status', 'pending')->count())
            ->toBe(1)
            ->and($renderedDomains)
            ->toContain('web.docs.shop.test');
    });

    it('reports slug projection failure without publishing a partial slug', function (): void {
        $this->fixture->projections->applicationErrorOnUrl = true;
        $oldRouteId = $this->fixture->defaultRoute->id;

        expect(fn () => app(UpdateProjectAction::class)->execute(
            $this->fixture->project,
            orb101_update_data(slug: 'shop'),
        ))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('project.slug_projection_failed')
                ->and($exception->getMessage())->toContain((string) $this->fixture->defaultInstance->id);
        });

        expect($this->fixture->project->refresh()->slug)
            ->toBe('acme')
            ->and($this->fixture->defaultRoute->refresh()->id)
            ->toBe($oldRouteId)
            ->and(Route::query()->where('project_id', $this->fixture->project->id)->value('domain'))
            ->toBe('acme.test')
            ->and(ProjectUpdate::query()->latest('id')->first()?->status)
            ->not->toBe(ProjectUpdateStatus::Complete);
    });

    it('reconciles inherited web roots without deploying production', function (): void {
        $override = Instance::query()->create([
            'project_id' => $this->fixture->project->id,
            'node_id' => $this->fixture->node->id,
            'name' => 'docs',
            'environment' => 'development',
            'source_layout' => InstanceSourceLayout::Checkout->value,
            'checkout_path' => '/srv/orbit/apps/acme/docs',
            'root' => 'docs/public',
            'branch' => 'main',
            'status' => InstanceState::Active,
        ]);
        $productionNode = Node::query()->create([
            'name' => 'app-prod',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.81',
            'wireguard_ip' => '10.44.0.81',
        ]);
        $productionNode->roles()->create([
            'role' => RoleName::AppProd,
            'status' => LifecycleStatus::Active,
        ]);
        $production = Instance::query()->create([
            'project_id' => $this->fixture->project->id,
            'node_id' => $productionNode->id,
            'name' => 'prod',
            'environment' => 'production',
            'source_layout' => 'release',
            'checkout_path' => '/srv/acme/releases/20260915',
            'production_home' => '/srv/acme',
            'root' => null,
            'branch' => 'release',
            'deployment_branch' => 'release',
            'starting_commit' => str_repeat('d', 40),
            'status' => InstanceState::Active,
        ]);

        app(UpdateProjectAction::class)->execute(
            $this->fixture->project,
            orb101_update_data(root: 'web/public'),
        );

        expect($this->fixture->project->refresh()->root)
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
        ProjectUpdate::query()->create([
            'project_id' => $this->fixture->project->id,
            'status' => ProjectUpdateStatus::Prepared,
            'fingerprint' => orb101_update_data(defaultBranch: 'stable')->fingerprint(),
            'requested_default_branch' => 'stable',
            'previous_slug' => 'acme',
            'previous_repository_url' => $this->fixture->project->repository_url,
            'previous_default_branch' => 'main',
            'previous_root' => 'public',
            'evidence' => [],
        ]);

        expect(fn () => app(UpdateProjectAction::class)->execute(
            $this->fixture->project,
            orb101_update_data(slug: 'shop'),
        ))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('project.update_in_progress');
        });

        expect($this->fixture->project->refresh()->slug)->toBe('acme');
    });
});
