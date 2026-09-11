<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\DeploymentLayout\DeploymentLayoutInventory;
use App\Domain\AppInstances\ProductionPhpRuntimeIdentity;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppInstances\RemoteProductionLayoutConverter;
use App\Infrastructure\AppProd\AppProdSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\AppDevFakeSshExecutor;

it('inventories without mutation and emits idempotent authenticated conversion effects', function (): void {
    [$instance, $route] = orb217_remote_conversion_fixture();
    $tuning = <<<'FPM'
        [orbit-orbit-app-217]
        pm = ondemand
        pm.max_children = 7
        env[APP_FLAG] = enabled
        php_admin_value[memory_limit] = 1G
        FPM;
    $tuning .= "\n";
    $payload = [
        'source_path' => '/home/orbit-app-217',
        'release_path' => '/home/orbit-app-217/releases/initial',
        'source_identity' => '8:217',
        'git_head' => str_repeat('a', 40),
        'git_state_hash' => hash('sha256', 'dirty'),
        'environment_hash' => hash('sha256', "KEY=value\n"),
        'sqlite_source_path' => '/home/orbit-app-217/storage/app.sqlite',
        'sqlite_hash' => hash('sha256', 'sqlite'),
        'sqlite_identity' => '8:218',
        'local_tuning_hash' => hash('sha256', $tuning),
        'local_tuning' => base64_encode($tuning),
        'route_id' => $route->id,
        'route_node_id' => $route->node_id,
        'route_hostname' => $route->hostname,
        'route_status' => $route->status->value,
        'route_targets_hash' => hash('sha256', json_encode([
            ['app_instance_id' => $instance->id, 'position' => 0],
        ], JSON_THROW_ON_ERROR)),
        'document_root' => 'public',
        'previous_socket' => "/run/php/orbit-app-instance-{$instance->id}.sock",
    ];
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, json_encode($payload, JSON_THROW_ON_ERROR)."\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, $tuning, '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
    ]);
    $converter = new RemoteProductionLayoutConverter(orb217_app_prod_executor($ssh));

    $inventory = $converter->preflight($instance, $route, "KEY=value\n", 'storage/app.sqlite');
    $converter->moveSource($instance, $inventory);
    $converter->assertSqliteQuiescent($instance, $inventory);
    $converter->placePersistentState($instance, $inventory);
    $actualTuning = $converter->runtimeTuning($instance, $inventory);
    $instance->update(['checkout_path' => $inventory->releasePath]);
    $instance->update(ProductionPhpRuntimeIdentity::forProvisioning($instance->refresh(), '8.5')->attributes());
    $instance->refresh();
    $converter->validateServingAssociation($instance, $inventory);
    $converter->validatePlacedLayout($instance, $inventory);

    $preflight = $ssh->commands[0];
    $move = $ssh->commands[1]->input ?? '';
    $quiescence = implode(' ', $ssh->commands[2]->arguments);
    $persistent = implode(' ', $ssh->commands[3]->arguments);
    $runtimeCommand = $ssh->commands[4];
    $runtime = implode(' ', $runtimeCommand->arguments);
    $serving = $ssh->commands[5]->input ?? '';
    $validation = $ssh->commands[6]->input ?? '';

    expect($inventory)
        ->toBeInstanceOf(DeploymentLayoutInventory::class)
        ->and($inventory->toArray())
        ->not->toHaveKeys(['local_tuning', 'environment'])
        ->and($actualTuning)
        ->toBe($tuning)
        ->and($preflight->arguments)
        ->toContain('python3', '/home/orbit-app-217', 'storage/app.sqlite')
        ->and($preflight->arguments[4])
        ->toContain(
            'status", "--porcelain=v2", "-z"',
            'SQLite source has an open file handle',
            'os.path.realpath(candidate) != candidate',
            'shared PHP tuning contains an unsupported directive',
            'production source has unexpected ownership',
            'document root contains a symbolic link',
            'SQLite source has an uncheckpointed sidecar',
            'php_admin_value[opcache.validate_timestamps]',
            'source_marker',
            'dedicated_runtime',
        )
        ->not->toContain('os.rename(', 'os.replace(', 'os.unlink(')
        ->and($move)
        ->toContain(
            'mv -- "$home" "$staging"',
            'mv -- "$staging" "$release"',
            'release-layout',
            'expected_identity',
        )
        ->not->toContain('git fetch', 'git reset', 'git clean', 'git checkout')
        ->and($quiescence)
        ->toContain('os.O_NOFOLLOW', 'expected_identity', 'os.listdir("/proc")', 'sqlite_sidecar_exists')
        ->and($persistent)
        ->toContain(
            'os.rename(".env", ".env"',
            'os.rename(source_leaf, "database.sqlite"',
            'os.O_NOFOLLOW',
            'environment_source is None and environment_destination is not None',
            'not source_exists and database_exists',
            'sqlite_sidecar_exists(selected_path)',
        )
        ->and($runtime)
        ->toContain(
            "orbit-app-instance-{$instance->id}",
            'orbit-orbit-app-217',
            'key.startswith("env[")',
            'key.startswith("php_admin_value[")',
        )
        ->and($serving)
        ->toContain('php_fastcgi unix/$socket {', 'root * $home/$document_root')
        ->and($validation)
        ->toContain(
            'test -S "$socket"',
            'realpath -e -- "$home/current"',
            'php_fastcgi unix/$socket {',
            'systemctl is-active',
            'database.sqlite',
            'expected_marker',
        );

    $pool = tempnam(sys_get_temp_dir(), 'orb217-pool-');

    if (! is_string($pool)) {
        throw new RuntimeException('Unable to create the PHP pool fixture.');
    }

    try {
        file_put_contents($pool, <<<FPM
            [orbit-app-instance-{$instance->id}]
            user = orbit-app-217
            group = orbit-app-217
            listen = /run/php/orbit-app-instance-{$instance->id}.sock
            chdir = /home/orbit-app-217
            clear_env = yes
            env[HOME] = /home/orbit-app-217
            env[USER] = orbit-app-217
            env[PATH] = /usr/local/bin:/opt/orbit/composer/vendor/bin:/usr/bin:/bin
            php_admin_value[opcache.validate_timestamps] = 0
            pm = ondemand
            pm.max_children = 7
            env[APP_FLAG] = enabled
            php_admin_value[memory_limit] = 1G

            FPM);
        $runtimeResult = orb217_run_program([
            'python3',
            '-c',
            $runtimeCommand->arguments[3],
            $pool,
            ...array_slice($runtimeCommand->arguments, 5),
        ]);

        expect($runtimeResult->succeeded())
            ->toBeTrue($runtimeResult->stderr)
            ->and($runtimeResult->stdout)
            ->toBe($tuning)
            ->not->toContain(
                'env[HOME]',
                'env[USER]',
                'env[PATH]',
                'php_admin_value[opcache.validate_timestamps]',
            );
    } finally {
        @unlink($pool);
    }
});

