<?php

declare(strict_types=1);

use App\Services\Database\DynamicPdoConnection;
use App\Services\Database\InternalDatabaseLane;
use App\Services\Database\LocalDatabaseQueryAction;
use App\Services\Database\LocalDatabaseQueryRequest;
use App\Support\Console\StandardInputReader;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->previousToken = getenv(InternalDatabaseLane::TOKEN_ENV);
    putenv(InternalDatabaseLane::TOKEN_ENV);
    unset($_ENV[InternalDatabaseLane::TOKEN_ENV], $_SERVER[InternalDatabaseLane::TOKEN_ENV]);
});

afterEach(function (): void {
    if (is_string($this->previousToken)) {
        putenv(InternalDatabaseLane::TOKEN_ENV.'='.$this->previousToken);
    } else {
        putenv(InternalDatabaseLane::TOKEN_ENV);
    }
});

it('queries a local sqlite file through PDO and keeps SQL off argv', function (): void {
    $path = internal_database_sqlite_path();
    $token = internal_database_lane_token();
    $payload = internal_database_lane_payload($token, $path, 'SELECT email FROM users');

    [$exit, $output] = internal_database_query_display($payload);

    expect($exit)->toBe(0);
    expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toBe([
        'columns' => ['email'],
        'rows' => [['email' => 'owner@example.test']],
        'row_count' => 1,
        'truncated' => false,
    ]);
    expect(app(Kernel::class)->all()['internal:database-local']->getDefinition()->hasArgument('sql'))
        ->toBeFalse();
    expect(app(Kernel::class)->all()['internal:database-local']->isHidden())->toBeTrue();
    expect(collect(app(Kernel::class)->all())->keys()->all())
        ->not->toContain('internal:database-query-local');

    unlink($path);
});

it('opens sqlite read-only unless the write flag is set', function (): void {
    $path = internal_database_sqlite_path();
    $token = internal_database_lane_token();

    [$exit, $output] = internal_database_query_display(internal_database_lane_payload(
        $token,
        $path,
        "INSERT INTO users (email) VALUES ('other@example.test')",
        write: false,
    ));

    expect($exit)->toBe(1);
    expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['error']['code'] ?? null)
        ->toBe('database.query_failed');
    expect((new PDO('sqlite:'.$path))->query('SELECT COUNT(*) FROM users')->fetchColumn())->toBe(1);

    [$writeExit, $writeOutput] = internal_database_query_display(internal_database_lane_payload(
        $token,
        $path,
        "INSERT INTO users (email) VALUES ('other@example.test')",
        write: true,
    ));

    expect($writeExit)->toBe(0);
    expect(json_decode($writeOutput, true, flags: JSON_THROW_ON_ERROR)['row_count'] ?? null)
        ->toBeGreaterThan(0);
    expect((new PDO('sqlite:'.$path))->query('SELECT COUNT(*) FROM users')->fetchColumn())->toBe(2);

    unlink($path);
});

it('refuses a missing or mismatched lane token and never shells out to sqlite3', function (): void {
    $path = internal_database_sqlite_path();
    $token = internal_database_lane_token();
    putenv(InternalDatabaseLane::TOKEN_ENV.'='.$token);

    [$missing, $missingOutput] = internal_database_query_display('{"path":"'.$path.'","sql":"SELECT 1","write":false}');
    [$mismatch, $mismatchOutput] = internal_database_query_display(internal_database_lane_payload(
        str_repeat('ab', 32),
        $path,
        'SELECT 1',
    ));

    expect($missing)->toBe(1)
        ->and($mismatch)->toBe(1)
        ->and(json_decode($missingOutput, true, flags: JSON_THROW_ON_ERROR)['error']['code'] ?? null)
        ->toBe('database.internal_unauthorized')
        ->and(json_decode($mismatchOutput, true, flags: JSON_THROW_ON_ERROR)['error']['code'] ?? null)
        ->toBe('database.internal_unauthorized')
        ->and($missingOutput.$mismatchOutput)
        ->not->toContain('sqlite3')
        ->not->toContain($token);

    unlink($path);
});

it('builds mysql and pgsql DSNs without embedding the password', function (): void {
    $connections = new DynamicPdoConnection;

    expect($connections->dsn([
        'driver' => 'mysql',
        'host' => 'db.example.test',
        'port' => 3306,
        'database' => 'app',
        'username' => 'app',
        'password' => 'db-cli-pdo-secret-21e4',
    ]))->toBe('mysql:host=db.example.test;port=3306;dbname=app;charset=utf8mb4')
        ->and($connections->dsn([
            'driver' => 'pgsql',
            'host' => 'pg.example.test',
            'port' => 5432,
            'database' => 'app',
        ]))->toBe('pgsql:host=pg.example.test;port=5432;dbname=app')
        ->and($connections->dsn([
            'driver' => 'mysql',
            'host' => 'db.example.test',
            'port' => 3306,
            'database' => 'app',
            'password' => 'db-cli-pdo-secret-21e4',
        ]))->not->toContain('db-cli-pdo-secret-21e4');
});

