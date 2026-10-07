<?php

declare(strict_types=1);

use App\Infrastructure\GatewayReleases\SqliteGatewayReleaseDatabase;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecordingProcessRunner;

beforeEach(function (): void {
    $this->scratch = sys_get_temp_dir().'/orbit-release-db-'.bin2hex(random_bytes(4));
    mkdir($this->scratch.'/release/apps/gateway/database/migrations', 0755, true);
    mkdir($this->scratch.'/home', 0700, true);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->scratch));
});

describe(SqliteGatewayReleaseDatabase::class, function (): void {
    it('lists the migrations a release ships that the database has not run', function (): void {
        $recorded = (string) DB::table('migrations')->orderBy('id')->value('migration');
        $directory = $this->scratch.'/release/apps/gateway/database/migrations';
        file_put_contents($directory.'/'.$recorded.'.php', '<?php');
        file_put_contents($directory.'/2099_01_02_000000_add_later.php', '<?php');
        file_put_contents($directory.'/2099_01_01_000000_add_new.php', '<?php');
        file_put_contents($directory.'/README.md', 'not a migration');

        $database = new SqliteGatewayReleaseDatabase(new RecordingProcessRunner, $this->scratch.'/home');

        expect($database->pending($this->scratch.'/release'))->toBe(['2099_01_01_000000_add_new', '2099_01_02_000000_add_later'])
            ->and($database->migrations($this->scratch.'/release'))->toBe([
                ...array_filter([$recorded.'.php'], static fn (string $file): bool => $file < '2099'),
                '2099_01_01_000000_add_new.php',
                '2099_01_02_000000_add_later.php',
            ]);
    });

    it('writes a private consistent snapshot of the database and keeps the newest five', function (): void {
        $live = $this->scratch.'/live.sqlite';
        touch($live);
        config(['database.connections.release_snapshot' => ['driver' => 'sqlite', 'database' => $live, 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::connection('release_snapshot')->statement('CREATE TABLE notes (body TEXT)');
        DB::connection('release_snapshot')->table('notes')->insert(['body' => 'kept']);
        $database = new SqliteGatewayReleaseDatabase(new RecordingProcessRunner, $this->scratch.'/home', connection: 'release_snapshot');

        foreach (['aaaaaaaaaaa1', 'aaaaaaaaaaa2', 'aaaaaaaaaaa3', 'aaaaaaaaaaa4', 'aaaaaaaaaaa5'] as $index => $id) {
            $path = $this->scratch.'/home/backups/pre-'.$id.'.sqlite';
            @mkdir(dirname($path), 0700, true);
            touch($path, time() - 100 + $index);
        }

        $snapshot = $database->snapshot('0123456789ab');
        $copy = new PDO('sqlite:'.$snapshot);

        expect($snapshot)->toBe($this->scratch.'/home/backups/pre-0123456789ab.sqlite')
            ->and($copy->query('SELECT body FROM notes')->fetchColumn())->toBe('kept')
            ->and(fileperms($snapshot) & 0o777)->toBe(0o600)
            ->and(file_exists($snapshot.'.partial'))->toBeFalse()
            ->and(file_exists($this->scratch.'/home/backups/pre-aaaaaaaaaaa1.sqlite'))->toBeFalse()
            ->and(glob($this->scratch.'/home/backups/pre-*.sqlite'))->toHaveCount(5);

        DB::purge('release_snapshot');
    });

    it('migrates with the release code and reports a failed migration', function (): void {
        $processes = new class implements ProcessRunner
        {
            /** @var list<ProcessInvocation> */
            public array $ran = [];

            public int $exit = 0;

            public function run(ProcessInvocation $invocation): CommandResult
            {
                $this->ran[] = $invocation;

                return new CommandResult($this->exit, '', $this->exit === 0 ? '' : 'SQLSTATE failed', 1, false);
            }
        };
        $database = new SqliteGatewayReleaseDatabase($processes, $this->scratch.'/home', php: '/usr/bin/php8.5');

        $database->migrate('/home/orbit/releases/0123456789ab');
        $processes->exit = 1;
        $exception = release_failure(fn () => $database->migrate('/home/orbit/releases/0123456789ab'));

        expect($processes->ran[0]->arguments)->toBe(['/usr/bin/php8.5', '/home/orbit/releases/0123456789ab/apps/gateway/artisan', 'migrate', '--force', '--no-interaction'])
            ->and($exception->step)->toBe('migrate')
            ->and($exception->errorCode)->toBe('gateway.release_migrate_failed');
    });

    it('refuses to snapshot a database that is not SQLite', function (): void {
        config(['database.connections.release_mysql' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'x']]);
        $database = new SqliteGatewayReleaseDatabase(new RecordingProcessRunner, $this->scratch.'/home', connection: 'release_mysql');

        expect(release_failure(fn () => $database->snapshot('0123456789ab'))->errorCode)->toBe('gateway.release_snapshot_unavailable');
    });
});
