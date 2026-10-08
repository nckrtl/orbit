<?php

declare(strict_types=1);

use App\Actions\Instances\ReserveInstanceAppProjectionsAction;
use App\Actions\Instances\RunInstanceAppProjectionAction;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Instances\Apps\AppProjectionPlan;
use App\Domain\Instances\Apps\AppProjectionStepPlan;
use App\Domain\Instances\ComposerSourceClassifier;
use App\Domain\Instances\Environment\AppProjectionEnvironmentTarget;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\Environment\InstanceTestEnvironment;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilder;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildLock;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddyTransport;
use App\Infrastructure\Caddy\Build\Sources\RouteCaddySiteSource;
use App\Infrastructure\Instances\AppProjectionServingAccess;
use App\Infrastructure\Instances\DevelopmentCaddyAccessCommand;
use App\Infrastructure\Instances\NativeAppProjectionEnvironment;
use App\Infrastructure\Instances\NativeAppProjectionServingRuntime;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceConfigurator;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\InstanceAppProjection;
use App\Models\InstanceAppProjectionStep;
use App\Models\InstanceAppUpdate;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\CaddySiteCertificateFixtures;
use Tests\Support\LocalAppProjectionServingTransport;

beforeEach(function (): void {
    $this->servingDatabase = tempnam(sys_get_temp_dir(), 'orbit-serving-db-');
    $this->servingConnection = DB::getDefaultConnection();
    config(['database.connections.serving_projection_test' => [...config('database.connections.sqlite'), 'database' => $this->servingDatabase]]);
    DB::setDefaultConnection('serving_projection_test');
    Artisan::call('migrate', ['--database' => 'serving_projection_test', '--force' => true]);
    $this->servingRoot = sys_get_temp_dir().'/orbit-serving-'.bin2hex(random_bytes(6));
    mkdir($this->servingRoot, 0755);
    $this->root = $this->servingRoot;
});

afterEach(function (): void {
    DB::setDefaultConnection($this->servingConnection);
    DB::purge('serving_projection_test');
    unlink($this->servingDatabase);
    new Filesystem()->deleteDirectory($this->servingRoot);
});

describe('app projection native serving', function (): void {
    it('prepares native sites pools sockets access source profiles and cached APP_URL without publishing maps or changing siblings', function (): void {
        [$instance, $projection, $adapter, $transport, $builder] = serving_projection_fixture($this->servingRoot);
        $before = $instance->fresh()->app_runtime;
        $oldPool = serving_projection_pools($instance);
        $oldSibling = new DevelopmentSiteRepository()->forNode($instance->node)->firstWhere('app', 'sibling');
        $plan = serving_projection_step($projection, $adapter);
        $receipt = app(RunInstanceAppProjectionAction::class)->step($projection, $plan, $adapter);
        $sites = new DevelopmentSiteRepository()->forNode($instance->node);
        $candidate = $sites->firstWhere('app', 'web');
        expect($candidate->applicationDirectory())->toBe($this->servingRoot.'/checkout/new')
            ->and($candidate->documentRoot)->toBe('new/public')->and($candidate->phpVersion)->toBe('8.4')
            ->and($candidate->poolName())->toBe('orbit-instance-'.$instance->id.'-web')
            ->and($sites->firstWhere('app', 'sibling'))->toEqual($oldSibling)
            ->and($instance->fresh()->appConfiguration('web')['path'])->toBe('old')
            ->and($instance->fresh()->app_runtime)->toBe($before)
            ->and($instance->environmentValues()->where('app', 'web')->where('env_key', 'APP_URL')->sole()->env_value)->toBe('published-app-url')
            ->and(collect($instance->project->fresh()->apps)->firstWhere('name', 'web')['path'])->toBe('old')
            ->and(file_get_contents($this->servingRoot.'/checkout/new/bootstrap/cache/config.php'))->toContain('https://web.test')
            ->and(file_get_contents($this->servingRoot.'/checkout/old/bootstrap/cache/config.php'))->toContain('old-url')
            ->and(file_get_contents($this->servingRoot.'/checkout/sibling/bootstrap/cache/config.php'))->toContain('sibling-url')
            ->and($receipt->matches(InstanceAppProjectionStep::query()->sole()))->toBeTrue();
        $pools = serving_projection_pools($instance);
        expect($transport->installed['8.5'])->not->toContain('orbit-instance-'.$instance->id.'-web')
            ->and($transport->installed['8.5'])->toContain('orbit-instance-'.$instance->id.'-sibling')
            ->and($transport->installed['8.4'])->toContain('orbit-instance-'.$instance->id.'-web')
            ->and($pools)->toContain('chdir = '.$this->servingRoot.'/checkout/new', $candidate->socketPath())
            ->and($oldPool)->not->toBe($pools);
        $commands = collect($transport->commands);
        expect($commands->contains(fn ($command): bool => in_array($this->servingRoot.'/checkout/new', $command->arguments, true)))->toBeTrue()
            ->and($commands->contains(fn ($command): bool => str_contains($command->input ?? '', base64_encode(new DevelopmentPhpFpmConfigRenderer()->render($sites->where('phpVersion', '8.4')->values(), serving_projection_account())))))->toBeTrue();
        $builder->build($instance->node->fresh());
        $render = new NodeCaddyfileRenderer([new RouteCaddySiteSource(new DevelopmentSiteRepository, new DevelopmentCaddyConfigRenderer)])->render($instance->node);
        expect($render->content)->toContain($this->servingRoot.'/checkout/new/public', $this->servingRoot.'/checkout/sibling/public')->not->toContain($this->servingRoot.'/checkout/old/public');
    });

    it('refuses a group-writable cached configuration instead of weakening protected file checks', function (): void {
        [$instance, $projection, $adapter] = serving_projection_fixture($this->servingRoot);
        $cache = $this->servingRoot.'/checkout/new/bootstrap/cache/config.php';
        chmod($cache, 0660);
        $before = file_get_contents($cache);
        expect(fn () => app(RunInstanceAppProjectionAction::class)->step($projection, serving_projection_step($projection, $adapter), $adapter))
            ->toThrow(ResourceOperationException::class);
        expect(file_get_contents($cache))->toBe($before)
            ->and($instance->fresh()->appConfiguration('web')['path'])->toBe('old')
            ->and($projection->fresh()->active_instance_id)->toBe($instance->id);
    });

    it('retains a legacy app pool socket and certificate identity while changing its root', function (): void {
        [$instance, $projection, $adapter] = serving_projection_fixture($this->servingRoot, 'legacy');
        $before = new DevelopmentSiteRepository()->forNode($instance->node)->firstWhere('domain', 'web.test');
        app(RunInstanceAppProjectionAction::class)->step($projection, serving_projection_step($projection, $adapter), $adapter);
        $candidate = new DevelopmentSiteRepository()->forNode($instance->node)->firstWhere('domain', 'web.test');
        expect($candidate->scope)->toBe($before->scope)->and($candidate->poolName())->toBe($before->poolName())
            ->and($candidate->socketPath())->toBe($before->socketPath())->and($candidate->applicationPath)->toBe('new');
    });

    it('withdraws PHP for a retained app changing to a static source without changing its sibling pool', function (): void {
        [$instance, $projection, $adapter, $transport] = serving_projection_fixture($this->servingRoot, 'static');
        app(RunInstanceAppProjectionAction::class)->step($projection, serving_projection_step($projection, $adapter), $adapter);
        $sites = new DevelopmentSiteRepository()->forNode($instance->node);
        expect($sites->firstWhere('app', 'web')->phpVersion)->toBeNull()
            ->and($sites->firstWhere('app', 'web')->applicationPath)->toBe('new')
            ->and($transport->installed['8.5'])->not->toContain('orbit-instance-'.$instance->id.'-web')
            ->and($transport->installed['8.5'])->toContain('orbit-instance-'.$instance->id.'-sibling')
            ->and($instance->fresh()->appConfiguration('web')['type'])->toBe('laravel-app');
        $render = new DevelopmentCaddyConfigRenderer()->render($sites->where('domain', 'web.test')->values());
        expect($render)->toContain('file_server')->not->toContain('php_fastcgi');
    });

    it('adds a privately reserved app through native certificate and site owners while its published app list stays old', function (): void {
        [$instance, $projection, $adapter] = serving_projection_fixture($this->servingRoot, 'add');
        expect(new DevelopmentSiteRepository()->forNode($instance->node)->pluck('app')->all())->not->toContain('added');
        app(RunInstanceAppProjectionAction::class)->step($projection, serving_projection_step($projection, $adapter, 'prepare', 'added'), $adapter);
        expect(new DevelopmentSiteRepository()->forNode($instance->node)->pluck('app')->all())->toContain('added', 'web', 'sibling')
            ->and(array_column($instance->fresh()->effectiveApps(), 'name'))->toBe(['sibling', 'web'])
            ->and(Route::query()->where('app', 'added')->sole()->sites_published)->toBeFalse()
            ->and(serving_projection_pools($instance))->toContain('orbit-instance-'.$instance->id.'-added');
    });
});