it('refuses SQLite sidecars during quiescence and before persistent mutation', function (): void {
    $root = sys_get_temp_dir().'/orbit-layout-sidecars-'.bin2hex(random_bytes(8));
    $home = "{$root}/home";
    $release = "{$home}/releases/initial";
    $database = "{$release}/storage/app.sqlite";
    $files = new Filesystem;
    $files->ensureDirectoryExists(dirname($database));
    file_put_contents("{$release}/.env", "KEY=value\n");
    file_put_contents($database, 'sqlite');
    $metadata = stat($database);
    $account = posix_getpwuid(posix_geteuid());

    if (! is_array($metadata) || ! is_array($account)) {
        throw new RuntimeException('Unable to inspect the SQLite sidecar fixture.');
    }

    $identity = "{$metadata['dev']}:{$metadata['ino']}";
    $hash = hash_file('sha256', $database);

    if (! is_string($hash)) {
        throw new RuntimeException('Unable to hash the SQLite sidecar fixture.');
    }
    $quiescence = orb217_converter_program('SqliteQuiescenceProgram');
    $persistent = orb217_converter_program('PersistentStateProgram');

    try {
        foreach (['-wal', '-shm', '-journal'] as $suffix) {
            file_put_contents($database.$suffix, 'sidecar');
            $result = orb217_run_program([
                'python3', '-c', $quiescence,
                $home, $release, 'storage/app.sqlite', $identity, $hash, $account['name'],
            ]);

            expect($result->exitCode)->toBe(42);
            unlink($database.$suffix);
        }

        file_put_contents("{$home}/database.sqlite-wal", 'stale destination');
        $destinationResult = orb217_run_program([
            'python3', '-c', $quiescence,
            $home, $release, 'storage/app.sqlite', $identity, $hash, $account['name'],
        ]);

        expect($destinationResult->exitCode)->toBe(42);
        unlink("{$home}/database.sqlite-wal");

        file_put_contents($database.'-journal', 'hot journal');
        $placementResult = orb217_run_program([
            'python3', '-c', $persistent,
            $home,
            $release,
            hash('sha256', "KEY=value\n"),
            'storage/app.sqlite',
            $hash,
            $identity,
            $account['name'],
        ]);

        expect($placementResult->exitCode)
            ->toBe(42)
            ->and(file_exists($database))
            ->toBeTrue()
            ->and(file_exists("{$release}/.env"))
            ->toBeTrue()
            ->and(file_exists("{$home}/database.sqlite"))
            ->toBeFalse()
            ->and(file_exists("{$home}/.env"))
            ->toBeFalse();

        unlink($database.'-journal');
        file_put_contents("{$home}/database.sqlite-shm", 'stale destination');
        $destinationPlacementResult = orb217_run_program([
            'python3', '-c', $persistent,
            $home,
            $release,
            hash('sha256', "KEY=value\n"),
            'storage/app.sqlite',
            $hash,
            $identity,
            $account['name'],
        ]);

        expect($destinationPlacementResult->exitCode)
            ->toBe(42)
            ->and(file_exists($database))
            ->toBeTrue()
            ->and(file_exists("{$release}/.env"))
            ->toBeTrue();

        unlink("{$home}/database.sqlite-shm");
        $successfulPlacement = orb217_run_program([
            'python3', '-c', $persistent,
            $home,
            $release,
            hash('sha256', "KEY=value\n"),
            'storage/app.sqlite',
            $hash,
            $identity,
            $account['name'],
        ]);

        expect($successfulPlacement->succeeded())
            ->toBeTrue($successfulPlacement->stderr)
            ->and(file_exists("{$home}/database.sqlite"))
            ->toBeTrue()
            ->and(is_link($database))
            ->toBeTrue()
            ->and(realpath($database))
            ->toBe("{$home}/database.sqlite")
            ->and(file_exists("{$home}/.env"))
            ->toBeTrue()
            ->and(is_link("{$release}/.env"))
            ->toBeTrue();
    } finally {
        $files->deleteDirectory($root);
    }
});

