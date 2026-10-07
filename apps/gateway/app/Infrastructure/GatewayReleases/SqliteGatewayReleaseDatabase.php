<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The Gateway's SQLite database during a release. A release's pending migrations are the migration files it ships
 * that the `migrations` table has not recorded. Before they run, `VACUUM INTO` writes a consistent copy of the live
 * database to `$ORBIT_HOME/backups/pre-<id>.sqlite`, which needs no `sqlite3` binary and does not block writers for
 * long. The new release then migrates with its own code, before the switch.
 */
final readonly class SqliteGatewayReleaseDatabase implements GatewayReleaseDatabase
{
    /** The default number of pre-release snapshots kept in `$ORBIT_HOME/backups`, newest first. */
    public const int KeptSnapshots = 5;

    /** @var Closure(string): (float|false) */
    private Closure $freeSpace;

    /**
     * @param  int  $keptSnapshots  `ORBIT_GATEWAY_RELEASE_SNAPSHOTS_KEEP`
     * @param  int  $minimumFreeBytes  The free space a snapshot leaves on the backup file system.
     * @param  (Closure(string): (float|false))|null  $freeSpace
     */
    public function __construct(
        private ProcessRunner $processes,
        private string $orbitHome,
        private string $php = '/usr/bin/php8.5',
        private float $migrateTimeout = 900.0,
        private ?string $connection = null,
        private int $keptSnapshots = self::KeptSnapshots,
        private int $minimumFreeBytes = GatewayReleaseBuilder::MinimumFreeBytes,
        ?Closure $freeSpace = null,
    ) {
        $this->freeSpace = $freeSpace ?? static fn (string $path): float|false => @disk_free_space($path);
    }

    /**
     * The live database file and its WAL. `VACUUM INTO` writes at most the pages in use, so this
     * bounds the snapshot from above.
     */
    public function snapshotBytes(): int
    {
        $database = DB::connection($this->connection)->getConfig('database');

        if (! is_string($database) || $database === '' || $database === ':memory:') {
            return 0;
        }

        $bytes = 0;

        foreach ([$database, $database.'-wal'] as $file) {
            $size = is_file($file) ? @filesize($file) : false;
            $bytes += $size === false ? 0 : $size;
        }

        return $bytes;
    }

    public function pending(string $releasePath): array
    {
        try {
            $ran = DB::connection($this->connection)->table(Config::string('database.migrations.table', 'migrations'))->pluck('migration')->all();
        } catch (Throwable $exception) {
            throw new GatewayReleaseException(
                step: 'snapshot',
                errorCode: 'gateway.release_migrations_unreadable',
                message: 'The Gateway migrations table cannot be read.',
                status: 500,
                previous: $exception,
            );
        }

        $recorded = array_fill_keys(array_map(static fn (mixed $name): string => is_string($name) ? $name : '', $ran), true);
        $pending = [];

        foreach ($this->migrations($releasePath) as $file) {
            $name = substr($file, 0, -4);

            if (! isset($recorded[$name])) {
                $pending[] = $name;
            }
        }

        return $pending;
    }

    public function snapshot(string $id): string
    {
        $id = GatewayReleaseCommit::assertId($id);
        $connection = DB::connection($this->connection);

        if ($connection->getDriverName() !== 'sqlite') {
            throw new GatewayReleaseException(
                step: 'snapshot',
                errorCode: 'gateway.release_snapshot_unavailable',
                message: 'Only a SQLite Gateway database can be snapshotted before a release migrates.',
                status: 500,
            );
        }

        $directory = rtrim($this->orbitHome, '/').'/backups';

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw $this->snapshotFailed("The backup directory [{$directory}] cannot be created.");
        }

        $target = $directory.'/pre-'.$id.'.sqlite';
        $partial = $target.'.partial';
        @unlink($partial);
        $this->assertRoom($directory);

        try {
            $connection->statement('VACUUM INTO ?', [$partial]);
        } catch (Throwable $exception) {
            @unlink($partial);

            throw $this->snapshotFailed('VACUUM INTO failed: '.$exception->getMessage(), $exception);
        }

        if (! @chmod($partial, 0600) || ! @rename($partial, $target)) {
            @unlink($partial);

            throw $this->snapshotFailed("The snapshot [{$target}] cannot be put in place.");
        }

        $this->prune($directory);

        return $target;
    }

    public function migrate(string $releasePath): void
    {
        $result = $this->processes->run(new ProcessInvocation(
            arguments: [$this->php, $releasePath.'/apps/gateway/artisan', 'migrate', '--force', '--no-interaction'],
            timeout: $this->migrateTimeout,
        ));

        if (! $result->succeeded()) {
            throw new GatewayReleaseException(
                step: 'migrate',
                errorCode: 'gateway.release_migrate_failed',
                message: 'The release migrations failed. The database may be partly migrated; the snapshot holds the state before.',
                status: 500,
                result: $result,
            );
        }
    }

    public function migrations(string $releasePath): array
    {
        $entries = @scandir($releasePath.'/apps/gateway/database/migrations');

        if ($entries === false) {
            return [];
        }

        $files = array_values(array_filter(
            $entries,
            static fn (string $entry): bool => preg_match('/\A[0-9A-Za-z_]+\.php\z/D', $entry) === 1,
        ));
        sort($files);

        return $files;
    }

    /** Refuses before writing when the snapshot would leave less than the floor free. */
    private function assertRoom(string $directory): void
    {
        $free = ($this->freeSpace)($directory);
        $needed = $this->snapshotBytes() + $this->minimumFreeBytes;

        if ($free !== false && $free < $needed) {
            throw new GatewayReleaseException(
                step: 'snapshot',
                errorCode: 'gateway.release_disk_low',
                message: sprintf('The backup directory has %d MiB free; a snapshot needs %d MiB plus the %d MiB floor.', (int) ($free / 1_048_576), intdiv($needed - $this->minimumFreeBytes, 1_048_576), intdiv($this->minimumFreeBytes, 1_048_576)),
            );
        }
    }

    private function prune(string $directory): void
    {
        $snapshots = glob($directory.'/pre-*.sqlite') ?: [];
        usort($snapshots, static fn (string $left, string $right): int => [(int) @filemtime($right), $right] <=> [(int) @filemtime($left), $left]);

        foreach (array_slice($snapshots, max(1, $this->keptSnapshots)) as $old) {
            @unlink($old);
        }
    }

    private function snapshotFailed(string $message, ?Throwable $previous = null): GatewayReleaseException
    {
        return new GatewayReleaseException(
            step: 'snapshot',
            errorCode: 'gateway.release_snapshot_failed',
            message: $message,
            status: 500,
            previous: $previous,
        );
    }
}
