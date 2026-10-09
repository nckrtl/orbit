<?php

declare(strict_types=1);

use App\Actions\Projects\UpdateProjectAction;
use App\Data\Projects\UpdateProjectData;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionRepositoryRecord;
use App\Domain\Projects\ProjectRepositoryUpdatePlanner;
use App\Domain\Projects\ProjectUpdateSourceMutator;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Projects\RemoteProjectUpdateSourceMutator;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProjectUpdate;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\Orb101ProjectUpdateFixture;
use Tests\Support\TaskWorkerSshExecutor;

beforeEach(function (): void {
    $this->fixture = Orb101ProjectUpdateFixture::bind($this);
});

describe('TaskCheckWorkerUser', function (): void {
    it('switches and restores Project branches as the managed user with no credential environment', function (): void {
        config()->set('orbit.tasks.worker_user', 'nobody');
        $checkout = sys_get_temp_dir().'/orbit-project-worker-'.bin2hex(random_bytes(6));
        $git = static fn (array $args): string => (new Process(['git', '-C', $checkout, '-c', 'user.name=t', '-c', 'user.email=t@t', ...$args]))->mustRun()->getOutput();
        (new Process(['git', 'init', '-q', '-b', 'main', $checkout]))->mustRun();
        file_put_contents($checkout.'/.gitattributes', "readme filter=uid\n");
        file_put_contents($checkout.'/readme', 'main');
        $git(['add', '.']);
        $git(['commit', '-qm', 'main']);
        $git(['checkout', '-qb', 'other']);
        file_put_contents($checkout.'/readme', 'other');
        $git(['commit', '-qam', 'other']);
        $git(['remote', 'add', 'origin', $checkout]);
        $git(['update-ref', 'refs/remotes/origin/other', 'HEAD']);
        $git(['checkout', '-q', 'main']);
        foreach (['clean', 'smudge'] as $filter) {
            $git(['config', 'filter.uid.'.$filter, 'printf "%s:%s\\n" "$(id -u)" "${GIT_CONFIG_VALUE_0-absent}" >> .git/filter-users; cat']);
        }
        $transport = TaskWorkerSshExecutor::forCheckout($checkout);
        (new Process(['setfacl', '-R', '-m', 'u:nobody:rwX,d:u:nobody:rwX,d:u:'.posix_geteuid().':rwX', $checkout]))->mustRun();
        $this->fixture->defaultInstance->update(['checkout_path' => $checkout]);
        $this->app->instance(SshExecutor::class, $transport);
        $mutator = $this->app->make(RemoteProjectUpdateSourceMutator::class);

        try {
            $mutator->switchDefaultBranch($this->fixture->defaultInstance, 'other');
            expect(file_get_contents($checkout.'/readme'))->toBe('other');
            $mutator->restoreDefaultBranch($this->fixture->defaultInstance, 'main');
            expect(file_get_contents($checkout.'/readme'))->toBe('main');
            $users = file($checkout.'/.git/filter-users', FILE_IGNORE_NEW_LINES) ?: [];
            expect($users)->not->toBeEmpty();
            foreach ($users as $user) {
                expect($user)->toBe(posix_geteuid().':absent');
            }
            expect(fileowner($checkout.'/readme'))->toBe(posix_geteuid());
        } finally {
            new Filesystem()->deleteDirectory($checkout);
        }
    });
});