it('refuses unsafe Caddy source access prerequisites without changing the flat source', function (): void {
    $root = sys_get_temp_dir().'/orbit-layout-caddy-access-'.bin2hex(random_bytes(8));
    $home = "{$root}/home";
    $documentRoot = "{$home}/public";
    $files = new Filesystem;
    $files->ensureDirectoryExists($documentRoot);
    file_put_contents("{$documentRoot}/index.php", 'unchanged');
    $metadata = stat($home);

    if (! is_array($metadata)) {
        throw new RuntimeException('Unable to inspect the Caddy source fixture.');
    }

    $program = orb217_converter_program('SourceAccessFunctions').<<<'PYTHON'

        import os, stat, sys

        home, document_root, uid, gid, device = sys.argv[1:]

        def refuse(message):
            print(message, file=sys.stderr)
            raise SystemExit(42)

        inspect_source_access(home, document_root, int(uid), int(gid), int(device), refuse)
        PYTHON;
    $arguments = [
        'python3', '-c', $program, $home, 'public',
        (string) posix_geteuid(),
        (string) posix_getegid(),
        (string) $metadata['dev'],
    ];

    try {
        expect(orb217_run_program($arguments)->succeeded())->toBeTrue();

        symlink('../storage', "{$documentRoot}/storage");
        $symlinkResult = orb217_run_program($arguments);

        expect($symlinkResult->exitCode)
            ->toBe(42)
            ->and($symlinkResult->stderr)
            ->toContain('document root contains a symbolic link')
            ->and(file_get_contents("{$documentRoot}/index.php"))
            ->toBe('unchanged')
            ->and(readlink("{$documentRoot}/storage"))
            ->toBe('../storage');

        unlink("{$documentRoot}/storage");
        file_put_contents("{$home}/foreign-group.txt", 'unchanged ownership fixture');
        $foreignGroupArguments = $arguments;
        $foreignGroupArguments[6] = (string) (posix_getegid() + 1);
        $ownershipResult = orb217_run_program($foreignGroupArguments);

        expect($ownershipResult->exitCode)
            ->toBe(42)
            ->and($ownershipResult->stderr)
            ->toContain('production source has unexpected ownership')
            ->and(file_get_contents("{$home}/foreign-group.txt"))
            ->toBe('unchanged ownership fixture');
    } finally {
        $files->deleteDirectory($root);
    }
});