describe('app projection serving recovery', function (): void {
    it('recovers a lost completion response from the protected receipt and independent builds select the same committed candidate', function (): void {
        [$instance, $projection, $adapter, $transport, $builder] = serving_projection_fixture($this->servingRoot);
        $transport->loseFinish = true;
        $runner = app(RunInstanceAppProjectionAction::class);
        $plan = serving_projection_step($projection, $adapter);
        expect(fn () => $runner->step($projection, $plan, $adapter))->toThrow(ResourceOperationException::class);
        DB::disconnect('serving_projection_test');
        $builder->build($instance->node->fresh());
        expect(new DevelopmentSiteRepository()->forNode($instance->node)->firstWhere('app', 'web')->applicationPath)->toBe('new');
        $receipt = $runner->step($projection, $plan, $adapter);
        expect($runner->step($projection, $plan, $adapter)->evidence())->toBe($receipt->evidence())
            ->and($instance->fresh()->appConfiguration('web')['path'])->toBe('old');
    });

    it('refuses a foreign result binding instead of accepting a database-shaped receipt', function (): void {
        [$instance, $projection, $adapter, $transport] = serving_projection_fixture($this->servingRoot);
        $transport->foreignReceipt = true;
        $runner = app(RunInstanceAppProjectionAction::class);
        $plan = serving_projection_step($projection, $adapter);
        expect(fn () => $runner->step($projection, $plan, $adapter))->toThrow(ResourceOperationException::class);
        expect(InstanceAppProjectionStep::query()->sole()->status)->toBe('access-ready')
            ->and($projection->fresh()->active_instance_id)->toBe($instance->id);
        expect($runner->step($projection, $plan, $adapter)->matches(InstanceAppProjectionStep::query()->sole()))->toBeTrue();
    });

    it('recovers a cache write interrupted after replacement without treating its candidate as the original', function (): void {
        [$instance, $projection, $adapter, $transport] = serving_projection_fixture($this->servingRoot);
        $transport->interruptAt = 'cache-write';
        $runner = app(RunInstanceAppProjectionAction::class);
        $plan = serving_projection_step($projection, $adapter);
        expect(fn () => $runner->step($projection, $plan, $adapter))->toThrow(ResourceOperationException::class);
        expect(file_get_contents($this->servingRoot.'/checkout/new/bootstrap/cache/config.php'))->toContain('https://web.test')
            ->and(new DevelopmentSiteRepository()->forNode($instance->node)->firstWhere('app', 'web')->applicationPath)->toBe('old');
        $runner->step($projection, $plan, $adapter);
        $prepared = InstanceAppProjectionStep::query()->sole();
        $runner->step($projection, serving_projection_step($projection, $adapter, 'restore', 'web', $prepared), $adapter);
        expect(file_get_contents($this->servingRoot.'/checkout/new/bootstrap/cache/config.php'))->toContain('new-url');
    });

    it('checkpoints an interrupted cache restore or protected snapshot unlink before resuming restoration', function (string $hook): void {
        [$instance, $projection, $adapter, $transport] = serving_projection_fixture($this->servingRoot);
        $runner = app(RunInstanceAppProjectionAction::class);
        $runner->step($projection, serving_projection_step($projection, $adapter), $adapter);
        $prepared = InstanceAppProjectionStep::query()->sole();
        $restore = serving_projection_step($projection, $adapter, 'restore', 'web', $prepared);
        $transport->interruptAt = $hook;
        expect(fn () => $runner->step($projection, $restore, $adapter))->toThrow(ResourceOperationException::class);
        $receipt = $runner->step($projection, $restore, $adapter);
        expect($runner->step($projection, $restore, $adapter)->evidence())->toBe($receipt->evidence())
            ->and(file_get_contents($this->servingRoot.'/checkout/new/bootstrap/cache/config.php'))->toContain('new-url')
            ->and(glob($this->servingRoot.'/receipts/'.$prepared->receipt_id.'/before-*'))->toBe([])
            ->and(new DevelopmentSiteRepository()->forNode($instance->node)->firstWhere('app', 'web')->applicationPath)->toBe('old');
    })->with(['cache-restore', 'snapshot-unlink']);

    it('withdraws only a removed app and restores its old site pool and identity before publication', function (): void {
        [$instance, $projection, $adapter] = serving_projection_fixture($this->servingRoot, 'remove');
        $runner = app(RunInstanceAppProjectionAction::class);
        $before = serving_projection_pools($instance);
        $runner->step($projection, serving_projection_step($projection, $adapter), $adapter);
        expect(serving_projection_pools($instance))->not->toContain('orbit-instance-'.$instance->id.'-web')
            ->and(new DevelopmentSiteRepository()->forNode($instance->node)->pluck('app')->all())->toBe(['sibling']);
        $prepared = InstanceAppProjectionStep::query()->sole();
        $runner->step($projection, serving_projection_step($projection, $adapter, 'restore', 'web', $prepared), $adapter);
        expect(serving_projection_pools($instance))->toBe($before)
            ->and($instance->fresh()->appConfiguration('web')['path'])->toBe('old');
    });

    it('requires the parent to publish an added Route and keeps it serving after ownership release', function (bool $publishRoute): void {
        [$instance, $projection, $adapter, , $builder] = serving_projection_fixture($this->servingRoot, 'add');
        $runner = app(RunInstanceAppProjectionAction::class);
        $runner->step($projection, serving_projection_step($projection, $adapter, 'prepare', 'added'), $adapter);
        $prepared = InstanceAppProjectionStep::query()->sole();
        DB::transaction(function () use ($instance, $projection, $publishRoute): void {
            $instance->project->update(['apps' => array_values($projection->plan['candidate_apps'])]);
            $instance->update(['app_runtime' => $projection->plan['candidate_profiles']]);
            if ($publishRoute) {
                Route::query()->where('app', 'added')->sole()->update(['status' => 'active']);
            }
            InstanceAppUpdate::query()->findOrFail($projection->instance_app_update_id)->update(['published_at' => now()]);
        });
        $cleanup = serving_projection_step($projection, $adapter, 'cleanup', 'added', $prepared);
        if (! $publishRoute) {
            expect(fn () => $runner->step($projection, $cleanup, $adapter))->toThrow(ResourceOperationException::class);
            expect($projection->fresh()->active_instance_id)->toBe($instance->id);

            return;
        }
        $runner->step($projection, $cleanup, $adapter);
        $runner->complete(InstanceAppUpdate::query()->findOrFail($projection->instance_app_update_id), ['published' => true]);
        $builder->build($instance->node->fresh());
        expect(new DevelopmentSiteRepository()->forNode($instance->node)->pluck('app')->all())->toContain('added', 'web', 'sibling')
            ->and($projection->fresh()->active_instance_id)->toBeNull();
    })->with([false, true]);

    it('keeps a removed app withdrawn on independent builds after forward completion releases journal ownership', function (): void {
        [$instance, $projection, $adapter, , $builder] = serving_projection_fixture($this->servingRoot, 'remove');
        $runner = app(RunInstanceAppProjectionAction::class);
        $runner->step($projection, serving_projection_step($projection, $adapter), $adapter);
        $prepared = InstanceAppProjectionStep::query()->sole();
        DB::transaction(function () use ($instance, $projection): void {
            $instance->project->update(['apps' => array_values($projection->plan['candidate_apps'])]);
            $instance->update(['app_runtime' => $projection->plan['candidate_profiles']]);
            InstanceAppUpdate::query()->findOrFail($projection->instance_app_update_id)->update(['published_at' => now()]);
        });
        $runner->step($projection, serving_projection_step($projection, $adapter, 'cleanup', 'web', $prepared), $adapter);
        $owner = InstanceAppUpdate::query()->findOrFail($projection->instance_app_update_id);
        $runner->complete($owner, ['published' => true]);
        DB::disconnect('serving_projection_test');
        $builder->build($instance->node->fresh());
        expect(new DevelopmentSiteRepository()->forNode($instance->node)->pluck('app')->all())->toBe(['sibling'])
            ->and(Route::query()->where('app', 'web')->sole()->sites_published)->toBeFalse()
            ->and($projection->fresh()->active_instance_id)->toBeNull();
    });

    it('restores candidate cached URL from protected snapshots and leaves old and unrelated files untouched', function (): void {
        [$instance, $projection, $adapter] = serving_projection_fixture($this->servingRoot);
        $runner = app(RunInstanceAppProjectionAction::class);
        $file = $this->servingRoot.'/checkout/new/bootstrap/cache/config.php';
        $before = file_get_contents($file);
        $runner->step($projection, serving_projection_step($projection, $adapter), $adapter);
        $prepared = InstanceAppProjectionStep::query()->sole();
        $restore = serving_projection_step($projection, $adapter, 'restore', 'web', $prepared);
        $runner->step($projection, $restore, $adapter);
        $runner->step($projection, $restore, $adapter);
        expect(file_get_contents($file))->toBe($before)
            ->and(fileperms($file) & 0777)->toBe(0640)
            ->and(new DevelopmentSiteRepository()->forNode($instance->node)->firstWhere('app', 'web')->applicationPath)->toBe('old')
            ->and(file_get_contents($this->servingRoot.'/checkout/sibling/bootstrap/cache/config.php'))->toContain('sibling-url');
    });

    it('recovers an interrupted native Node build without recapturing candidate cache as before state', function (): void {
        [$instance, $projection, $adapter, $transport, $builder] = serving_projection_fixture($this->servingRoot);
        $transport->failBuild = true;
        $runner = app(RunInstanceAppProjectionAction::class);
        $plan = serving_projection_step($projection, $adapter);
        expect(fn () => $runner->step($projection, $plan, $adapter))->toThrow(RuntimeConvergenceException::class);
        expect(InstanceAppProjectionStep::query()->sole()->status)->toBe('rendering');
        $builder->build($instance->node);
        $runner->step($projection, $plan, $adapter);
        $prepared = InstanceAppProjectionStep::query()->sole();
        $runner->step($projection, serving_projection_step($projection, $adapter, 'restore', 'web', $prepared), $adapter);
        $builder->build($instance->node);
        expect(file_get_contents($this->servingRoot.'/checkout/new/bootstrap/cache/config.php'))->toContain('new-url')
            ->and(new DevelopmentSiteRepository()->forNode($instance->node)->firstWhere('app', 'web')->applicationPath)->toBe('old');
    });

    it('restores an addition without withdrawing retained app sites or pools', function (): void {
        [$instance, $projection, $adapter] = serving_projection_fixture($this->servingRoot, 'add');
        $before = serving_projection_pools($instance);
        $runner = app(RunInstanceAppProjectionAction::class);
        $runner->step($projection, serving_projection_step($projection, $adapter, 'prepare', 'added'), $adapter);
        $prepared = InstanceAppProjectionStep::query()->sole();
        $runner->step($projection, serving_projection_step($projection, $adapter, 'restore', 'added', $prepared), $adapter);
        expect(serving_projection_pools($instance))->toBe($before)
            ->and(new DevelopmentSiteRepository()->forNode($instance->node)->pluck('app')->all())->not->toContain('added')
            ->and(file_get_contents($this->servingRoot.'/checkout/added/bootstrap/cache/config.php'))->toContain('added-url');
    });

    it('rejects missing or damaged receipts and replaced directory ownership before recovery mutates serving files', function (string $fault): void {
        [$instance, $projection, $adapter] = serving_projection_fixture($this->servingRoot);
        $runner = app(RunInstanceAppProjectionAction::class);
        $plan = serving_projection_step($projection, $adapter);
        $runner->step($projection, $plan, $adapter);
        $step = InstanceAppProjectionStep::query()->sole();
        $directory = $this->servingRoot.'/receipts/'.$step->receipt_id;
        if ($fault === 'missing') {
            unlink($directory.'/manifest.json');
        } elseif ($fault === 'snapshot') {
            file_put_contents($directory.'/before-0', 'foreign-snapshot');
        } elseif ($fault === 'protection') {
            chmod($directory.'/manifest.json', 0644);
        } else {
            $cache = $this->servingRoot.'/checkout/new/bootstrap/cache';
            rename($cache, $cache.'-held');
            mkdir($cache, 0755);
            rename($cache.'-held/config.php', $cache.'/config.php');
        }
        $contents = file_get_contents($this->servingRoot.'/checkout/new/bootstrap/cache/config.php');
        expect(fn () => $runner->step($projection, $plan, $adapter))->toThrow(ResourceOperationException::class);
        expect(file_get_contents($this->servingRoot.'/checkout/new/bootstrap/cache/config.php'))->toBe($contents)
            ->and($projection->fresh()->active_instance_id)->toBe($instance->id);
    })->with(['missing', 'snapshot', 'protection', 'directory']);

    it('preserves an unrelated Instance site and pool across independent builds and restoration', function (): void {
        [$instance, $projection, $adapter, $transport, $builder] = serving_projection_fixture($this->servingRoot);
        $other = $instance->replicate();
        $other->name = 'unrelated';
        $other->checkout_path = $this->servingRoot.'/unrelated';
        new Filesystem()->copyDirectory($instance->checkout_path, $other->checkout_path);
        $other->save();
        $route = Route::query()->create(['project_id' => $other->project_id, 'node_id' => $other->node_id, 'app' => 'sibling', 'domain' => 'unrelated.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending', 'sites_published' => true]);
        $route->targets()->create(['instance_id' => $other->id, 'position' => 0]);
        $route->update(['status' => 'active']);
        $transport->installed['8.5'] = serving_projection_pools($instance);
        $before = new DevelopmentSiteRepository()->forNode($instance->node)->firstWhere('instanceId', $other->id);
        $runner = app(RunInstanceAppProjectionAction::class);
        $runner->step($projection, serving_projection_step($projection, $adapter), $adapter);
        DB::disconnect('serving_projection_test');
        $builder->build($instance->node->fresh());
        expect(new DevelopmentSiteRepository()->forNode($instance->node)->firstWhere('instanceId', $other->id))->toEqual($before)
            ->and($transport->installed['8.5'])->toContain($before->poolName());
        $prepared = InstanceAppProjectionStep::query()->sole();
        $runner->step($projection, serving_projection_step($projection, $adapter, 'restore', 'web', $prepared), $adapter);
        expect(new DevelopmentSiteRepository()->forNode($instance->node)->firstWhere('instanceId', $other->id))->toEqual($before);
    });

    it('verifies forward after the parent publishes and refuses foreign cached configuration without deleting it', function (): void {
        [$instance, $projection, $adapter, $transport] = serving_projection_fixture($this->servingRoot);
        $runner = app(RunInstanceAppProjectionAction::class);
        $runner->step($projection, serving_projection_step($projection, $adapter), $adapter);
        $prepared = InstanceAppProjectionStep::query()->sole();
        DB::transaction(function () use ($instance, $projection): void {
            $instance->update(['app_overrides' => ['web' => ['path' => 'new', 'web_root' => 'public']], 'app_runtime' => $projection->plan['candidate_profiles']]);
            InstanceAppUpdate::query()->findOrFail($projection->instance_app_update_id)->update(['published_at' => now()]);
        });
        $cleanup = serving_projection_step($projection, $adapter, 'cleanup', 'web', $prepared);
        $receipt = $runner->step($projection, $cleanup, $adapter);
        expect(glob($this->servingRoot.'/receipts/'.$prepared->receipt_id.'/before-*'))->toBe([])
            ->and(glob($this->servingRoot.'/receipts/'.$prepared->receipt_id.'/candidate-*'))->toBe([]);
        $publishCount = count(array_filter($transport->commands, fn ($command): bool => str_contains($command->input ?? '', 'managed_configuration="$pool_directory/orbit-scopes.conf"')));
        expect($runner->step($projection, $cleanup, $adapter)->evidence())->toBe($receipt->evidence())
            ->and(count(array_filter($transport->commands, fn ($command): bool => str_contains($command->input ?? '', 'managed_configuration="$pool_directory/orbit-scopes.conf"'))))->toBe($publishCount);
        file_put_contents($this->servingRoot.'/checkout/new/bootstrap/cache/config.php', 'foreign-cache');
        expect(fn () => $runner->step($projection, $cleanup, $adapter))->toThrow(ResourceOperationException::class);
        expect(file_get_contents($this->servingRoot.'/checkout/new/bootstrap/cache/config.php'))->toBe('foreign-cache')
            ->and($projection->fresh()->active_instance_id)->toBe($instance->id);
    });
});

