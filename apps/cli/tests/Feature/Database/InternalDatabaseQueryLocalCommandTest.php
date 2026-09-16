<?php

declare(strict_types=1);

use App\Services\Database\DynamicPdoConnection;
use App\Services\Database\InternalDatabaseLane;
use App\Services\Database\LocalDatabaseQueryAction;
use App\Services\Database\LocalDatabaseQueryRequest;
use App\Support\Console\StandardInputReader;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Tester\CommandTester;

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
    expect(app(Kernel::class)->all()['internal:database-query-local']->getDefinition()->hasArgument('sql'))
        ->toBeFalse();
    expect(app(Kernel::class)->all()['internal:database-query-local']->isHidden())->toBeTrue();

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

/**
 * @return array{0: int, 1: string}
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

    $tester = new CommandTester(app(Kernel::class)->all()['internal:database-query-local']);

    return [$tester->execute([], ['interactive' => false]), $tester->getDisplay(true)];
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