it('refuses a SQLite source below the document root without changing the flat source', function (): void {
    $root = sys_get_temp_dir().'/orbit-layout-served-sqlite-'.bin2hex(random_bytes(8));
    $home = "{$root}/home";
    $documentRoot = "{$home}/public";
    $database = "{$documentRoot}/app.sqlite";
    $files = new Filesystem;
    $files->ensureDirectoryExists($documentRoot);
    file_put_contents("{$documentRoot}/index.php", 'unchanged serving response');
    file_put_contents("{$home}/.env", "KEY=value\n");
    file_put_contents($database, "SQLite format 3\x00unchanged database bytes");
    $metadata = stat($home);

    if (! is_array($metadata)) {
        throw new RuntimeException('Unable to inspect the served SQLite fixture.');
    }

    $sourceHash = hash_file('sha256', "{$documentRoot}/index.php");
    $databaseHash = hash_file('sha256', $database);
    $environmentHash = hash_file('sha256', "{$home}/.env");

    if (! is_string($sourceHash) || ! is_string($databaseHash) || ! is_string($environmentHash)) {
        throw new RuntimeException('Unable to hash the served SQLite fixture.');
    }

    $program = orb217_converter_program('SourceAccessFunctions').<<<'PYTHON'

        import os, stat, sys

        home, document_root, sqlite_source, uid, gid, device = sys.argv[1:]

        def refuse(message):
            print(message, file=sys.stderr)
            raise SystemExit(42)

        document_root_path = inspect_source_access(home, document_root, int(uid), int(gid), int(device), refuse)
        inspect_sqlite_source_location(sqlite_source, document_root_path, refuse)
        PYTHON;

    try {
        $result = orb217_run_program([
            'python3', '-c', $program, $home, 'public', $database,
            (string) posix_geteuid(),
            (string) posix_getegid(),
            (string) $metadata['dev'],
        ]);

        expect($result->exitCode)
            ->toBe(42)
            ->and($result->stderr)
            ->toContain('SQLite source is inside the document root')
            ->and(hash_file('sha256', "{$documentRoot}/index.php"))
            ->toBe($sourceHash)
            ->and(hash_file('sha256', $database))
            ->toBe($databaseHash)
            ->and(hash_file('sha256', "{$home}/.env"))
            ->toBe($environmentHash)
            ->and(file_exists("{$home}/releases"))
            ->toBeFalse()
            ->and(file_exists("{$home}/current"))
            ->toBeFalse()
            ->and(file_exists("{$home}/database.sqlite"))
            ->toBeFalse();
    } finally {
        $files->deleteDirectory($root);
    }
});

it('maps expected preflight refusal to a bounded conflict and preserves transport failures', function (): void {
    [$instance, $route] = orb217_remote_conversion_fixture();
    $refused = new RemoteProductionLayoutConverter(orb217_app_prod_executor(new AppDevFakeSshExecutor([
        new CommandResult(42, '', 'sensitive remote detail', 1, false),
    ])));

    expect(fn () => $refused->preflight($instance, $route, "KEY=value\n", null))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->status)
                ->toBe(409)
                ->and($exception->getMessage())
                ->toBe('The deployment-layout preflight refused conversion.')
                ->not->toContain('sensitive remote detail');
        });

    $transport = new RemoteProductionLayoutConverter(orb217_app_prod_executor(new AppDevFakeSshExecutor([
        new CommandResult(255, '', 'transport detail', 1, false),
    ])));

    expect(fn () => $transport->preflight($instance, $route, "KEY=value\n", null))
        ->toThrow(RuntimeConvergenceException::class);
});

/** @return array{AppInstance, Route} */
function orb217_remote_conversion_fixture(): array
{
    $node = Node::query()->create([
        'name' => 'layout-remote',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.217',
        'wireguard_ip' => '10.44.0.217',
        'user' => 'orbit',
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Layout remote',
        'slug' => 'layout-remote',
        'repository_url' => 'https://example.test/layout-remote.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = AppInstance::query()->create([
        'id' => 217,
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'default',
        'environment' => 'production',
        'checkout_path' => '/home/orbit-app-217',
        'production_user' => 'orbit-app-217',
        'production_home' => '/home/orbit-app-217',
        'selected_php_version' => '8.5',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'hostname' => 'layout-remote.test',
        'provenance' => 'explicit',
        'publication' => 'private',
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);

    return [$instance->fresh(['app', 'node']), $route];
}

function orb217_app_prod_executor(AppDevFakeSshExecutor $ssh): AppProdSshExecutor
{
    return new AppProdSshExecutor(
        $ssh,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/tmp/orbit-layout-key';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 synthetic';
            }
        },
        new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/tmp/orbit-layout-known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    );
}

function orb217_converter_program(string $name): string
{
    $constant = new ReflectionClass(RemoteProductionLayoutConverter::class)->getReflectionConstant($name);

    if ($constant === false) {
        throw new RuntimeException("Converter program {$name} is unavailable.");
    }

    $program = $constant->getValue();

    if (! is_string($program)) {
        throw new RuntimeException("Converter program {$name} is invalid.");
    }

    return $program;
}

/** @param non-empty-list<string> $arguments */
function orb217_run_program(array $arguments): CommandResult
{
    return new NativeProcessRunner(maxOutputBytes: 16_384)->run(new ProcessInvocation($arguments));
}