it('executes sqlite through the local PDO action without argv SQL', function (): void {
    $path = internal_database_sqlite_path();
    $result = app(LocalDatabaseQueryAction::class)->execute(new LocalDatabaseQueryRequest(
        internal_database_lane_token(),
        $path,
        'SELECT email FROM users',
        false,
    ));

    expect($result->toArray())->toBe([
        'columns' => ['email'],
        'rows' => [['email' => 'owner@example.test']],
        'row_count' => 1,
        'truncated' => false,
    ]);

    unlink($path);
});

describe('local sqlite result failures', function (): void {
    it('returns one bounded failure with no partial result or stderr', function (string $sql): void {
        $path = internal_database_sqlite_path();
        $token = internal_database_lane_token();

        try {
            [$exit, $output, $stderr] = internal_database_query_display(
                internal_database_lane_payload($token, $path, $sql),
            );

            expect($exit)->toBe(1)
                ->and(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toBe([
                    'error' => [
                        'code' => 'database.query_failed',
                        'message' => 'Database query failed.',
                        'request_id' => null,
                    ],
                ])
                ->and(substr_count($output, "\n"))->toBe(1)
                ->and($stderr)->toBe('');

            foreach ([$sql, $token, $path, 'private-first-row', 'PDOException', 'JsonException', '\\uFFFD', "\e"] as $forbidden) {
                expect($output)->not->toContain($forbidden);
            }
        } finally {
            unlink($path);
        }
    })->with([
        'deferred second-row overflow' => ["SELECT 'private-first-row' AS value UNION ALL SELECT abs(-9223372036854775808)"],
        'unrepresentable text' => ["SELECT CAST(X'80' AS TEXT) AS value"],
        'nonfinite number' => ['SELECT 1e999 AS value'],
    ]);

    it('preserves representable Unicode and scalar values exactly', function (): void {
        $path = internal_database_sqlite_path();

        try {
            [$exit, $output, $stderr] = internal_database_query_display(internal_database_lane_payload(
                internal_database_lane_token(),
                $path,
                "SELECT '雪 café 🛰️' AS text, 42 AS integer_value, 1.25 AS float_value, NULL AS null_value, '' AS empty_value, '001' AS numeric_text",
            ));

            expect($exit)->toBe(0)
                ->and(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toBe([
                    'columns' => ['text', 'integer_value', 'float_value', 'null_value', 'empty_value', 'numeric_text'],
                    'rows' => [[
                        'text' => '雪 café 🛰️',
                        'integer_value' => 42,
                        'float_value' => 1.25,
                        'null_value' => null,
                        'empty_value' => '',
                        'numeric_text' => '001',
                    ]],
                    'row_count' => 1,
                    'truncated' => false,
                ])
                ->and($stderr)->toBe('');
        } finally {
            unlink($path);
        }
    });

    it('does not imply rollback when a completed write result cannot be encoded', function (): void {
        $path = internal_database_sqlite_path();

        try {
            [$exit, $output, $stderr] = internal_database_query_display(internal_database_lane_payload(
                internal_database_lane_token(),
                $path,
                "INSERT INTO users (email) VALUES ('committed@example.test') RETURNING CAST(X'80' AS TEXT) AS value",
                write: true,
            ));

            expect($exit)->toBe(1)
                ->and(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toBe([
                    'error' => [
                        'code' => 'database.query_failed',
                        'message' => 'Database query failed.',
                        'request_id' => null,
                    ],
                ])
                ->and($stderr)->toBe('')
                ->and((new PDO('sqlite:'.$path))->query('SELECT email FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN))
                ->toBe(['owner@example.test', 'committed@example.test']);
        } finally {
            unlink($path);
        }
    });
});

describe('native PDO driver read-only opening', function (): void {
    it('bounds unavailable readonly driver options without a deprecation or new database', function (): void {
        $probe = new Process([PHP_BINARY, '-n', '-r', 'echo json_encode(class_exists("PDO") ? PDO::getAvailableDrivers() : null);']);
        $probe->mustRun();
        $drivers = json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        if (is_array($drivers) && in_array('sqlite', $drivers, true)) {
            $this->markTestSkipped('This binary compiles SQLite support in; it cannot be unloaded.');
        }

        $path = sys_get_temp_dir().'/orbit-internal-db-unavailable-'.bin2hex(random_bytes(8)).'.sqlite';
        $arguments = [PHP_BINARY, '-n'];

        if ($drivers === null) {
            $arguments = [...$arguments, '-d', 'extension=pdo'];
        }

        try {
            $process = new Process([
                ...$arguments, '-d', 'error_reporting=-1', '-d', 'display_errors=stderr',
                dirname(__DIR__, 2).'/Fixtures/Database/read-only-capability.php',
            ], input: $path);

            expect($process->run())->toBe(1)
                ->and(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe([
                    'error' => ['code' => 'database.query_failed', 'message' => 'Database query failed.'],
                ])
                ->and($process->getErrorOutput())->toBe('')
                ->and(file_exists($path))->toBeFalse();
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    });

    it('reads a SQLite file without runtime warnings on either driver class layout', function (): void {
        $path = internal_database_sqlite_path();

        try {
            [$exit, $stdout, $stderr] = internal_database_query_process(internal_database_lane_payload(
                internal_database_lane_token(), $path, 'SELECT email FROM users',
            ));

            expect($exit)->toBe(0)
                ->and(json_decode($stdout, true, flags: JSON_THROW_ON_ERROR))->toBe([
                    'columns' => ['email'],
                    'rows' => [['email' => 'owner@example.test']],
                    'row_count' => 1,
                    'truncated' => false,
                ])
                ->and($stderr)->toBe('');
        } finally {
            unlink($path);
        }
    });

    it('refuses a write through a readonly connection without changing the database', function (): void {
        $path = internal_database_sqlite_path();

        try {
            [$exit, $stdout, $stderr] = internal_database_query_process(internal_database_lane_payload(
                internal_database_lane_token(), $path, "INSERT INTO users (email) VALUES ('refused@example.test')",
            ));

            expect($exit)->toBe(1)
                ->and(json_decode($stdout, true, flags: JSON_THROW_ON_ERROR))->toBe([
                    'error' => [
                        'code' => 'database.query_failed',
                        'message' => 'Database query failed.',
                        'request_id' => null,
                    ],
                ])
                ->and($stderr)->toBe('')
                ->and((new PDO('sqlite:'.$path))->query('SELECT email FROM users')->fetchAll(PDO::FETCH_COLUMN))
                ->toBe(['owner@example.test']);
        } finally {
            unlink($path);
        }
    });

    it('refuses a missing readonly database without creating it', function (): void {
        $path = sys_get_temp_dir().'/orbit-internal-db-missing-'.bin2hex(random_bytes(8)).'.sqlite';

        try {
            [$exit, $stdout, $stderr] = internal_database_query_process(internal_database_lane_payload(
                internal_database_lane_token(), $path, 'SELECT 1',
            ));

            expect($exit)->toBe(1)
                ->and(json_decode($stdout, true, flags: JSON_THROW_ON_ERROR))->toBe([
                    'error' => [
                        'code' => 'database.query_failed',
                        'message' => 'Database query failed.',
                        'request_id' => null,
                    ],
                ])
                ->and($stderr)->toBe('')
                ->and(file_exists($path))->toBeFalse();
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    });

    it('keeps an explicit writable connection available', function (): void {
        $path = internal_database_sqlite_path();

        try {
            [$exit, $stdout, $stderr] = internal_database_query_process(internal_database_lane_payload(
                internal_database_lane_token(), $path, "INSERT INTO users (email) VALUES ('allowed@example.test')", write: true,
            ));

            expect($exit)->toBe(0)
                ->and(json_decode($stdout, true, flags: JSON_THROW_ON_ERROR))->toBe([
                    'columns' => [],
                    'rows' => [],
                    'row_count' => 1,
                    'truncated' => false,
                ])
                ->and($stderr)->toBe('')
                ->and((new PDO('sqlite:'.$path))->query('SELECT email FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN))
                ->toBe(['owner@example.test', 'allowed@example.test']);
        } finally {
            unlink($path);
        }
    });
});

/** @return array{int, string, string} */
function internal_database_query_process(string $payload): array
{
    $fixtureHome = sys_get_temp_dir().'/orbit-internal-db-home-'.bin2hex(random_bytes(8));
    mkdir($fixtureHome, 0o700);

    try {
        $process = new Process(
            [PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', dirname(__DIR__, 3).'/orbit', 'internal:database-local'],
            env: ['ORBIT_HOME' => $fixtureHome, InternalDatabaseLane::TOKEN_ENV => false],
            input: $payload,
        );
        $process->run();

        return [$process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
    } finally {
        new Filesystem()->deleteDirectory($fixtureHome);
    }
}

/**
 * @return array{0: int, 1: string, 2: string}
 */
function internal_database_query_display(string $payload): array
{
    app()->instance(StandardInputReader::class, new class($payload) implements StandardInputReader
    {
        public function __construct(private string $payload) {}

        public function read(): string
        {
            return $this->payload;
        }
    });

    $tester = new CommandTester(app(Kernel::class)->all()['internal:database-local']);

    $exit = $tester->execute([], ['interactive' => false, 'capture_stderr_separately' => true]);

    return [$exit, $tester->getDisplay(true), $tester->getErrorOutput(true)];
}

function internal_database_sqlite_path(): string
{
    $path = sys_get_temp_dir().'/orbit-internal-db-'.bin2hex(random_bytes(8)).'.sqlite';
    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');
    $pdo->exec("INSERT INTO users (email) VALUES ('owner@example.test')");

    return $path;
}

function internal_database_lane_token(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * @return array{token: string, path: string, sql: string, write: bool}|string
 */
function internal_database_lane_payload(string $token, string $path, string $sql, bool $write = false): string
{
    return json_encode([
        'token' => $token,
        'path' => $path,
        'sql' => $sql,
        'write' => $write,
    ], JSON_THROW_ON_ERROR);
}