function serving_projection_account(): ManagedUserAccount
{
    return new ManagedUserAccount(posix_getpwuid(posix_geteuid())['name'], posix_getgrgid(posix_getegid())['name'], '/tmp/serving-account');
}

describe('app projection native serving ACL review', function (): void {
    it('applies real Caddy ACL effects before both environment and cached URL receipts and preserves them on retries and restore', function (): void {
        [$instance, $projection, $adapter, $transport] = serving_projection_fixture($this->root);
        $published = app(InstanceEnvironmentContextResolver::class)->resolveForProjection($instance, $projection->id, 'web');
        $candidate = new InstanceEnvironmentContext($published->instanceId, $published->projectId, $published->nodeId, $published->environment,
            $this->root.'/checkout/new', $published->executionUser, true, $published->routeId, $published->routeDomain, $published->nodeStatus, $published->node, app: 'web');
        $renderer = app(InstanceEnvironmentRenderer::class);
        $old = $renderer->render($published, ['APP_URL' => 'published-app-url']);
        file_put_contents($published->path.'/.env', $old);
        chmod($published->path.'/.env', 0640);
        $keys = Mockery::mock(SshKeyProvider::class);
        $keys->shouldReceive('privateKeyPath')->andReturn('/unused/key');
        $hosts = Mockery::mock(KnownHostsStore::class);
        $hosts->shouldReceive('path')->andReturn('/unused/hosts');
        $env = new NativeAppProjectionEnvironment(new DevelopmentSshExecutor($transport, $keys, $hosts), app(InstanceEnvironmentStore::class), $renderer,
            app(InstanceTestEnvironment::class), $published, [new AppProjectionEnvironmentTarget('stable', $published, $candidate, $instance->checkout_path, $instance->checkout_path, 'stable-home')]);
        $envPlan = new AppProjectionStepPlan('prepare-env', 1, 'prepare', 'web', 'environment', 'prepare', $env->targetIdentities(), 'restore');
        $cache = $candidate->path.'/bootstrap/cache/config.php';
        expect(new Process(['getfacl', '-cp', $cache])->mustRun()->getOutput())->not->toContain('user:caddy:');
        $runner = app(RunInstanceAppProjectionAction::class);
        $envReceipt = $runner->step($projection, $envPlan, $env);
        $envSource = InstanceAppProjectionStep::query()->where('intent->resource', 'environment')->sole();
        $envAcl = new Process(['getfacl', '-cp', $candidate->path.'/.env'])->mustRun()->getOutput();
        $prepared = $runner->step($projection, serving_projection_step($projection, $adapter, sequence: 2), $adapter);
        $source = InstanceAppProjectionStep::query()->where('intent->resource', 'serving')->sole();
        $cacheAcl = new Process(['getfacl', '-cp', $cache])->mustRun()->getOutput();
        expect($cacheAcl)->toContain('user:caddy:---')->and($envAcl)->toContain('user:caddy:---')
            ->and($runner->step($projection, $envPlan, $env)->resultFingerprint)->toBe($envReceipt->resultFingerprint)
            ->and($runner->step($projection, serving_projection_step($projection, $adapter, sequence: 2), $adapter)->resultFingerprint)->toBe($prepared->resultFingerprint);
        $runner->step($projection, serving_projection_step($projection, $adapter, 'restore', source: $source, sequence: 3), $adapter);
        $runner->step($projection, new AppProjectionStepPlan('restore-env', 4, 'restore', 'web', 'environment', 'restore', [...$env->targetIdentities(), 'restores_step_id' => $envSource->id], 'restore'), $env);
        expect(file_get_contents($published->path.'/.env'))->toBe($old)->and(file_exists($candidate->path.'/.env'))->toBeFalse()
            ->and(file_get_contents($cache))->toContain("'new-url'")
            ->and(new Process(['getfacl', '-cp', $cache])->mustRun()->getOutput())->toBe($cacheAcl)
            ->and(collect($transport->commands)->filter(fn ($command): bool => str_contains($command->input ?? '', 'checkouts=()'))->count())->toBe(1)
            ->and(InstanceEnvironmentValue::query()->where('app', 'web')->sole()->env_value)->toBe('published-app-url')
            ->and($projection->fresh()->active_instance_id)->toBe($instance->id);
    });
});

