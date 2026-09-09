<?php

declare(strict_types=1);

use App\Actions\AppInstances\UpdateAppInstanceEnvironmentAction;
use App\Domain\AppInstances\AppInstanceRemovalStatus;
use App\Domain\AppInstances\AppInstanceRemovalStep;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContextResolver;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentStore;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteHostnameChangeDirection;
use App\Domain\Routes\RouteHostnameChangeStep;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceEnvironmentValue;
use App\Models\AppInstanceRemoval;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('serializes concurrent complete-result validation through SQLite immediate transactions', function (): void {
    $directory = sys_get_temp_dir().'/orbit-env-concurrency-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $database = "{$directory}/gateway.sqlite";
    $script = "{$directory}/worker.php";
    $barrier = "{$directory}/go";
    file_put_contents($script, orb207_concurrency_worker_script());

    try {
        $setup = new Process([PHP_BINARY, $script, base_path(), $database, $directory, 'setup']);
        $setup->mustRun();

        $import = new Process([PHP_BINARY, $script, base_path(), $database, $directory, 'import']);
        $update = new Process([PHP_BINARY, $script, base_path(), $database, $directory, 'update']);
        $import->start();
        $update->start();

        $deadline = microtime(true) + 5;
        while (
            (! file_exists("{$directory}/import.ready")
            || ! file_exists("{$directory}/update.ready"))
            && microtime(true) < $deadline
        ) {
            usleep(1_000);
        }

        if (! file_exists("{$directory}/import.ready") || ! file_exists("{$directory}/update.ready")) {
            touch($barrier);
            $import->wait();
            $update->wait();
            throw new RuntimeException(json_encode([
                'import' => [$import->getOutput(), $import->getErrorOutput()],
                'update' => [$update->getOutput(), $update->getErrorOutput()],
            ], JSON_THROW_ON_ERROR));
        }

        expect(file_exists("{$directory}/import.ready"))
            ->toBeTrue()
            ->and(file_exists("{$directory}/update.ready"))
            ->toBeTrue();
        touch($barrier);
        $import->wait();
        $update->wait();

        expect($import->isSuccessful())->toBeTrue()->and($update->isSuccessful())->toBeTrue();
        $results = [
            json_decode((string) file_get_contents("{$directory}/import.result"), true, flags: JSON_THROW_ON_ERROR),
            json_decode((string) file_get_contents("{$directory}/update.result"), true, flags: JSON_THROW_ON_ERROR),
        ];
        $statuses = collect($results)->pluck('status')->sort()->values()->all();

        expect($statuses)
            ->toBe(['invalid', 'ok'])
            ->and(collect($results)->firstWhere('status', 'invalid')['code'] ?? null)
            ->toBe('env.configuration_invalid');

        $inspect = new Process([PHP_BINARY, $script, base_path(), $database, $directory, 'inspect']);
        $inspect->mustRun();
        $stored = json_decode($inspect->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($stored['count'])
            ->toBeIn([1, 1_024])
            ->and($stored['keys'])
            ->toBeIn([['EXTRA'], ['KEY_0', 'KEY_1023']]);
    } finally {
        foreach (glob("{$directory}/*") ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
});

it('refuses a stale update after the supported removal boundary enters removing', function (): void {
    [$instance] = orb207_concurrency_fixture();
    $instance->environmentValues()->create(['env_key' => 'EXISTING', 'env_value' => 'kept']);
    $context = app(AppInstanceEnvironmentContextResolver::class)->resolve($instance, false);

    orb207_begin_recorded_removal($instance);

    try {
        app(AppInstanceEnvironmentStore::class)->update($context, 'NEW', 'must-not-attach');
        $this->fail('The stale update unexpectedly committed.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('env.owner_changed');
    }

    expect($instance->refresh()->status)
        ->toBe(AppInstanceState::Removing)
        ->and($instance->removalMember)
        ->not
        ->toBeNull()
        ->and(AppInstanceEnvironmentValue::query()->pluck('env_key')->all())
        ->toBe(['EXISTING']);
});

it('refuses a stale import across the recorded Route hostname transition without a partial change', function (): void {
    [$instance, $route] = orb207_concurrency_fixture();
    $instance->environmentValues()->create(['env_key' => 'EXISTING', 'env_value' => 'kept']);
    $context = app(AppInstanceEnvironmentContextResolver::class)->resolve($instance, true);

    DB::transaction(function () use ($route): void {
        Route::query()
            ->lockForUpdate()
            ->findOrFail($route->id)
            ->update([
                'hostname_change_previous' => $route->hostname,
                'hostname_change_target' => 'next.example.test',
                'hostname_change_direction' => RouteHostnameChangeDirection::Forward,
                'hostname_change_step' => RouteHostnameChangeStep::Reserved,
            ]);
    });

    try {
        app(AppInstanceEnvironmentStore::class)->import(
            $context,
            [
                'EXISTING' => 'replaced',
                'NEW' => 'must-not-attach',
            ],
            true,
        );
        $this->fail('The stale import unexpectedly committed.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('env.owner_changed');
    }

    expect(AppInstanceEnvironmentValue::query()->pluck('env_key')->all())
        ->toBe(['EXISTING'])
        ->and($instance->environmentValues()->sole()->env_value)
        ->toBe('kept');
});

it('marks every submitted and parsed value frame as sensitive', function (): void {
    [$instance] = orb207_concurrency_fixture();
    $sentinel = 'arbitrary-visible-value-'.Str::random(12);
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        app(UpdateAppInstanceEnvironmentAction::class)->execute($instance, 'KEY', $sentinel."\0");
        $this->fail('The invalid value unexpectedly committed.');
    } catch (ResourceOperationException $exception) {
        $trace = $exception->getTrace();
        $frames = collect($trace)->whereIn('function', ['execute', 'update', 'validate']);

        expect($frames->pluck('function')->all())->toContain('execute', 'update', 'validate');

        foreach ($frames as $frame) {
            expect(collect($frame['args'] ?? [])
                ->contains(
                    static fn (mixed $argument): bool => $argument instanceof SensitiveParameterValue,
                ))
                ->toBeTrue()
                ->and(json_encode($frame['args'] ?? [], JSON_PARTIAL_OUTPUT_ON_ERROR))
                ->not->toContain($sentinel);
        }
    } finally {
        if (is_string($previous)) {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    expect(AppInstanceEnvironmentValue::query()->count())->toBe(0);
});

/** @return array{AppInstance, Route} */
function orb207_concurrency_fixture(): array
{
    $app = OrbitApp::query()->create([
        'name' => 'Concurrency',
        'slug' => 'concurrency',
        'repository_url' => 'https://example.test/concurrency.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'concurrency-owner',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.210',
        'wireguard_ip' => '10.44.0.210',
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'default',
        'environment' => 'development',
        'source_layout' => AppInstanceSourceLayout::Checkout,
        'checkout_path' => '/srv/orbit/apps/concurrency/default',
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => AppInstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'hostname' => 'concurrency.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => AppInstanceState::Active]);

    return [$instance->fresh(['app', 'node']), $route->fresh()];
}

function orb207_begin_recorded_removal(AppInstance $instance): void
{
    DB::transaction(function () use ($instance): void {
        $locked = AppInstance::query()->lockForUpdate()->findOrFail($instance->id);
        $route = $locked->routes()->sole();
        $removal = AppInstanceRemoval::query()->create([
            'id' => (string) Str::uuid(),
            'requested_app_instance_id' => $locked->id,
            'requested_name' => $locked->name,
            'force' => false,
            'inventory_digest' => str_repeat('b', 64),
            'total' => 1,
            'status' => AppInstanceRemovalStatus::Removing,
            'current_step' => AppInstanceRemovalStep::SourcePreparation,
        ]);
        $removal
            ->members()
            ->create([
                'position' => 0,
                'app_instance_id' => $locked->id,
                'app_id' => $locked->app_id,
                'node_id' => $locked->node_id,
                'route_id' => $route->id,
                'name' => $locked->name,
                'environment' => $locked->environment,
                'source_layout' => $locked->source_layout,
                'repository_identity' => $locked->app->repository_identity,
                'checkout_path' => $locked->checkout_path,
                'root' => $locked->effectiveRoot(),
                'branch' => $locked->branch,
                'starting_commit' => $locked->starting_commit,
                'source_commit' => $locked->starting_commit,
                'common_repository_path' => $locked->checkout_path,
                'source_identity' => "test:{$locked->id}",
                'linked_worktree_paths' => [$locked->checkout_path],
                'source_digest' => str_repeat('c', 64),
            ]);
        $locked->update(['status' => AppInstanceState::Removing]);
    });
}

function orb207_concurrency_worker_script(): string
{
    return <<<'PHP'
        <?php

        declare(strict_types=1);

        [$script, $base, $database, $directory, $mode] = $argv;
        $environment = [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'ORBIT_HOME' => "{$directory}/orbit-home",
        ];
        foreach ($environment as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
        require "{$base}/vendor/autoload.php";
        $app = require "{$base}/bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        if ($mode === 'setup') {
            Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--force' => true]);
            $orbitApp = App\Models\App::query()->create([
                'name' => 'Concurrent worker',
                'slug' => 'concurrent-worker',
                'repository_url' => 'https://example.test/concurrent-worker.git',
                'default_branch' => 'main',
                'root' => 'public',
            ]);
            $node = App\Models\Node::query()->create([
                'name' => 'concurrent-worker',
                'status' => 'active',
                'platform' => 'linux',
                'public_ssh_host' => '192.0.2.220',
                'wireguard_ip' => '10.44.0.220',
                'user' => 'orbit',
            ]);
            $instance = App\Models\AppInstance::query()->create([
                'app_id' => $orbitApp->id,
                'node_id' => $node->id,
                'name' => 'default',
                'environment' => 'development',
                'checkout_path' => '/srv/orbit/apps/concurrent-worker/default',
                'source_is_laravel' => false,
                'provisioning_step' => 'active',
                'status' => 'source_resolved',
            ]);
            $route = App\Models\Route::query()->create([
                'app_id' => $orbitApp->id,
                'node_id' => $node->id,
                'hostname' => 'concurrent-worker.example.test',
                'provenance' => 'explicit',
                'publication' => 'private',
                'status' => 'pending',
            ]);
            $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
            $route->update(['status' => 'active']);
            $instance->update(['status' => 'active']);
            exit(0);
        }

        if ($mode === 'inspect') {
            $query = App\Models\AppInstanceEnvironmentValue::query();
            $count = $query->count();
            $keys = $query->whereIn('env_key', ['EXTRA', 'KEY_0', 'KEY_1023'])->orderBy('env_key')->pluck('env_key')->all();
            echo json_encode(['count' => $count, 'keys' => $keys], JSON_THROW_ON_ERROR);
            exit(0);
        }

        $instance = App\Models\AppInstance::query()->firstOrFail();
        $context = app(App\Domain\AppInstances\Environment\AppInstanceEnvironmentContextResolver::class)
            ->resolve($instance, false);
        touch("{$directory}/{$mode}.ready");
        while (! file_exists("{$directory}/go")) {
            usleep(1_000);
        }

        try {
            $store = app(App\Domain\AppInstances\Environment\AppInstanceEnvironmentStore::class);
            if ($mode === 'import') {
                $values = [];
                for ($index = 0; $index < 1_024; $index++) {
                    $values["KEY_{$index}"] = 'value';
                }
                $store->import($context, $values, false);
            } else {
                $store->update($context, 'EXTRA', 'value');
            }
            $result = ['status' => 'ok'];
        } catch (App\Domain\Shared\ResourceOperationException $exception) {
            $result = ['status' => 'invalid', 'code' => $exception->errorCode];
        } catch (Throwable $exception) {
            $result = ['status' => get_class($exception), 'message' => $exception->getMessage()];
        }

        file_put_contents("{$directory}/{$mode}.result", json_encode($result, JSON_THROW_ON_ERROR));
        PHP;
}
