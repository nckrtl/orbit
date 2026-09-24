<?php

declare(strict_types=1);

use App\Actions\Apps\UpdateAppAction;
use App\Data\Apps\UpdateAppData;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Apps\AppRepositoryUpdatePlanner;
use App\Domain\Apps\AppUpdateSourceMutator;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Apps\RemoteAppUpdateSourceMutator;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\AppInstance;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\LocalShellSshExecutor;
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

describe('App repository reconciliation', function (): void {
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

        expect($planner->uniqueCheckoutPaths($plan['checkouts']))
            ->toBe(['/srv/orbit/apps/acme/default'])
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

    it('preflights the configured origin despite an insteadOf rule and still refuses a different origin', function (): void {
        $sandbox = sys_get_temp_dir().'/orbit-app-update-origin-'.Str::uuid();
        $checkout = "{$sandbox}/checkout";
        $git = static function (array $arguments): void {
            $result = new NativeProcessRunner()->run(new ProcessInvocation(['git', ...$arguments]));
            expect($result->succeeded())->toBeTrue($result->stderr);
        };
        $git(['init', '--bare', '--initial-branch=main', "{$sandbox}/current.git"]);
        $git(['init', '--bare', '--initial-branch=main', "{$sandbox}/proposed.git"]);
        $git(['init', '--initial-branch=main', $checkout]);
        $git(['-C', $checkout, '-c', 'user.name=Orbit Test', '-c', 'user.email=orbit@example.test', 'commit', '--allow-empty', '-m', 'Initial']);
        $git(['-C', $checkout, 'remote', 'add', 'origin', "{$sandbox}/current.git"]);
        $git(['-C', $checkout, 'config', "url.{$sandbox}/./.insteadOf", "{$sandbox}/"]);
        $this->fixture->defaultInstance->update(['checkout_path' => $checkout]);
        $this->app->instance(SshExecutor::class, new LocalShellSshExecutor);
        $mutator = $this->app->make(RemoteAppUpdateSourceMutator::class);

        try {
            $mutator->preflightRepository(
                [$this->fixture->defaultInstance->refresh()],
                "{$sandbox}/current.git",
                "{$sandbox}/proposed.git",
            );

            $git(['-C', $checkout, 'remote', 'set-url', 'origin', "{$sandbox}/other.git"]);

            expect(fn () => $mutator->preflightRepository(
                [$this->fixture->defaultInstance->refresh()],
                "{$sandbox}/current.git",
                "{$sandbox}/proposed.git",
            ))->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('app.repository_preflight_failed');
            });
        } finally {
            new Filesystem()->deleteDirectory($sandbox);
        }
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
