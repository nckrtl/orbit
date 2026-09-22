<?php

declare(strict_types=1);

use App\Actions\Apps\UpdateAppAction;
use App\Data\Apps\UpdateAppData;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Apps\AppRepositoryUpdatePlanner;
use App\Domain\Apps\AppUpdateSourceMutator;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Apps\RemoteAppUpdateSourceMutator;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppUpdate;
use App\Models\Node;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\Orb101AppUpdateFixture;

beforeEach(function (): void {
    $this->fixture = Orb101AppUpdateFixture::bind($this);
});

function orb101_repository_data(string $url): UpdateAppData
{
    return new UpdateAppData(
        typeProvided: false,
        type: null,
        slugProvided: false,
        slug: null,
        repositoryUrlProvided: true,
        repositoryUrl: $url,
        defaultBranchProvided: false,
        defaultBranch: null,
        rootProvided: false,
        root: null,
    );
}

function orb101_repository_checkout(
    Orb101AppUpdateFixture $fixture,
    string $name,
    ?OrbitApp $app = null,
): AppInstance {
    $address = 80 + Node::query()->count();
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => "192.0.2.{$address}",
        'wireguard_ip' => "10.44.0.{$address}",
    ]);
    $checkout = $fixture->defaultInstance->replicate(['name']);
    $checkout->fill(['app_id' => ($app ?? $fixture->app)->id, 'node_id' => $node->id, 'name' => $name]);
    $checkout->save();

    return $checkout;
}

/** @return array{app_id?: int, instance_id?: int, node_id?: int, path: string, previous_url: string, current_url: string, mutated: bool} */
function orb101_origin_evidence(AppInstance $checkout, bool $qualified = true): array
{
    return [
        ...($qualified ? [
            'app_id' => $checkout->app_id,
            'instance_id' => $checkout->id,
            'node_id' => $checkout->node_id,
        ] : []),
        'path' => rtrim($checkout->checkout_path, '/'),
        'previous_url' => 'git@github.com:acme/site.git',
        'current_url' => 'https://github.com/acme/site.git',
        'mutated' => true,
    ];
}