describe('app projection native serving independent access review', function (): void {
    it('keeps both root protections stable during independent native access maintenance', function (): void {
        [$instance, $projection, $adapter, $transport] = serving_projection_fixture($this->root);
        $runner = app(RunInstanceAppProjectionAction::class);
        $plan = serving_projection_step($projection, $adapter);
        $receipt = $runner->step($projection, $plan, $adapter);
        $source = InstanceAppProjectionStep::query()->sole();
        $keys = Mockery::mock(SshKeyProvider::class);
        $keys->shouldReceive('privateKeyPath')->andReturn('/unused/key');
        $hosts = Mockery::mock(KnownHostsStore::class);
        $hosts->shouldReceive('path')->andReturn('/unused/hosts');
        $ssh = new DevelopmentSshExecutor($transport, $keys, $hosts);
        $ssh->execute($instance->node, new DevelopmentCaddyAccessCommand()->command(new AppProjectionServingAccess($ssh)->forNode($instance->node)), 'source-access', 'app-dev.source_access_failed');
        expect($runner->step($projection, $plan, $adapter)->resultFingerprint)->toBe($receipt->resultFingerprint);
        $runner->step($projection, serving_projection_step($projection, $adapter, 'restore', source: $source), $adapter);
        expect(file_get_contents($this->root.'/checkout/new/bootstrap/cache/config.php'))->toContain("'new-url'")
            ->and($projection->fresh()->active_instance_id)->toBe($instance->id);
    });
});

