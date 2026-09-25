<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function orb247_gateway_root(): string
{
    return dirname(__DIR__, 3);
}

function orb247_temporary_path(string $name): string
{
    $directory = sys_get_temp_dir().'/orbit-gateway-test-'.bin2hex(random_bytes(8));

    if (! mkdir($directory, 0o700, true) && ! is_dir($directory)) {
        throw new RuntimeException("Could not create {$directory}.");
    }

    register_shutdown_function(static function () use ($directory): void {
        if (! is_dir($directory)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isDir() && ! $file->isLink()) {
                rmdir($file->getPathname());

                continue;
            }

            unlink($file->getPathname());
        }

        rmdir($directory);
    });

    return "{$directory}/{$name}";
}

/** @return array{hash: string, rows: list<array{id: int, value: string}>, tables: list<string>} */
function orb247_sentinel_state(string $database): array
{
    clearstatcache(true, $database);
    $pdo = new PDO('sqlite:'.$database);
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
        ->fetchAll(PDO::FETCH_COLUMN);
    $rows = $pdo->query('SELECT id, value FROM sentinel ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

    return [
        'hash' => hash_file('sha256', $database),
        'rows' => $rows,
        'tables' => $tables,
    ];
}

function orb247_create_sentinel(string $database): array
{
    $pdo = new PDO('sqlite:'.$database);
    $pdo->exec('CREATE TABLE sentinel (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
    $pdo->exec("INSERT INTO sentinel (id, value) VALUES (247, 'preserve-me')");
    unset($pdo);

    return orb247_sentinel_state($database);
}

/** @param array<string, string|false> $environment */
function orb247_run_guard_probe(array $environment = []): Process
{
    $process = new Process([
        PHP_BINARY,
        orb247_gateway_root().'/tests/Fixtures/TestDatabase/GuardProbe.php',
    ], orb247_gateway_root(), ['ORBIT_TEST_DATABASE' => false, ...$environment]);
    $process->setTimeout(30);
    $process->run();

    return $process;
}

/** @param array<string, mixed> $database */
function orb247_cached_configuration(array $database): string
{
    $cache = orb247_temporary_path('configuration.php');
    $process = new Process(
        [PHP_BINARY, 'artisan', 'config:cache', '--no-interaction'],
        orb247_gateway_root(),
        [
            'APP_CONFIG_CACHE' => $cache,
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => false,
            'ORBIT_TEST_DATABASE' => false,
        ],
    );
    $process->setTimeout(30);
    $process->mustRun();

    /** @var array<string, mixed> $configuration */
    $configuration = require $cache;
    $configuration['app']['env'] = 'testing';
    $configuration['database'] = $database;
    file_put_contents($cache, '<?php return '.var_export($configuration, true).';');

    return $cache;
}

it('normalizes inherited database values in every PHP environment source', function (): void {
    $sentinel = orb247_temporary_path('caller.sqlite');
    $before = orb247_create_sentinel($sentinel);
    $sources = json_encode([
        'environment' => [
            'DB_DATABASE' => $sentinel,
            'DB_URL' => 'sqlite:////'.ltrim($sentinel, '/'),
        ],
        'server' => [
            'DB_DATABASE' => $sentinel,
            'DB_URL' => 'sqlite:////'.ltrim($sentinel, '/'),
        ],
    ], JSON_THROW_ON_ERROR);
    $process = new Process([
        PHP_BINARY,
        orb247_gateway_root().'/tests/Fixtures/TestDatabase/BootstrapProbe.php',
        $sources,
    ], orb247_gateway_root(), [
        'DB_DATABASE' => $sentinel,
        'DB_URL' => 'sqlite:////'.ltrim($sentinel, '/'),
        'ORBIT_TEST_DATABASE' => false,
    ]);
    $process->mustRun();
    $values = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($values)->toBe([
        'APP_ENV' => ['getenv' => 'testing', 'environment' => 'testing', 'server' => 'testing'],
        'DB_CONNECTION' => ['getenv' => 'sqlite', 'environment' => 'sqlite', 'server' => 'sqlite'],
        'DB_DATABASE' => ['getenv' => ':memory:', 'environment' => ':memory:', 'server' => ':memory:'],
        'DB_URL' => ['getenv' => '', 'environment' => '', 'server' => ''],
    ]);
    expect(orb247_sentinel_state($sentinel))->toBe($before);
});

it('refuses an unsafe cached URL before the sentinel changes', function (): void {
    $sentinel = orb247_temporary_path('caller.sqlite');
    $before = orb247_create_sentinel($sentinel);
    $cache = orb247_cached_configuration([
        'default' => 'sqlite',
        'connections' => [
            'sqlite' => [
                'driver' => 'sqlite',
                'url' => 'sqlite:////'.ltrim($sentinel, '/'),
                'database' => ':memory:',
                'prefix' => '',
            ],
        ],
        'migrations' => ['table' => 'migrations'],
    ]);
    $probe = orb247_temporary_path('probe.json');
    $process = orb247_run_guard_probe([
        'APP_CONFIG_CACHE' => $cache,
        'ORBIT_TEST_DATABASE_PROBE' => $probe,
    ]);
    $output = $process->getErrorOutput().$process->getOutput();

    expect($process->getExitCode())->not->toBe(0);
    expect($output)->toContain('Gateway tests refused an unsafe database connection');
    expect($probe)->not->toBeFile();
    expect(orb247_sentinel_state($sentinel))->toBe($before);
});

it('refuses unsafe cached read and write targets before the sentinel changes', function (
    string $target,
    bool $multiple,
): void {
    $sentinel = orb247_temporary_path('caller.sqlite');
    $before = orb247_create_sentinel($sentinel);
    $targets = [
        'read' => ['database' => ':memory:'],
        'write' => ['database' => ':memory:'],
    ];
    $targets[$target] = $multiple
        ? [['database' => ':memory:'], ['database' => $sentinel]]
        : ['database' => $sentinel];
    $cache = orb247_cached_configuration([
        'default' => 'sqlite',
        'connections' => [
            'sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'read' => $targets['read'],
                'write' => $targets['write'],
                'prefix' => '',
            ],
        ],
        'migrations' => ['table' => 'migrations'],
    ]);
    $probe = orb247_temporary_path('probe.json');
    $process = orb247_run_guard_probe([
        'APP_CONFIG_CACHE' => $cache,
        'ORBIT_TEST_DATABASE_PROBE' => $probe,
    ]);
    $output = $process->getErrorOutput().$process->getOutput();

    expect($process->getExitCode())->not->toBe(0);
    expect($output)->toContain('Gateway tests refused an unsafe database connection');
    expect($probe)->not->toBeFile();
    expect(orb247_sentinel_state($sentinel))->toBe($before);
})->with([
    'read target' => ['read', false],
    'write target' => ['write', false],
    'read target list' => ['read', true],
    'write target list' => ['write', true],
]);

it('uses safe cached read and write targets', function (): void {
    $database = orb247_temporary_path('orbit-gateway-test-read-write.sqlite');
    touch($database);
    $cache = orb247_cached_configuration([
        'default' => 'sqlite',
        'connections' => [
            'sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'read' => ['database' => ':memory:'],
                'write' => ['database' => $database],
                'prefix' => '',
            ],
        ],
        'migrations' => ['table' => 'migrations'],
    ]);
    $marker = orb247_temporary_path('probe.json');
    $process = orb247_run_guard_probe([
        'APP_CONFIG_CACHE' => $cache,
        'ORBIT_TEST_DATABASE' => $database,
        'ORBIT_TEST_DATABASE_PROBE' => $marker,
    ]);
    $output = $process->getErrorOutput().$process->getOutput();

    expect($process->getExitCode())->toBe(0, $output);
    $probe = json_decode((string) file_get_contents($marker), true, flags: JSON_THROW_ON_ERROR);
    expect($probe['driver'] ?? null)->toBe('sqlite');
    expect($probe['database'] ?? null)->toBe($database);
    expect($probe['database'] ?? null)->toBeFile();
});