describe('App repository reconciliation', function (): void {
    it('preflights and changes both node-owned checkouts when their paths match', function (): void {
        $secondNode = Node::query()->create([
            'name' => 'second-app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.81',
            'wireguard_ip' => '10.44.0.81',
        ]);
        $second = $this->fixture->defaultInstance->replicate(['name']);
        $second->fill(['node_id' => $secondNode->id, 'name' => 'second']);
        $second->save();
        $ssh = new AppDevFakeSshExecutor;
        app()->instance(SshExecutor::class, $ssh);
        app()->instance(AppUpdateSourceMutator::class, app(RemoteAppUpdateSourceMutator::class));

        app(UpdateAppAction::class)->execute(
            $this->fixture->app,
            orb101_repository_data('https://github.com/acme/site.git'),
        );

        expect(array_column($ssh->connections, 'host'))->toBe([
            '10.44.0.80',
            '10.44.0.81',
            '10.44.0.80',
            '10.44.0.81',
        ]);
        expect(array_column(AppUpdate::query()->sole()->evidence['origins'], 'instance_id'))
            ->toBe([$this->fixture->defaultInstance->id, $second->id]);
        expect(array_column(AppUpdate::query()->sole()->evidence['origins'], 'node_id'))
            ->toBe([$this->fixture->node->id, $secondNode->id]);
    });

    it('retries only the unfinished owner and restores each recorded Node', function (): void {
        $first = $this->fixture->defaultInstance;
        $second = orb101_repository_checkout($this->fixture, 'second');
        $ssh = new AppDevFakeSshExecutor;
        app()->instance(SshExecutor::class, $ssh);
        $mutator = app(RemoteAppUpdateSourceMutator::class);
        $evidence = [orb101_origin_evidence($first)];

        $evidence = $mutator->changeOrigins(
            [$first, $second],
            'git@github.com:acme/site.git',
            'https://github.com/acme/site.git',
            $evidence,
        );

        expect(array_column($ssh->connections, 'host'))->toBe(['10.44.0.81']);
        expect(array_column($evidence, 'instance_id'))->toBe([$first->id, $second->id]);

        $mutator->restoreOrigins($this->fixture->app, $evidence);

        expect(array_column($ssh->connections, 'host'))->toBe(['10.44.0.81', '10.44.0.80', '10.44.0.81']);
        expect($ssh->commands[1]->arguments)
            ->toBe(['bash', '-seu', '--', $first->checkout_path, 'git@github.com:acme/site.git']);
        expect($ssh->commands[2]->arguments)->toBe($ssh->commands[1]->arguments);
    });

    it('restores a qualified owner without touching an unrelated Project at the same path', function (): void {
        $unrelated = OrbitApp::query()->create([
            'name' => 'Unrelated',
            'slug' => 'unrelated',
            'repository_url' => 'https://github.com/other/unrelated.git',
        ]);
        $checkout = orb101_repository_checkout($this->fixture, 'unrelated', $unrelated);
        $ssh = new AppDevFakeSshExecutor;
        app()->instance(SshExecutor::class, $ssh);

        app(RemoteAppUpdateSourceMutator::class)->restoreOrigins($unrelated, [orb101_origin_evidence($checkout)]);

        expect(array_column($ssh->connections, 'host'))->toBe(['10.44.0.81']);
        expect($ssh->commands)->toHaveCount(1);
    });

    it('normalizes a uniquely owned legacy origin without repeating its mutation', function (): void {
        $first = $this->fixture->defaultInstance;
        $unrelated = OrbitApp::query()->create([
            'name' => 'Unrelated',
            'slug' => 'unrelated',
            'repository_url' => 'https://github.com/other/unrelated.git',
        ]);
        orb101_repository_checkout($this->fixture, 'unrelated', $unrelated);
        $legacy = orb101_origin_evidence($first, qualified: false);
        $legacy['path'] .= '/';
        $ssh = new AppDevFakeSshExecutor;
        app()->instance(SshExecutor::class, $ssh);
        $mutator = app(RemoteAppUpdateSourceMutator::class);

        $evidence = $mutator->changeOrigins(
            [$first],
            'git@github.com:acme/site.git',
            'https://github.com/acme/site.git',
            [$legacy],
        );

        expect($evidence)->toBe([orb101_origin_evidence($first)]);
        expect($ssh->commands)->toBe([]);

        $mutator->restoreOrigins($this->fixture->app, [$legacy]);

        expect(array_column($ssh->connections, 'host'))->toBe(['10.44.0.80']);
        expect($ssh->commands[0]->arguments[3])->toBe($first->checkout_path);
    });

    it('refuses ambiguous legacy ownership before any remote operation', function (string $operation): void {
        $first = $this->fixture->defaultInstance;
        $second = orb101_repository_checkout($this->fixture, 'second');
        $legacy = [orb101_origin_evidence($first, qualified: false)];
        $ssh = new AppDevFakeSshExecutor;
        app()->instance(SshExecutor::class, $ssh);
        $mutator = app(RemoteAppUpdateSourceMutator::class);

        expect(fn () => $operation === 'restore'
            ? $mutator->restoreOrigins($this->fixture->app, $legacy)
            : $mutator->changeOrigins([$first, $second], 'old', 'new', $legacy))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('app.repository_origin_owner_changed');
            });

        expect($ssh->commands)->toBe([]);
    })->with(['retry' => 'change', 'restore' => 'restore']);

    it('validates every recorded owner before restoring any origin', function (string $change): void {
        $first = $this->fixture->defaultInstance;
        $second = orb101_repository_checkout($this->fixture, 'second');
        $evidence = [orb101_origin_evidence($first), orb101_origin_evidence($second)];

        match ($change) {
            'node' => $second->update(['node_id' => Node::query()->create([
                'name' => 'replacement-node',
                'status' => LifecycleStatus::Active,
                'public_ssh_host' => '192.0.2.82',
                'wireguard_ip' => '10.44.0.82',
            ])->id]),
            'path' => $second->update(['checkout_path' => '/srv/moved']),
            'project' => $second->update(['app_id' => OrbitApp::query()->create([
                'name' => 'Replacement',
                'slug' => 'replacement',
                'repository_url' => 'https://github.com/other/replacement.git',
            ])->id]),
            'layout' => $second->update(['source_layout' => AppInstanceSourceLayout::Worktree->value]),
            'production' => $second->update(['environment' => 'production']),
            'deleted' => $second->delete(),
        };
        $ssh = new AppDevFakeSshExecutor;
        app()->instance(SshExecutor::class, $ssh);

        expect(fn () => app(RemoteAppUpdateSourceMutator::class)->restoreOrigins($this->fixture->app, $evidence))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('app.repository_origin_owner_changed');
            });

        expect($ssh->commands)->toBe([]);
    })->with(['node', 'path', 'project', 'layout', 'production', 'deleted']);

    it('refuses missing legacy ownership without restoring an earlier valid origin', function (): void {
        $first = $this->fixture->defaultInstance;
        $missing = orb101_origin_evidence($first, qualified: false);
        $missing['path'] = '/srv/no-longer-owned';
        $ssh = new AppDevFakeSshExecutor;
        app()->instance(SshExecutor::class, $ssh);

        expect(fn () => app(RemoteAppUpdateSourceMutator::class)->restoreOrigins(
            $this->fixture->app,
            [orb101_origin_evidence($first), $missing],
        ))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('app.repository_origin_owner_changed');
        });

        expect($ssh->commands)->toBe([]);
    });

    it('deduplicates repeated references and normalizes trailing slashes within a Node', function (): void {
        $checkout = $this->fixture->defaultInstance;
        $path = $checkout->checkout_path;
        $checkout->update(['checkout_path' => $path.'/']);
        $ssh = new AppDevFakeSshExecutor;
        app()->instance(SshExecutor::class, $ssh);
        $mutator = app(RemoteAppUpdateSourceMutator::class);

        $mutator->preflightRepository([$checkout, $checkout->fresh()], 'old', 'new');
        $evidence = $mutator->changeOrigins([$checkout, $checkout->fresh()], 'old', 'new', []);

        expect(array_column($ssh->connections, 'host'))->toBe(['10.44.0.80', '10.44.0.80']);
        expect($ssh->commands[0]->arguments[3])->toBe($path);
        expect($ssh->commands[1]->arguments[3])->toBe($path);
        expect($evidence)->toHaveCount(1)->and($evidence[0]['path'])->toBe($path);
    });

    it('refuses stale checkout selection before preflight or mutation', function (string $operation): void {
        $checkout = $this->fixture->defaultInstance;
        AppInstance::query()->whereKey($checkout->id)->update(['checkout_path' => '/srv/moved']);
        $ssh = new AppDevFakeSshExecutor;
        app()->instance(SshExecutor::class, $ssh);
        $mutator = app(RemoteAppUpdateSourceMutator::class);

        expect(fn () => $operation === 'preflight'
            ? $mutator->preflightRepository([$checkout], 'old', 'new')
            : $mutator->changeOrigins([$checkout], 'old', 'new', []))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('app.repository_origin_owner_changed');
            });

        expect($ssh->commands)->toBe([]);
    })->with(['preflight', 'change']);

    it('refuses conflicting canonical checkout owners on the same Node', function (): void {
        $first = $this->fixture->defaultInstance;
        $alias = $first->replicate(['name']);
        $alias->fill(['name' => 'alias', 'checkout_path' => $first->checkout_path.'/']);
        $alias->save();
        $ssh = new AppDevFakeSshExecutor;
        app()->instance(SshExecutor::class, $ssh);

        expect(fn () => app(RemoteAppUpdateSourceMutator::class)->changeOrigins([$first, $alias], 'old', 'new', []))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('app.repository_origin_owner_changed');
            });

        expect($ssh->commands)->toBe([]);
    });

    it('refuses incomplete or conflicting qualified evidence before restoration', function (string $corruption): void {
        $checkout = $this->fixture->defaultInstance;
        $row = orb101_origin_evidence($checkout);

        if ($corruption === 'partial') {
            unset($row['node_id']);
            $evidence = [$row];
        } else {
            $conflict = $row;
            $conflict['previous_url'] = 'https://github.com/other/repository.git';
            $evidence = [$row, $conflict];
        }
        $ssh = new AppDevFakeSshExecutor;
        app()->instance(SshExecutor::class, $ssh);

        expect(fn () => app(RemoteAppUpdateSourceMutator::class)->restoreOrigins($this->fixture->app, $evidence))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('app.repository_origin_owner_changed');
            });

        expect($ssh->commands)->toBe([]);
    })->with(['partial identity' => 'partial', 'conflicting rows' => 'conflict']);

    it('leaves linked worktrees and production sources outside native origin updates', function (): void {
        $checkout = $this->fixture->defaultInstance;
        $worktree = $checkout->replicate(['name']);
        $worktree->fill([
            'name' => 'worktree',
            'checkout_path' => '/srv/orbit/apps/acme/worktree',
            'source_layout' => AppInstanceSourceLayout::Worktree->value,
            'registration_common_repository_path' => $checkout->checkout_path.'/.git',
        ]);
        $worktree->save();
        $production = orb101_repository_checkout($this->fixture, 'production');
        $production->update(['environment' => 'production']);
        $ssh = new AppDevFakeSshExecutor;
        app()->instance(SshExecutor::class, $ssh);
        app()->instance(AppUpdateSourceMutator::class, app(RemoteAppUpdateSourceMutator::class));

        app(UpdateAppAction::class)->execute(
            $this->fixture->app,
            orb101_repository_data('https://github.com/acme/site.git'),
        );

        expect(array_column($ssh->connections, 'host'))->toBe(['10.44.0.80', '10.44.0.80']);
        expect(array_column(AppUpdate::query()->sole()->evidence['origins'], 'instance_id'))->toBe([$checkout->id]);
        expect($ssh->commands[0]->arguments[3])->toBe($checkout->checkout_path);
        expect($ssh->commands[1]->arguments[3])->toBe($checkout->checkout_path);
    });

    it('changes origin once per Orbit-owned checkout and never mutates a worktree common repository', function (): void {
        $worktree = AppInstance::query()->create([
            'app_id' => $this->fixture->app->id,
            'node_id' => $this->fixture->node->id,
            'name' => 'feature',
            'environment' => 'development',
            'source_layout' => AppInstanceSourceLayout::Worktree->value,
            'checkout_path' => '/srv/orbit/apps/acme/feature',
            'registration_common_repository_path' => '/srv/orbit/apps/acme/default',
            'branch' => 'feature',
            'status' => AppInstanceState::Active,
        ]);
        $planner = new AppRepositoryUpdatePlanner;
        $plan = $planner->inventory($this->fixture->app->appInstances()->get());

        expect(array_column($plan['checkouts'], 'id'))
            ->toBe([$this->fixture->defaultInstance->id])
            ->and($planner->ownedCheckout($plan['checkouts'], $worktree)?->id)
            ->toBe($this->fixture->defaultInstance->id);

        app(UpdateAppAction::class)->execute(
            $this->fixture->app,
            orb101_repository_data('https://github.com/acme/site.git'),
        );

        expect($this->fixture->sources->originMutations)
            ->toBe(['/srv/orbit/apps/acme/default'])
            ->and($this->fixture->sources->originMutations)
            ->not
            ->toContain('/srv/orbit/apps/acme/feature');
    });

    it('refuses a worktree whose common repository no Orbit-owned checkout owns', function (): void {
        AppInstance::query()->create([
            'app_id' => $this->fixture->app->id,
            'node_id' => $this->fixture->node->id,
            'name' => 'orphan',
            'environment' => 'development',
            'source_layout' => AppInstanceSourceLayout::Worktree->value,
            'checkout_path' => '/srv/orbit/apps/acme/orphan',
            'registration_common_repository_path' => '/unmanaged/repo',
            'branch' => 'main',
            'status' => AppInstanceState::Active,
        ]);

        expect(fn () => app(UpdateAppAction::class)->execute(
            $this->fixture->app,
            orb101_repository_data('https://github.com/acme/site.git'),
        ))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('app.repository_unowned_common');
        });

        expect($this->fixture->sources->originMutations)->toBe([]);
    });

    it('refuses before mutation when the complete source set cannot preflight', function (): void {
        $this->fixture->sources->refuseRepositoryPreflight = true;

        expect(fn () => app(UpdateAppAction::class)->execute(
            $this->fixture->app,
            orb101_repository_data('https://github.com/acme/site.git'),
        ))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('app.repository_preflight_failed');
        });

        expect($this->fixture->app->refresh()->repository_url)
            ->toBe('git@github.com:acme/site.git')
            ->and($this->fixture->sources->originMutations)
            ->toBe([]);
    });

    it('runs remote origin updates only against checkout paths', function (): void {
        $ssh = new AppDevFakeSshExecutor([
            new CommandResult(0, '', '', 1, false),
            new CommandResult(0, 'https://github.com/acme/site.git', '', 1, false),
        ]);
        $this->app->instance(SshExecutor::class, $ssh);
        $mutator = $this->app->make(RemoteAppUpdateSourceMutator::class);
        $this->app->instance(AppUpdateSourceMutator::class, $mutator);

        $mutator->changeOrigins(
            [$this->fixture->defaultInstance],
            'git@github.com:acme/site.git',
            'https://github.com/acme/site.git',
            [],
        );

        expect($ssh->commands)->toHaveCount(1);
        expect($ssh->commands[0]->arguments)
            ->toContain('/srv/orbit/apps/acme/default')
            ->toContain('https://github.com/acme/site.git')
            ->and($ssh->commands[0]->input)
            ->toContain('remote set-url origin')
            ->and($ssh->commands[0]->input)
            ->toContain('test ! -f "$path/.git"');
    });
});