describe('app projection serving recovery boundary review', function (): void {
    it('rejects foreign targets and changed parents at both publication boundaries while keeping ownership', function (string $phase, string $fault): void {
        [$instance, $projection, $adapter, $transport] = serving_projection_fixture($this->root);
        $runner = app(RunInstanceAppProjectionAction::class);
        $source = null;
        if ($phase === 'restore') {
            $runner->step($projection, serving_projection_step($projection, $adapter), $adapter);
            $source = InstanceAppProjectionStep::query()->sole();
        }
        $cache = $this->root.'/checkout/new/bootstrap/cache/config.php';
        $before = file_get_contents($cache);
        $transport->foreignAt = $phase.'-'.$fault;
        expect(fn () => $runner->step($projection, serving_projection_step($projection, $adapter, $phase, source: $source), $adapter))->toThrow(ResourceOperationException::class);
        expect(file_get_contents($cache))->toBe($fault === 'protection' ? $before : 'foreign-cache')
            ->and($projection->fresh()->active_instance_id)->toBe($instance->id)
            ->and($instance->fresh()->appConfiguration('web')['path'])->toBe('old');
        if (in_array($fault, ['parent', 'parent-exchange'], true)) {
            expect(file_get_contents(dirname($cache).'-held/config.php'))->toBe($before);
        }
    })->with(['prepare', 'restore'])->with(['checkpoint', 'parent', 'protection', 'exchange', 'parent-exchange']);

    it('does not resurrect a deleted prepared cache during restore', function (): void {
        [$instance, $projection, $adapter] = serving_projection_fixture($this->root);
        $runner = app(RunInstanceAppProjectionAction::class);
        $runner->step($projection, serving_projection_step($projection, $adapter), $adapter);
        $source = InstanceAppProjectionStep::query()->sole();
        $cache = $this->root.'/checkout/new/bootstrap/cache/config.php';
        unlink($cache);
        expect(fn () => $runner->step($projection, serving_projection_step($projection, $adapter, 'restore', source: $source), $adapter))->toThrow(ResourceOperationException::class);
        expect(file_exists($cache))->toBeFalse()->and($projection->fresh()->active_instance_id)->toBe($instance->id);
    });

    it('does not repair foreign ACL changes over protected evidence on retry or restore', function (string $phase): void {
        [$instance, $projection, $adapter, $transport] = serving_projection_fixture($this->root);
        $runner = app(RunInstanceAppProjectionAction::class);
        $runner->step($projection, serving_projection_step($projection, $adapter), $adapter);
        $source = InstanceAppProjectionStep::query()->sole();
        $cache = $this->root.'/checkout/new/bootstrap/cache/config.php';
        new Process(['setfacl', '-x', 'u:caddy', $cache])->mustRun();
        $foreign = new Process(['getfacl', '-cp', $cache])->mustRun()->getOutput();
        expect(fn () => $runner->step($projection, serving_projection_step($projection, $adapter, $phase, source: $phase === 'restore' ? $source : null), $adapter))->toThrow(ResourceOperationException::class);
        expect(new Process(['getfacl', '-cp', $cache])->mustRun()->getOutput())->toBe($foreign)
            ->and(collect($transport->commands)->filter(fn ($command): bool => str_contains($command->input ?? '', 'checkouts=()'))->count())->toBe(1)
            ->and($projection->fresh()->active_instance_id)->toBe($instance->id);
    })->with(['prepare', 'restore']);

    it('refuses changed unacknowledged private proposals instead of adopting them', function (string $phase): void {
        [$instance, $projection, $adapter, $transport] = serving_projection_fixture($this->root);
        $runner = app(RunInstanceAppProjectionAction::class);
        $transport->interruptAt = 'prepare-create';
        $prepare = serving_projection_step($projection, $adapter);
        expect(fn () => $runner->step($projection, $prepare, $adapter))->toThrow(ResourceOperationException::class);
        $source = InstanceAppProjectionStep::query()->sole();
        $manifest = json_decode(file_get_contents($this->root.'/receipts/'.$source->receipt_id.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $proposal = $manifest['records'][0]['stage_parent'].'/'.$manifest['records'][0]['stage_name'].'/candidate';
        file_put_contents($proposal, 'foreign-private-proposal');
        expect(fn () => $runner->step($projection, $phase === 'prepare' ? $prepare : serving_projection_step($projection, $adapter, 'restore', source: $source), $adapter))->toThrow(ResourceOperationException::class);
        expect(file_get_contents($proposal))->toBe('foreign-private-proposal')
            ->and(file_get_contents($this->root.'/checkout/new/bootstrap/cache/config.php'))->toContain("'new-url'")
            ->and($projection->fresh()->active_instance_id)->toBe($instance->id);
    })->with(['prepare', 'restore']);

    it('recovers hard exits before staged file identities are acknowledged without recapturing snapshots', function (string $point, bool $rollback): void {
        [$instance, $projection, $adapter, $transport] = serving_projection_fixture($this->root);
        $runner = app(RunInstanceAppProjectionAction::class);
        $prepare = serving_projection_step($projection, $adapter);
        if ($point === 'restore-create') {
            $runner->step($projection, $prepare, $adapter);
        }
        $source = $point === 'restore-create' ? InstanceAppProjectionStep::query()->sole() : null;
        $plan = $source === null ? $prepare : serving_projection_step($projection, $adapter, 'restore', source: $source);
        $transport->interruptAt = $point;
        expect(fn () => $runner->step($projection, $plan, $adapter))->toThrow(ResourceOperationException::class);
        $source ??= InstanceAppProjectionStep::query()->where('intent->phase', 'prepare')->sole();
        $root = $this->root.'/receipts/'.$source->receipt_id;
        $snapshot = file_get_contents($root.'/before-0');
        $snapshotInode = fileinode($root.'/before-0');
        $manifest = json_decode(file_get_contents($root.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $key = $point === 'prepare-create' ? 'result' : 'restore_result';
        expect($manifest['records'][0][$key] ?? null)->toBeNull()
            ->and($manifest['records'][0][$key.'_intended'])->toBeTrue()
            ->and($manifest['records'][0]['stage_identity'])->not->toBeNull();
        if ($rollback) {
            $runner->step($projection, serving_projection_step($projection, $adapter, 'restore', source: $source), $adapter);
            expect(file_get_contents($this->root.'/checkout/new/bootstrap/cache/config.php'))->toContain("'new-url'")
                ->and(glob($this->root.'/.orbit-app-serving-stages/*'))->toBe([]);
        } else {
            $runner->step($projection, $plan, $adapter);
            if ($point === 'prepare-create') {
                expect(file_get_contents($root.'/before-0'))->toBe($snapshot)->and(fileinode($root.'/before-0'))->toBe($snapshotInode)
                    ->and(file_get_contents($this->root.'/checkout/new/bootstrap/cache/config.php'))->toContain("'https://web.test'");
            } else {
                expect(file_get_contents($this->root.'/checkout/new/bootstrap/cache/config.php'))->toContain("'new-url'");
            }
        }
        expect($projection->fresh()->active_instance_id)->toBe($instance->id);
    })->with(['prepare-create', 'restore-create'])->with([false, true]);
});

describe('app projection serving recovery access handoff', function (): void {
    it('resumes or restores the proven pre-file phase after access and initialization interruptions', function (string $resource, string $point, bool $rollback): void {
        [$instance, $projection, $serving, $transport] = serving_projection_fixture($this->root);
        $adapter = $resource === 'serving' ? $serving : serving_projection_environment($instance, $projection, $transport, $this->root);
        $plan = $resource === 'serving' ? serving_projection_step($projection, $serving) : new AppProjectionStepPlan('prepare-env', 1, 'prepare', 'web', 'environment', 'prepare', $adapter->targetIdentities(), 'restore');
        $cache = $this->root.'/checkout/new/bootstrap/cache/config.php';
        $before = file_get_contents($cache);
        if ($point === 'access-ready') {
            $armed = true;
            InstanceAppProjectionStep::updated(function (InstanceAppProjectionStep $step) use ($projection, &$armed): void {
                if ($armed && $step->instance_app_projection_id === $projection->id && $step->status === 'access-ready') {
                    $armed = false;
                    throw new ResourceOperationException('test.interruption', 'Interrupted after access acknowledgment.', 409);
                }
            });
        } else {
            $transport->accessInterrupt = $point;
        }
        $runner = app(RunInstanceAppProjectionAction::class);
        expect(fn () => $runner->step($projection, $plan, $adapter))->toThrow(Exception::class);
        $source = InstanceAppProjectionStep::query()->sole();
        $root = $this->root.'/'.($resource === 'serving' ? 'receipts' : 'environment-receipts').'/'.$source->receipt_id;
        expect(file_exists($root.'/manifest.json'))->toBeFalse()->and(file_get_contents($cache))->toBe($before)
            ->and(file_exists($this->root.'/checkout/new/.env'))->toBeFalse();
        $access = json_decode(file_get_contents($this->root.'/access-receipts/'.$source->receipt_id.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($access['file_initialized'])->toBeFalse()->and($access['operations'])->not->toBeEmpty();
        if ($rollback) {
            $restore = $resource === 'serving' ? serving_projection_step($projection, $serving, 'restore', source: $source)
                : new AppProjectionStepPlan('restore-env', 2, 'restore', 'web', 'environment', 'restore', [...$adapter->targetIdentities(), 'restores_step_id' => $source->id], 'restore');
            $receipt = $runner->step($projection, $restore, $adapter);
            expect($runner->step($projection, $restore, $adapter)->resultFingerprint)->toBe($receipt->resultFingerprint)
                ->and(file_get_contents($cache))->toBe($before)->and(file_exists($this->root.'/checkout/new/.env'))->toBeFalse();
        } else {
            $receipt = $runner->step($projection, $plan, $adapter);
            expect($runner->step($projection, $plan, $adapter)->resultFingerprint)->toBe($receipt->resultFingerprint);
            expect(file_exists($root.'/manifest.json'))->toBeTrue();
        }
        expect($projection->fresh()->active_instance_id)->toBe($instance->id)
            ->and($instance->fresh()->appConfiguration('web')['path'])->toBe('old');
    })->with(['serving', 'environment'])->with(['access-result', 'access-exit', 'access-ready', 'file-command', 'file-init', 'acl-write'])->with([false, true]);

    it('refuses foreign ACLs and lost or damaged access evidence instead of restarting pre-file preparation', function (string $resource, string $fault): void {
        [$instance, $projection, $serving, $transport] = serving_projection_fixture($this->root);
        $adapter = $resource === 'serving' ? $serving : serving_projection_environment($instance, $projection, $transport, $this->root);
        $plan = $resource === 'serving' ? serving_projection_step($projection, $serving) : new AppProjectionStepPlan('prepare-env', 1, 'prepare', 'web', 'environment', 'prepare', $adapter->targetIdentities(), 'restore');
        $transport->accessInterrupt = 'file-command';
        $runner = app(RunInstanceAppProjectionAction::class);
        expect(fn () => $runner->step($projection, $plan, $adapter))->toThrow(Exception::class);
        $source = InstanceAppProjectionStep::query()->sole();
        $path = $this->root.'/access-receipts/'.$source->receipt_id.'/manifest.json';
        $cache = $this->root.'/checkout/new/bootstrap/cache/config.php';
        $before = file_get_contents($cache);
        if ($fault === 'missing') {
            unlink($path);
        } elseif ($fault === 'damaged') {
            file_put_contents($path, '{}');
        } else {
            new Process(['setfacl', '-x', 'u:caddy', $cache])->mustRun();
        }
        $acl = new Process(['getfacl', '-cp', $cache])->mustRun()->getOutput();
        expect(fn () => $runner->step($projection, $plan, $adapter))->toThrow(Exception::class);
        $restore = $resource === 'serving' ? serving_projection_step($projection, $serving, 'restore', source: $source)
            : new AppProjectionStepPlan('restore-env', 2, 'restore', 'web', 'environment', 'restore', [...$adapter->targetIdentities(), 'restores_step_id' => $source->id], 'restore');
        expect(fn () => $runner->step($projection, $restore, $adapter))->toThrow(Exception::class);
        expect(file_get_contents($cache))->toBe($before)->and(new Process(['getfacl', '-cp', $cache])->mustRun()->getOutput())->toBe($acl)
            ->and($projection->fresh()->active_instance_id)->toBe($instance->id);
    })->with(['serving', 'environment'])->with(['missing', 'damaged', 'foreign-acl']);
});

function serving_projection_environment(Instance $instance, InstanceAppProjection $projection, LocalAppProjectionServingTransport $transport, string $root): NativeAppProjectionEnvironment
{
    $published = app(InstanceEnvironmentContextResolver::class)->resolveForProjection($instance, $projection->id, 'web');
    $candidate = new InstanceEnvironmentContext($published->instanceId, $published->projectId, $published->nodeId, $published->environment,
        $root.'/checkout/new', $published->executionUser, true, $published->routeId, $published->routeDomain, $published->nodeStatus, $published->node, app: 'web');
    $renderer = app(InstanceEnvironmentRenderer::class);
    file_put_contents($published->path.'/.env', $renderer->render($published, ['APP_URL' => 'published-app-url']));
    chmod($published->path.'/.env', 0640);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/unused/key');
    $hosts = Mockery::mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/unused/hosts');

    return new NativeAppProjectionEnvironment(new DevelopmentSshExecutor($transport, $keys, $hosts), app(InstanceEnvironmentStore::class), $renderer,
        app(InstanceTestEnvironment::class), $published, [new AppProjectionEnvironmentTarget('stable', $published, $candidate, $instance->checkout_path, $instance->checkout_path, 'stable-home')]);
}

function serving_projection_pools(Instance $instance): string
{
    return new DevelopmentPhpFpmConfigRenderer()->render(new DevelopmentSiteRepository()->forNode($instance->node), serving_projection_account());
}

function serving_projection_step(InstanceAppProjection $projection, NativeAppProjectionServingRuntime $adapter, string $phase = 'prepare', string $app = 'web', ?InstanceAppProjectionStep $source = null, ?int $sequence = null): AppProjectionStepPlan
{
    $targets = $adapter->targetIdentities($projection, $app);
    if ($source !== null) {
        $targets[$phase === 'restore' ? 'restores_step_id' : 'cleans_step_id'] = $source->id;
    }

    return new AppProjectionStepPlan($phase.'-'.$app, $sequence ?? ($phase === 'prepare' ? 1 : 2), $phase, $app, 'serving', $phase, $targets, 'restore');
}

/** @return array{Instance, InstanceAppProjection, NativeAppProjectionServingRuntime, LocalAppProjectionServingTransport, NodeCaddyBuilder} */
function serving_projection_fixture(string $root, string $mode = 'change'): array
{
    foreach (['old', 'new', 'sibling', 'added'] as $path) {
        mkdir($root.'/checkout/'.$path.'/bootstrap/cache', 0755, true);
        mkdir($root.'/checkout/'.$path.'/public', 0755, true);
        file_put_contents($root.'/checkout/'.$path.'/composer.json', json_encode(['require' => ['php' => $path === 'old' || $path === 'sibling' ? '~8.5.0' : '~8.4.0', 'laravel/framework' => '^13.0']], JSON_THROW_ON_ERROR));
        file_put_contents($root.'/checkout/'.$path.'/artisan', '<?php');
        file_put_contents($root.'/checkout/'.$path.'/bootstrap/cache/config.php', "<?php return ['app' => ['url' => '".$path."-url'], 'other' => ['url' => 'do-not-change']];");
        chmod($root.'/checkout/'.$path.'/bootstrap/cache/config.php', 0640);
    }
    new Process(['git', '-C', $root.'/checkout', 'init', '--quiet'])->mustRun();
    $node = Node::query()->create(['name' => 'serving-projection', 'user' => serving_projection_account()->user, 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.20', 'wireguard_ip' => '10.44.0.3']);
    $node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $apps = ['web' => ['name' => 'web', 'path' => 'old', 'web_root' => 'public', 'type' => 'laravel-app'], 'sibling' => ['name' => 'sibling', 'path' => 'sibling', 'web_root' => 'public', 'type' => 'laravel-app']];
    $profiles = ['web' => ['php_version' => '8.5', 'laravel' => true], 'sibling' => ['php_version' => '8.5', 'laravel' => true]];
    if ($mode === 'legacy') {
        $profiles['web']['app_identity'] = false;
    }
    if ($mode === 'static') {
        unlink($root.'/checkout/new/composer.json');
        unlink($root.'/checkout/new/artisan');
    }
    $project = Project::query()->create(['name' => 'Serving projection', 'slug' => 'serving-projection', 'repository_url' => 'git@example.test:projection.git', 'apps' => array_values($apps)]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'environment' => 'development', 'checkout_path' => $root.'/checkout', 'source_layout' => 'checkout', 'branch' => 'main', 'provisioning_step' => 'active', 'status' => 'active', 'app_runtime' => $profiles]);
    InstanceEnvironmentValue::query()->create(['instance_id' => $instance->id, 'app' => 'web', 'env_key' => 'APP_URL', 'env_value' => 'published-app-url']);
    $resources = [];
    foreach (['web', 'sibling', ...($mode === 'add' ? ['added'] : [])] as $app) {
        $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'app' => $app, 'domain' => $app.'.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending', 'sites_published' => $app !== 'added']);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        if ($app !== 'added') {
            $route->update(['status' => 'active']);
        }
        $resources[$app] = ['route_id' => $route->id, 'domain' => $route->domain, 'app_identity' => ! ($mode === 'legacy' && $app === 'web')];
    }
    $candidate = $apps;
    $candidateProfiles = $profiles;
    if ($mode === 'remove') {
        unset($candidate['web'], $candidateProfiles['web']);
    } elseif ($mode === 'add') {
        $candidate['added'] = ['name' => 'added', 'path' => 'added', 'web_root' => 'public', 'type' => 'laravel-app'];
        $candidateProfiles['added'] = ['php_version' => '8.4', 'laravel' => true];
    } else {
        $candidate['web']['path'] = 'new';
        $candidateProfiles['web']['php_version'] = '8.4';
        if ($mode === 'static') {
            $candidate['web']['type'] = 'node-package';
            $candidateProfiles['web'] = ['php_version' => null, 'laravel' => false];
        }
    }
    $plan = new AppProjectionPlan($instance->id, $node->id, $apps, $candidate, $profiles, $candidateProfiles, ['checkout_path' => $instance->checkout_path], null, ['serving' => $resources]);
    $projection = app(ReserveInstanceAppProjectionsAction::class)->instance($instance, ['serving' => $mode], $plan, fn (InstanceAppUpdate $owner, InstanceAppProjection $child): InstanceAppProjection => $child);
    $transport = new LocalAppProjectionServingTransport($root);
    $transport->installed['8.5'] = serving_projection_pools($instance);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/unused/key');
    $hosts = Mockery::mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/unused/hosts');
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldReceive('resolve')->andReturn(serving_projection_account());
    $ssh = new DevelopmentSshExecutor($transport, $keys, $hosts);
    $processes = Mockery::mock(ProcessRunner::class);
    $processes->shouldReceive('run')->andReturn(new CommandResult(0, '', '', 0, false));
    $signer = Mockery::mock(LeafCertificateSigner::class);
    $signer->shouldReceive('rootCertificate')->andReturn('test-root');
    $sites = new DevelopmentSiteRepository;
    $builder = new NodeCaddyBuilder(new NodeCaddyfileRenderer([new RouteCaddySiteSource($sites, new DevelopmentCaddyConfigRenderer)]), app(NodeCaddyBuildLock::class), new NodeCaddyTransport($processes, $transport, $keys, $hosts, app(GatewayServingHost::class)));
    $adapter = new NativeAppProjectionServingRuntime($ssh, $accounts, new RemoteDevelopmentInstanceConfigurator($ssh, $accounts, app(ComposerSourceClassifier::class)),
        new RemoteAppDevPhpFpmManager($sites, new DevelopmentPhpFpmConfigRenderer, $ssh, $accounts, new RemotePhpPackageManager),
        new RemoteAppDevCertificateManager($ssh, $signer, $accounts, $sites), new RemoteAppDevCaddyManager($builder, $ssh),
        new DnsmasqPrivateDnsManager($processes, new DevelopmentDnsConfigRenderer($sites)), app(DevelopmentProjectionOperationLock::class));
    CaddySiteCertificateFixtures::recordAll($node);

    return [$instance, $projection, $adapter, $transport, $builder];
}
