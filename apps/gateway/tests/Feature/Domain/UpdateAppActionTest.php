<?php

declare(strict_types=1);

use App\Actions\Apps\UpdateAppAction;
use App\Data\Apps\UpdateAppData;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Apps\AppUpdateSourceMutator;
use App\Domain\Apps\AppUpdateStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Apps\RemoteAppUpdateSourceMutator;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceEnvironmentValue;
use App\Models\AppUpdate;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;
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

function orb101_preflighted_source_update(Orb101AppUpdateFixture $fixture, UpdateAppData $data): AppUpdate
{
    $instance = $fixture->defaultInstance;

    return AppUpdate::query()->create([
        'app_id' => $fixture->app->id,
        'status' => AppUpdateStatus::Preflighted,
        'fingerprint' => $data->fingerprint(),
        'requested_repository_url' => $data->repositoryUrl,
        'requested_default_branch' => $data->defaultBranch,
        'previous_slug' => $fixture->app->slug,
        'previous_repository_url' => $fixture->app->repository_url,
        'previous_default_branch' => $fixture->app->default_branch,
        'previous_root' => $fixture->app->root,
        'inventory' => [
            'source_owners' => [$instance->id => [
                'app_id' => $instance->app_id,
                'instance_id' => $instance->id,
                'node_id' => $instance->node_id,
                'path' => $instance->checkout_path,
                'source_layout' => $instance->source_layout,
                'branch' => $instance->branch,
            ]],
            'inherited_defaults' => $data->defaultBranchProvided ? [$instance->id] : [],
            'repository' => ['checkout_ids' => $data->repositoryUrlProvided ? [$instance->id] : []],
            'production' => [],
        ],
        'evidence' => [],
    ]);
}