function orb101_repository_data(string $url): UpdateProjectData
{
    return new UpdateProjectData(
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
        $worktree = Instance::query()->create([
            'project_id' => $this->fixture->project->id,
            'node_id' => $this->fixture->node->id,
            'name' => 'feature',
            'environment' => 'development',
            'source_layout' => InstanceSourceLayout::Worktree->value,
            'checkout_path' => '/srv/orbit/apps/acme/feature',
            'registration_common_repository_path' => '/srv/orbit/apps/acme/default',
            'branch' => 'feature',
            'status' => InstanceState::Active,
        ]);
        $planner = new ProjectRepositoryUpdatePlanner;
        $plan = $planner->inventory($this->fixture->project->instances()->get());

        expect($planner->uniqueCheckoutPaths($plan['checkouts']))
            ->toBe(['/srv/orbit/apps/acme/default'])
            ->and($planner->ownedCheckout($plan['checkouts'], $worktree)?->id)
            ->toBe($this->fixture->defaultInstance->id);

        app(UpdateProjectAction::class)->execute(
            $this->fixture->project,
            orb101_repository_data('https://github.com/acme/site.git'),
        );

        expect($this->fixture->sources->originMutations)
            ->toBe(['/srv/orbit/apps/acme/default'])
            ->and($this->fixture->sources->originMutations)
            ->not
            ->toContain('/srv/orbit/apps/acme/feature');
    });

    it('refuses a worktree whose common repository no Orbit-owned checkout owns', function (): void {
        Instance::query()->create([
            'project_id' => $this->fixture->project->id,
            'node_id' => $this->fixture->node->id,
            'name' => 'orphan',
            'environment' => 'development',
            'source_layout' => InstanceSourceLayout::Worktree->value,
            'checkout_path' => '/srv/orbit/apps/acme/orphan',
            'registration_common_repository_path' => '/unmanaged/repo',
            'branch' => 'main',
            'status' => InstanceState::Active,
        ]);

        expect(fn () => app(UpdateProjectAction::class)->execute(
            $this->fixture->project,
            orb101_repository_data('https://github.com/acme/site.git'),
        ))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('project.repository_unowned_common');
        });

        expect($this->fixture->sources->originMutations)->toBe([]);
    });

    it('refuses before mutation when the complete source set cannot preflight', function (): void {
        $this->fixture->sources->refuseRepositoryPreflight = true;

        expect(fn () => app(UpdateProjectAction::class)->execute(
            $this->fixture->project,
            orb101_repository_data('https://github.com/acme/site.git'),
        ))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('project.repository_preflight_failed');
        });

        expect($this->fixture->project->refresh()->repository_url)
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
        $mutator = $this->app->make(RemoteProjectUpdateSourceMutator::class);

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
                expect($exception->errorCode)->toBe('project.repository_preflight_failed');
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
        $mutator = $this->app->make(RemoteProjectUpdateSourceMutator::class);
        $this->app->instance(ProjectUpdateSourceMutator::class, $mutator);

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

    it('re-binds every production home when the URL moves from SSH to HTTPS', function (): void {
        $production = orb101_production_instance($this->fixture);
        $ssh = 'git@github.com:acme/site.git';
        $https = 'https://github.com/acme/site.git';
        $record = new ProductionRepositoryRecord($ssh, $ssh, ['initial' => $ssh, '20261009-a' => $ssh]);
        $this->fixture->production->records[$production->id] = $record;

        app(UpdateProjectAction::class)->execute($this->fixture->project, orb101_repository_data($https));

        expect($this->fixture->project->refresh()->repository_url)->toBe($https)
            ->and($this->fixture->production->rebinds)->toEqual([[$production->id, $record, $record->withRepository($https)]])
            ->and($this->fixture->production->records[$production->id]->boundTo($https))->toBeTrue();
    });

    it('refuses a different repository while a production home records the old one', function (): void {
        $production = orb101_production_instance($this->fixture);
        $ssh = 'git@github.com:acme/site.git';
        $this->fixture->production->records[$production->id] = new ProductionRepositoryRecord($ssh, $ssh, ['initial' => $ssh]);

        expect(fn () => app(UpdateProjectAction::class)->execute(
            $this->fixture->project,
            orb101_repository_data('https://github.com/acme/other.git'),
        ))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('project.production_repository_mismatch');
        });

        expect($this->fixture->project->refresh()->repository_url)->toBe($ssh)
            ->and($this->fixture->production->rebinds)->toBe([])
            ->and($this->fixture->sources->originMutations)->toBe([]);
    });

    it('repairs a home that an earlier update left on the old URL when the current URL is sent again', function (): void {
        $production = orb101_production_instance($this->fixture);
        $ssh = 'git@github.com:acme/site.git';
        $https = 'https://github.com/acme/site.git';
        $this->fixture->project->update(['repository_url' => $https]);
        $record = new ProductionRepositoryRecord($ssh, $ssh, ['initial' => $ssh]);
        $this->fixture->production->records[$production->id] = $record;

        app(UpdateProjectAction::class)->execute($this->fixture->project->refresh(), orb101_repository_data($https));

        expect($this->fixture->production->rebinds)->toEqual([[$production->id, $record, $record->withRepository($https)]])
            ->and($this->fixture->sources->originMutations)->toBe([])
            ->and(ProjectUpdate::query()->latest('id')->first()?->status->value)->toBe('complete');

        // A home that already records the URL needs no rewrite.
        app(UpdateProjectAction::class)->execute($this->fixture->project->refresh(), orb101_repository_data($https));

        expect($this->fixture->production->rebinds)->toHaveCount(1);
    });

    it('restores production homes when the update rolls back', function (): void {
        $production = orb101_production_instance($this->fixture);
        $ssh = 'git@github.com:acme/site.git';
        $https = 'https://github.com/acme/site.git';
        $record = new ProductionRepositoryRecord($ssh, null, ['initial' => $ssh]);
        $this->fixture->production->records[$production->id] = $record;
        $this->fixture->production->failRebind = true;

        expect(fn () => app(UpdateProjectAction::class)->execute($this->fixture->project, orb101_repository_data($https)))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('project.production_rebind_failed');
            });

        expect($this->fixture->project->refresh()->repository_url)->toBe($ssh)
            ->and($this->fixture->production->rebinds)->toHaveCount(2)
            ->and($this->fixture->production->rebinds[1])->toEqual([$production->id, $record->withRepository($https), $record])
            ->and(ProjectUpdate::query()->latest('id')->first()?->status->value)->toBe('rolling_back');
    });
});

function orb101_production_instance(Orb101ProjectUpdateFixture $fixture): Instance
{
    $node = Node::query()->create([
        'name' => 'app-prod',
        'status' => 'active',
        'public_ssh_host' => '192.0.2.81',
        'wireguard_ip' => '10.44.0.81',
    ]);
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);

    return Instance::query()->create([
        'project_id' => $fixture->project->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'source_layout' => 'release',
        'checkout_path' => '/home/orbit-app-72/releases/initial',
        'production_home' => '/home/orbit-app-72',
        'production_user' => 'orbit-app-72',
        'branch' => 'main',
        'status' => InstanceState::Active,
    ]);
}