it('refuses an unsafe cached non-SQLite connection', function (): void {
    $cache = orb247_cached_configuration([
        'default' => 'caller',
        'connections' => [
            'caller' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'database' => 'caller',
            ],
        ],
        'migrations' => ['table' => 'migrations'],
    ]);
    $process = orb247_run_guard_probe([
        'APP_CONFIG_CACHE' => $cache,
        'ORBIT_TEST_DATABASE_PROBE' => orb247_temporary_path('probe.json'),
    ]);

    expect($process->getExitCode())->not->toBe(0);
    expect($process->getErrorOutput().$process->getOutput())
        ->toContain('Gateway tests refused an unsafe database connection: the effective driver is not SQLite');
});

it('uses an explicitly allocated disposable SQLite database', function (): void {
    $database = orb247_temporary_path('orbit-gateway-test-allocated.sqlite');
    touch($database);
    $marker = orb247_temporary_path('probe.json');
    $process = orb247_run_guard_probe([
        'ORBIT_TEST_DATABASE' => $database,
        'ORBIT_TEST_DATABASE_PROBE' => $marker,
    ]);
    $output = $process->getErrorOutput().$process->getOutput();

    expect($process->getExitCode())->toBe(0, $output);
    $probe = json_decode((string) file_get_contents($marker), true, flags: JSON_THROW_ON_ERROR);
    expect($probe['driver'] ?? null)->toBe('sqlite');
    expect($probe['database'] ?? null)->toBe($database);
    expect($probe['database'] ?? null)->toBeFile();
});

it('does not change non-test Gateway database selection', function (): void {
    $database = orb247_temporary_path('production.sqlite');
    $process = new Process([
        PHP_BINARY,
        orb247_gateway_root().'/tests/Fixtures/TestDatabase/NonTestDatabaseProbe.php',
    ], orb247_gateway_root(), [
        'APP_ENV' => 'production',
        'CACHE_STORE' => false,
        'DB_DATABASE' => $database,
        'DB_URL' => false,
        'ORBIT_TEST_DATABASE' => false,
    ]);
    $process->mustRun();
    $configuration = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($configuration)->toBe([
        'environment' => 'production',
        'database' => $database,
        'url' => null,
    ]);
});