describe('UpdateAppAction', function (): void {
    it('returns a real checkout to its original Git branch after later preparation fails', function (bool $matchingFile): void {
        $directory = trim(new Process(['mktemp', '-d', sys_get_temp_dir().'/orbit-source-rollback-XXXXXX'])
            ->mustRun()->getOutput());

        try {
            new Process(['git', 'init', '--initial-branch=main', $directory])->mustRun();

            if ($matchingFile) {
                file_put_contents($directory.'/main', "A tracked path with the branch name.\n");
                new Process(['git', '-C', $directory, 'add', 'main'])->mustRun();
            }

            new Process([
                'git', '-C', $directory, '-c', 'user.name=Source recovery test',
                '-c', 'user.email=source-recovery@example.test', 'commit', '--allow-empty', '-m', 'Initial',
            ])->mustRun();
            new Process(['git', '-C', $directory, 'branch', 'stable'])->mustRun();
            new Process(['git', '-C', $directory, 'remote', 'add', 'origin', $directory])->mustRun();
            $this->fixture->defaultInstance->update(['checkout_path' => $directory]);
            $this->fixture->projections->failSlugPrepare = true;
            app()->instance(SshExecutor::class, new class implements SshExecutor
            {
                public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
                {
                    return new NativeProcessRunner()->run(new ProcessInvocation(
                        arguments: $command->arguments,
                        input: $command->input,
                        protectedInput: $command->protectedInput,
                    ));
                }
            });
            app()->instance(AppUpdateSourceMutator::class, app(RemoteAppUpdateSourceMutator::class));

            expect(fn () => app(UpdateAppAction::class)->execute(
                $this->fixture->app,
                orb101_update_data(slug: 'shop', defaultBranch: 'stable'),
            ))->toThrow(ResourceOperationException::class);

            expect(trim(new Process(['git', '-C', $directory, 'branch', '--show-current'])->mustRun()->getOutput()))
                ->toBe('main');
            expect($this->fixture->defaultInstance->refresh()->branch)->toBe('main');
            expect(AppUpdate::query()->sole()->status)->toBe(AppUpdateStatus::RolledBack);
        } finally {
            new Filesystem()->deleteDirectory($directory);
        }
    })->with(['branch only' => false, 'branch and matching path' => true]);

    it('refuses a resumed source update when its preflighted owner changed', function (string $source, string $change): void {
        $data = $source === 'branch'
            ? orb101_update_data(defaultBranch: 'stable')
            : orb101_update_data(repositoryUrl: 'https://github.com/acme/site.git');
        $update = orb101_preflighted_source_update($this->fixture, $data);
        $instance = $this->fixture->defaultInstance;
        match ($change) {
            'node' => $instance->update(['node_id' => Node::query()->create([
                'name' => 'moved', 'status' => LifecycleStatus::Active,
                'public_ssh_host' => '192.0.2.81', 'wireguard_ip' => '10.44.0.81',
            ])->id]),
            'path' => $instance->update(['checkout_path' => '/srv/moved']),
            'project' => $instance->update(['app_id' => OrbitApp::query()->create([
                'name' => 'Other', 'slug' => 'other', 'repository_url' => 'https://github.com/acme/other.git',
            ])->id]),
            'layout' => $instance->update(['source_layout' => AppInstanceSourceLayout::Worktree->value]),
            'production' => $instance->update(['environment' => 'production']),
            'deleted' => $instance->delete(),
        };

        expect(fn () => app(UpdateAppAction::class)->execute($this->fixture->app, $data))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('app.source_owner_changed');
            });

        expect($this->fixture->sources->originMutations)->toBe([]);
        expect($this->fixture->sources->switchedInstances)->toBe([]);
        expect($update->refresh()->status)->toBe(AppUpdateStatus::RolledBack);
        expect($this->fixture->app->refresh()->default_branch)->toBe('main');
        expect($this->fixture->app->repository_url)->toBe('git@github.com:acme/site.git');
    })->with(['branch', 'origin'])->with(['node', 'path', 'project', 'layout', 'production', 'deleted']);

    it('retains recovery state for an older interrupted source inventory without exact owners', function (bool $missingInventory): void {
        $data = orb101_update_data(defaultBranch: 'stable');
        $update = orb101_preflighted_source_update($this->fixture, $data);
        $inventory = $update->inventory;
        unset($inventory['source_owners']);
        $update->update(['inventory' => $missingInventory ? null : $inventory]);

        expect(fn () => app(UpdateAppAction::class)->execute($this->fixture->app, $data))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('app.source_owner_changed');
            });

        expect($update->refresh()->status)->toBe(AppUpdateStatus::RollingBack);
        expect($this->fixture->sources->switchedInstances)->toBe([]);
        expect($this->fixture->sources->restoredBranches)->toBe([]);
    })->with(['legacy IDs only' => false, 'missing inventory' => true]);

    it('retains attempted branch recovery when the recorded owner no longer owns its path', function (): void {
        $data = orb101_update_data(defaultBranch: 'stable');
        $update = orb101_preflighted_source_update($this->fixture, $data);
        $owner = $update->inventory['source_owners'][$this->fixture->defaultInstance->id];
        $update->update([
            'status' => AppUpdateStatus::RollingBack,
            'evidence' => ['branches' => [[
                ...$owner, 'previous_branch' => 'main', 'current_branch' => 'stable',
                'attempted' => true, 'switched' => false,
            ]]],
        ]);
        $this->fixture->defaultInstance->update(['checkout_path' => '/srv/moved']);

        expect(fn () => app(UpdateAppAction::class)->execute($this->fixture->app, $data))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('app.source_owner_changed');
            });

        expect($update->refresh()->status)->toBe(AppUpdateStatus::RollingBack);
        expect($update->evidence['branches'][0]['path'])->toBe('/srv/orbit/apps/acme/default');
        expect($this->fixture->sources->restoredBranches)->toBe([]);
    });

    it('restores completed branch and origin changes when later slug preparation fails', function (): void {
        $this->fixture->projections->failSlugPrepare = true;

        expect(fn () => app(UpdateAppAction::class)->execute(
            $this->fixture->app,
            orb101_update_data(slug: 'shop', repositoryUrl: 'https://github.com/acme/site.git', defaultBranch: 'stable'),
        ))->toThrow(ResourceOperationException::class);

        expect($this->fixture->defaultInstance->refresh()->branch)->toBe('main');
        expect($this->fixture->app->refresh()->default_branch)->toBe('main');
        expect($this->fixture->sources->restoredBranches)->toBe([
            ['instance_id' => $this->fixture->defaultInstance->id, 'branch' => 'main'],
        ]);
        expect($this->fixture->sources->originRestores)->toBe([$this->fixture->defaultInstance->checkout_path]);
        expect(AppUpdate::query()->sole()->evidence['branches'][0]['previous_branch'])->toBe('main');
        expect(AppUpdate::query()->sole()->status)->toBe(AppUpdateStatus::RolledBack);
    });

    it('restores the exact branch owner after a lost remote switch acknowledgment', function (): void {
        $ssh = new AppDevFakeSshExecutor([
            new CommandResult(0, '', '', 1, false),
            new CommandResult(1, '', '', 1, false),
        ]);
        app()->instance(SshExecutor::class, $ssh);
        app()->instance(AppUpdateSourceMutator::class, app(RemoteAppUpdateSourceMutator::class));

        expect(fn () => app(UpdateAppAction::class)->execute(
            $this->fixture->app,
            orb101_update_data(defaultBranch: 'stable'),
        ))->toThrow(ResourceOperationException::class);

        expect(array_column($ssh->connections, 'host'))->toBe(['10.44.0.80', '10.44.0.80', '10.44.0.80']);
        expect($ssh->commands[2]->arguments)->toBe([
            'bash', '-seu', '--', $this->fixture->defaultInstance->checkout_path, 'main',
        ]);
        expect($ssh->commands[2]->input)->toContain('switch -- "$branch"');
        expect(AppUpdate::query()->sole()->evidence['branches'][0])->toMatchArray([
            'attempted' => true, 'switched' => false, 'previous_branch' => 'main',
        ]);
        expect(AppUpdate::query()->sole()->status)->toBe(AppUpdateStatus::RolledBack);
        expect($this->fixture->defaultInstance->refresh()->branch)->toBe('main');
    });

    it('restores the original nullable stored branch without losing its inherited Git branch', function (): void {
        $this->fixture->defaultInstance->update(['branch' => null]);
        $this->fixture->projections->failSlugPrepare = true;

        expect(fn () => app(UpdateAppAction::class)->execute(
            $this->fixture->app,
            orb101_update_data(slug: 'shop', defaultBranch: 'stable'),
        ))->toThrow(ResourceOperationException::class);

        expect($this->fixture->defaultInstance->refresh()->branch)->toBeNull();
        expect($this->fixture->sources->restoredBranches)->toBe([
            ['instance_id' => $this->fixture->defaultInstance->id, 'branch' => 'main'],
        ]);
        expect(AppUpdate::query()->sole()->evidence['branches'][0]['previous_branch'])->toBeNull();
    });

    it('restores a switched branch when saving its completion fails', function (string $failure): void {
        $trigger = match ($failure) {
            'instance' => <<<'SQL'
                CREATE TEMP TRIGGER source_completion_failure BEFORE UPDATE ON app_instances
                WHEN NEW.branch = 'stable'
                BEGIN SELECT RAISE(ABORT, 'Injected Instance completion failure.'); END
                SQL,
            'journal' => <<<'SQL'
                CREATE TEMP TRIGGER source_completion_failure BEFORE UPDATE ON app_updates
                WHEN json_extract(NEW.evidence, '$.branches[0].switched') = 1
                BEGIN SELECT RAISE(ABORT, 'Injected journal completion failure.'); END
                SQL,
        };
        DB::unprepared($trigger);

        try {
            expect(fn () => app(UpdateAppAction::class)->execute(
                $this->fixture->app,
                orb101_update_data(defaultBranch: 'stable'),
            ))->toThrow(QueryException::class);
        } finally {
            DB::unprepared('DROP TRIGGER source_completion_failure');
        }

        expect($this->fixture->sources->switchedInstances)->toBe([$this->fixture->defaultInstance->id]);
        expect($this->fixture->sources->restoredBranches)->toBe([
            ['instance_id' => $this->fixture->defaultInstance->id, 'branch' => 'main'],
        ]);
        expect($this->fixture->defaultInstance->refresh()->branch)->toBe('main');
        expect(AppUpdate::query()->sole()->evidence['branches'][0])->toMatchArray([
            'previous_branch' => 'main', 'attempted' => true, 'switched' => false,
        ]);
        expect(AppUpdate::query()->sole()->status)->toBe(AppUpdateStatus::RolledBack);
    })->with(['instance', 'journal']);

    it('resumes preflighted source preparation without repeating completed source mutations', function (): void {
        $data = orb101_update_data(repositoryUrl: 'https://github.com/acme/site.git', defaultBranch: 'stable');
        app(UpdateAppAction::class)->execute($this->fixture->app, $data);
        $update = AppUpdate::query()->sole();
        $update->update(['status' => AppUpdateStatus::Preflighted]);
        $this->fixture->app->update(['default_branch' => 'main', 'repository_url' => 'git@github.com:acme/site.git']);

        app(UpdateAppAction::class)->execute($this->fixture->app, $data);

        expect($this->fixture->sources->switchedInstances)->toBe([$this->fixture->defaultInstance->id]);
        expect($this->fixture->sources->originMutations)->toBe([$this->fixture->defaultInstance->checkout_path]);
        expect($update->refresh()->status)->toBe(AppUpdateStatus::Complete);
        expect($update->evidence['branches'][0]['previous_branch'])->toBe('main');
        expect($update->evidence['origins'][0]['previous_url'])->toBe('git@github.com:acme/site.git');
    });

    it('retains original branch evidence when retrying an unacknowledged switch', function (): void {
        $data = orb101_update_data(defaultBranch: 'stable');
        app(UpdateAppAction::class)->execute($this->fixture->app, $data);
        $update = AppUpdate::query()->sole();
        $evidence = $update->evidence;
        $evidence['branches'][0]['switched'] = false;
        $update->update(['status' => AppUpdateStatus::Preflighted, 'evidence' => $evidence]);
        $this->fixture->app->update(['default_branch' => 'main']);

        app(UpdateAppAction::class)->execute($this->fixture->app, $data);

        expect($this->fixture->sources->switchedInstances)->toBe([
            $this->fixture->defaultInstance->id, $this->fixture->defaultInstance->id,
        ]);
        expect($update->refresh()->evidence['branches'][0]['previous_branch'])->toBe('main');
        expect($update->status)->toBe(AppUpdateStatus::Complete);
    });

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
