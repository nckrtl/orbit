<?php

declare(strict_types=1);

use App\Domain\AppInstances\Sqlite\SqliteSeedPlacement;
use App\Domain\AppInstances\Sqlite\SqliteSnapshotTransfer;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppInstances\ProtectedSqliteSnapshotTransfer;
use App\Infrastructure\AppInstances\RemoteAppInstanceSqliteSeeder;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Symfony\Component\Process\Process;

it('refuses invalid sources and insufficient capacity before target replacement', function (string $case): void {
    $sandbox = sqlite_seed_sandbox();
    $sourcePath = "{$sandbox}/source/database.sqlite";
    sqlite_seed_create_database($sourcePath);
    file_put_contents("{$sandbox}/target/unrelated.txt", 'target-sentinel');
    $sourceReplacements = [];
    $targetReplacements = [];

    if ($case === 'outside source placement') {
        $sourcePath = "{$sandbox}/outside.sqlite";
        sqlite_seed_create_database($sourcePath);
    }

    if ($case === 'relative source path') {
        $sourcePath = 'database.sqlite';
    }

    if ($case === 'symlink source') {
        rename($sourcePath, "{$sandbox}/source/real.sqlite");
        symlink("{$sandbox}/source/real.sqlite", $sourcePath);
    }

    if ($case === 'unreadable source') {
        chmod($sourcePath, 0000);
    }

    if ($case === 'directory instead of file') {
        $sourcePath = "{$sandbox}/source/database-directory";
        mkdir($sourcePath, 0700);
    }

    if ($case === 'non-SQLite file') {
        file_put_contents($sourcePath, 'not a sqlite database');
    }

    if ($case === 'insufficient source capacity') {
        $sourceReplacements = [
            'available = filesystem.f_bavail * filesystem.f_frsize' => 'available = 0',
        ];
    }

    if ($case === 'insufficient target capacity') {
        $targetReplacements = [
            'if filesystem.f_bavail * filesystem.f_frsize < expected_size + 1048576:' => 'if True:',
        ];
    }

    try {
        $ssh = new SqliteSeedLocalSshExecutor(
            new NativeProcessRunner,
            $sandbox,
            sourceProgramReplacements: $sourceReplacements,
            targetProgramReplacements: $targetReplacements,
        );
        $transfer = new SqliteSeedLocalTransfer($ssh);
        $seeder = sqlite_seed_seeder($ssh, $transfer);

        try {
            $seeder->seed(
                sqlite_seed_source_placement($sandbox),
                sqlite_seed_target_placement(),
                $sourcePath,
            );
            $this->fail('The invalid SQLite seed unexpectedly passed preflight.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)
                ->toBe('sqlite.seed_preflight_failed')
                ->and($exception->getMessage())
                ->toBe('The recorded SQLite seed source or target is unavailable.');
        }

        expect(file_get_contents("{$sandbox}/target/unrelated.txt"))
            ->toBe('target-sentinel')
            ->and(file_exists("{$sandbox}/target/database.sqlite"))
            ->toBeFalse()
            ->and($transfer->transfers)
            ->toBe([])
            ->and(glob("{$sandbox}/snapshots/*") ?: [])
            ->toBe([])
            ->and(glob("{$sandbox}/state/*") ?: [])
            ->toBe([]);
    } finally {
        sqlite_seed_remove_directory($sandbox);
    }
})->with([
    'outside source placement',
    'relative source path',
    'symlink source',
    'unreadable source',
    'directory instead of file',
    'non-SQLite file',
    'insufficient source capacity',
    'insufficient target capacity',
]);

it('creates one valid snapshot while WAL writes continue without source workload commands', function (): void {
    $sandbox = sqlite_seed_sandbox();
    $sourcePath = "{$sandbox}/source/database.sqlite";
    $stopPath = "{$sandbox}/stop-writer";
    sqlite_seed_create_database($sourcePath, rows: 2_000, wal: true);
    $sourceInode = fileinode($sourcePath);
    $writer = sqlite_seed_wal_writer($sourcePath, $stopPath);

    try {
        $writer->start();

        if (! $writer->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'READY'))) {
            throw new RuntimeException('The WAL writer did not become ready.');
        }

        $ssh = new SqliteSeedLocalSshExecutor(new NativeProcessRunner, $sandbox);
        $transfer = new SqliteSeedLocalTransfer($ssh);

        $result = sqlite_seed_seeder($ssh, $transfer)->seed(
            sqlite_seed_source_placement($sandbox),
            sqlite_seed_target_placement(),
            $sourcePath,
        );
        $snapshotRows = sqlite_seed_row_count("{$sandbox}/target/database.sqlite");
        $deadline = microtime(true) + 5;

        do {
            $sourceRows = sqlite_seed_row_count($sourcePath);

            if ($sourceRows > $snapshotRows) {
                break;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        expect($result->confirmed)
            ->toBeTrue()
            ->and($result->changed)
            ->toBeTrue();
        expect(sqlite_seed_integrity("{$sandbox}/target/database.sqlite"))
            ->toBe('ok')
            ->and($snapshotRows)
            ->toBeGreaterThanOrEqual(2_000)
            ->and($sourceRows)
            ->toBeGreaterThan($snapshotRows);
        expect(fileinode($sourcePath))
            ->toBe($sourceInode)
            ->and(sqlite_seed_journal_mode($sourcePath))
            ->toBe('wal');
        expect($ssh->commandModes())
            ->toBe(['source:prepare', 'target:prepare', 'target:install', 'source:cleanup'])
            ->and($ssh->hasOnlyFixedPythonCommands())
            ->toBeTrue();
    } finally {
        file_put_contents($stopPath, 'stop');

        if ($writer->isRunning()) {
            $writer->wait();
        }

        sqlite_seed_remove_directory($sandbox);
    }
});

it('completes a large live WAL snapshot within a bounded command deadline', function (): void {
    $sandbox = sqlite_seed_sandbox();
    $sourcePath = "{$sandbox}/source/database.sqlite";
    $stopPath = "{$sandbox}/stop-writer";
    sqlite_seed_create_database($sourcePath, rows: 200, wal: true);
    $source = new PDO("sqlite:{$sourcePath}");
    $source->exec('CREATE TABLE seed_payload (content BLOB NOT NULL)');
    $source->exec('INSERT INTO seed_payload (content) VALUES (zeroblob(67108864))');
    unset($source);
    $writer = sqlite_seed_wal_writer($sourcePath, $stopPath, delayMicroseconds: 0);
    $deadline = new CommandDeadline;
    $deadline->start(10);

    try {
        $writer->start();

        if (! $writer->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'READY'))) {
            throw new RuntimeException('The WAL writer did not become ready.');
        }

        $ssh = new SqliteSeedLocalSshExecutor(new NativeProcessRunner(deadline: $deadline), $sandbox);
        $result = sqlite_seed_seeder($ssh, new SqliteSeedLocalTransfer($ssh))->seed(
            sqlite_seed_source_placement($sandbox),
            sqlite_seed_target_placement(),
            $sourcePath,
        );

        expect($result->confirmed)
            ->toBeTrue()
            ->and($result->changed)
            ->toBeTrue();
        expect(sqlite_seed_integrity("{$sandbox}/target/database.sqlite"))
            ->toBe('ok')
            ->and(filesize("{$sandbox}/target/database.sqlite"))
            ->toBeGreaterThan(67_108_864);
    } finally {
        file_put_contents($stopPath, 'stop');

        if ($writer->isRunning()) {
            $writer->wait();
        }

        sqlite_seed_remove_directory($sandbox);
    }
});

it('streams the protected snapshot through bounded scp invocations and removes the local staging file', function (): void {
    $sandbox = sqlite_seed_sandbox();
    $sourcePath = "{$sandbox}/source/database.sqlite";
    $targetPath = "{$sandbox}/target/incoming.sqlite";
    sqlite_seed_create_database($sourcePath, rows: 25);
    $bytes = filesize($sourcePath);
    $digest = hash_file('sha256', $sourcePath);

    try {
        $processes = new SqliteSeedScpProcessRunner;
        $transfer = new ProtectedSqliteSnapshotTransfer(
            $processes,
            sqlite_seed_key_provider(),
            sqlite_seed_known_hosts(),
        );

        $transfer->transfer(
            sqlite_seed_source_placement($sandbox),
            $sourcePath,
            sqlite_seed_target_placement(),
            $targetPath,
            $bytes,
            $digest,
        );

        expect(hash_file('sha256', $targetPath))
            ->toBe($digest)
            ->and(sqlite_seed_integrity($targetPath))
            ->toBe('ok');
        expect($processes->invocations)
            ->toHaveCount(2);

        foreach ($processes->invocations as $invocation) {
            expect($invocation->arguments[0])
                ->toBe('scp')
                ->and($invocation->maxOutputBytes)
                ->toBe(256)
                ->and($invocation->input)
                ->toBeNull()
                ->and($invocation->protectedInput)
                ->toBeNull();
        }

        $localStagingPath = $processes->invocations[0]->arguments[array_key_last($processes->invocations[0]->arguments)];

        expect(file_exists($localStagingPath))
            ->toBeFalse()
            ->and(json_encode($processes->invocations, JSON_THROW_ON_ERROR))
            ->not->toContain('seed-row-25');
    } finally {
        sqlite_seed_remove_directory($sandbox);
    }
});

it('redacts protected transfer failures and removes their local staging file', function (): void {
    $sandbox = sqlite_seed_sandbox();
    $sourcePath = "{$sandbox}/source/database.sqlite";
    sqlite_seed_create_database($sourcePath);
    $sensitive = 'raw-database-diagnostic-sentinel';

    try {
        $processes = new SqliteSeedScpProcessRunner($sensitive);
        $transfer = new ProtectedSqliteSnapshotTransfer(
            $processes,
            sqlite_seed_key_provider(),
            sqlite_seed_known_hosts(),
        );

        try {
            $transfer->transfer(
                sqlite_seed_source_placement($sandbox),
                $sourcePath,
                sqlite_seed_target_placement(),
                "{$sandbox}/target/incoming.sqlite",
                filesize($sourcePath),
                hash_file('sha256', $sourcePath),
            );
            $this->fail('The failed protected transfer unexpectedly passed.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())
                ->toBe('The protected SQLite snapshot transfer failed safely.')
                ->not->toContain($sensitive)
                ->and($exception->getPrevious())
                ->toBeNull();
        }

        $localStagingPath = $processes->invocations[0]->arguments[array_key_last($processes->invocations[0]->arguments)];

        expect(file_exists($localStagingPath))
            ->toBeFalse();
    } finally {
        sqlite_seed_remove_directory($sandbox);
    }
});

it('keeps the selected source path and raw remote output out of seed diagnostics and traces', function (): void {
    $sandbox = sqlite_seed_sandbox();
    $sourcePath = "{$sandbox}/source/trace-diagnostic-sentinel.sqlite";
    sqlite_seed_create_database($sourcePath);
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        $ssh = new SqliteSeedObservationSshExecutor(new CommandResult(
            43,
            "raw {$sourcePath}",
            "diagnostic {$sourcePath}",
            1,
            false,
        ));
        $transfer = new ProtectedSqliteSnapshotTransfer(
            new SqliteSeedScpProcessRunner,
            sqlite_seed_key_provider(),
            sqlite_seed_known_hosts(),
        );

        try {
            sqlite_seed_seeder($ssh, $transfer)->seed(
                sqlite_seed_source_placement($sandbox),
                sqlite_seed_target_placement(),
                $sourcePath,
            );
            $this->fail('The failed seed receipt unexpectedly passed.');
        } catch (ResourceOperationException $exception) {
            $frames = collect($exception->getTrace())->where('function', 'seed');

            expect($exception->getMessage())
                ->not->toContain($sourcePath)
                ->and($exception->getPrevious())
                ->toBeNull()
                ->and($frames)
                ->not->toBeEmpty();

            foreach ($frames as $frame) {
                expect(collect($frame['args'] ?? [])->contains(
                    static fn (mixed $argument): bool => $argument instanceof SensitiveParameterValue,
                ))
                    ->toBeTrue()
                    ->and(json_encode($frame['args'] ?? [], JSON_PARTIAL_OUTPUT_ON_ERROR))
                    ->not->toContain($sourcePath);
            }
        }
    } finally {
        if (is_string($previous)) {
            ini_set('zend.exception_ignore_args', $previous);
        }

        sqlite_seed_remove_directory($sandbox);
    }
});

it('refuses a foreign destination without changing it or unrelated candidates', function (): void {
    $sandbox = sqlite_seed_sandbox();
    $sourcePath = "{$sandbox}/source/database.sqlite";
    $destinationPath = "{$sandbox}/target/database.sqlite";
    sqlite_seed_create_database($sourcePath, rows: 10);
    sqlite_seed_create_database($destinationPath, rows: 3);
    chmod($destinationPath, 0600);
    file_put_contents("{$sandbox}/target/.database.sqlite.orbit-foreign", 'foreign-candidate');
    $destinationInode = fileinode($destinationPath);
    $destinationDigest = hash_file('sha256', $destinationPath);

    try {
        $ssh = new SqliteSeedLocalSshExecutor(new NativeProcessRunner, $sandbox);
        $transfer = new SqliteSeedLocalTransfer($ssh);

        try {
            sqlite_seed_seeder($ssh, $transfer)->seed(
                sqlite_seed_source_placement($sandbox),
                sqlite_seed_target_placement(),
                $sourcePath,
            );
            $this->fail('The foreign SQLite destination was unexpectedly replaced.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)
                ->toBe('sqlite.seed_preflight_failed');
        }

        expect(fileinode($destinationPath))
            ->toBe($destinationInode)
            ->and(hash_file('sha256', $destinationPath))
            ->toBe($destinationDigest)
            ->and(file_get_contents("{$sandbox}/target/.database.sqlite.orbit-foreign"))
            ->toBe('foreign-candidate')
            ->and($transfer->transfers)
            ->toBe([]);
    } finally {
        sqlite_seed_remove_directory($sandbox);
    }
});

it('does not overwrite a destination created during atomic installation', function (): void {
    $sandbox = sqlite_seed_sandbox();
    $sourcePath = "{$sandbox}/source/database.sqlite";
    $destinationPath = "{$sandbox}/target/database.sqlite";
    sqlite_seed_create_database($sourcePath, rows: 10);

    try {
        $ssh = new SqliteSeedLocalSshExecutor(
            new NativeProcessRunner,
            $sandbox,
            targetProgramReplacements: [
                'os.link(candidate, destination_path, follow_symlinks=False)' => 'open(destination_path, "xb").write(b"foreign-destination"); os.link(candidate, destination_path, follow_symlinks=False)',
            ],
        );
        $transfer = new SqliteSeedLocalTransfer($ssh);

        try {
            sqlite_seed_seeder($ssh, $transfer)->seed(
                sqlite_seed_source_placement($sandbox),
                sqlite_seed_target_placement(),
                $sourcePath,
            );
            $this->fail('The destination race unexpectedly replaced the foreign file.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)
                ->toBe('sqlite.seed_failed');
        }

        expect(file_get_contents($destinationPath))
            ->toBe('foreign-destination')
            ->and(glob("{$sandbox}/target/.database.sqlite.orbit-*") ?: [])
            ->toBe([])
            ->and(glob("{$sandbox}/snapshots/*") ?: [])
            ->toBe([]);

        foreach ($transfer->incomingPaths as $incomingPath) {
            expect(glob("{$incomingPath}*") ?: [])
                ->toBe([]);
        }
    } finally {
        sqlite_seed_remove_directory($sandbox);
    }
});

it('retries owned snapshot transfer and installation failures without touching unrelated files', function (string $failure): void {
    $sandbox = sqlite_seed_sandbox();
    $sourcePath = "{$sandbox}/source/database.sqlite";
    sqlite_seed_create_database($sourcePath, rows: 50);
    file_put_contents("{$sandbox}/source/unrelated.txt", 'source-unrelated');
    file_put_contents("{$sandbox}/target/.database.sqlite.orbit-foreign", 'target-unrelated');

    try {
        $ssh = new SqliteSeedLocalSshExecutor(
            new NativeProcessRunner,
            $sandbox,
            failurePoint: $failure === 'transfer' ? null : $failure,
        );
        $transfer = new SqliteSeedLocalTransfer($ssh, failures: $failure === 'transfer' ? 1 : 0);
        $seeder = sqlite_seed_seeder($ssh, $transfer);

        try {
            $seeder->seed(
                sqlite_seed_source_placement($sandbox),
                sqlite_seed_target_placement(),
                $sourcePath,
            );
            $this->fail('The interrupted SQLite seed unexpectedly reported success.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)
                ->toBe(match ($failure) {
                    'snapshot' => 'sqlite.seed_preflight_failed',
                    'transfer' => 'sqlite.seed_transfer_failed',
                    'install' => 'sqlite.seed_failed',
                });
        }

        expect(file_exists("{$sandbox}/target/database.sqlite"))
            ->toBeFalse()
            ->and(file_get_contents("{$sandbox}/source/unrelated.txt"))
            ->toBe('source-unrelated')
            ->and(file_get_contents("{$sandbox}/target/.database.sqlite.orbit-foreign"))
            ->toBe('target-unrelated');

        $retry = $seeder->seed(
            sqlite_seed_source_placement($sandbox),
            sqlite_seed_target_placement(),
            $sourcePath,
        );

        expect($retry->confirmed)
            ->toBeTrue()
            ->and($retry->changed)
            ->toBeTrue();
        expect(sqlite_seed_integrity("{$sandbox}/target/database.sqlite"))
            ->toBe('ok')
            ->and(sqlite_seed_row_count("{$sandbox}/target/database.sqlite"))
            ->toBe(50)
            ->and(file_get_contents("{$sandbox}/source/unrelated.txt"))
            ->toBe('source-unrelated')
            ->and(file_get_contents("{$sandbox}/target/.database.sqlite.orbit-foreign"))
            ->toBe('target-unrelated');
        expect(glob("{$sandbox}/snapshots/*") ?: [])
            ->toBe([]);

        foreach ($transfer->incomingPaths as $incomingPath) {
            expect(glob("{$incomingPath}*") ?: [])
                ->toBe([]);
        }
    } finally {
        sqlite_seed_remove_directory($sandbox);
    }
})->with([
    'snapshot',
    'transfer',
    'install',
]);

it('re-prepares when a recorded incomplete target file is missing', function (string $missingFile): void {
    $sandbox = sqlite_seed_sandbox();
    $sourcePath = "{$sandbox}/source/database.sqlite";
    sqlite_seed_create_database($sourcePath, rows: 45);
    file_put_contents("{$sandbox}/target/unrelated.txt", 'target-unrelated');

    try {
        $ssh = new SqliteSeedLocalSshExecutor(
            new NativeProcessRunner,
            $sandbox,
            failurePoint: $missingFile === 'candidate' ? 'install' : null,
        );
        $transfer = new SqliteSeedLocalTransfer($ssh, failures: $missingFile === 'incoming' ? 1 : 0);
        $seeder = sqlite_seed_seeder($ssh, $transfer);

        try {
            $seeder->seed(
                sqlite_seed_source_placement($sandbox),
                sqlite_seed_target_placement(),
                $sourcePath,
            );
            $this->fail('The interrupted SQLite seed unexpectedly reported success.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)
                ->toBe($missingFile === 'incoming' ? 'sqlite.seed_transfer_failed' : 'sqlite.seed_failed');
        }

        $lostPath = $missingFile === 'incoming'
            ? $transfer->incomingPaths[0]
            : (glob("{$sandbox}/target/.database.sqlite.orbit-*") ?: [])[0];
        unlink($lostPath);

        $retry = $seeder->seed(
            sqlite_seed_source_placement($sandbox),
            sqlite_seed_target_placement(),
            $sourcePath,
        );

        expect($retry->confirmed)
            ->toBeTrue()
            ->and($retry->changed)
            ->toBeTrue()
            ->and(sqlite_seed_row_count("{$sandbox}/target/database.sqlite"))
            ->toBe(45)
            ->and(sqlite_seed_integrity("{$sandbox}/target/database.sqlite"))
            ->toBe('ok')
            ->and(file_exists($lostPath))
            ->toBeFalse()
            ->and(file_get_contents("{$sandbox}/target/unrelated.txt"))
            ->toBe('target-unrelated')
            ->and(glob("{$sandbox}/snapshots/*") ?: [])
            ->toBe([])
            ->and(glob("{$sandbox}/target/.database.sqlite.orbit-*") ?: [])
            ->toBe([])
            ->and(file_exists("{$sandbox}/state/source-11-target-22"))
            ->toBeFalse()
            ->and(file_exists("{$sandbox}/state/target-22/state.json"))
            ->toBeTrue()
            ->and($transfer->transfers)
            ->toHaveCount(2)
            ->and($transfer->incomingPaths)
            ->toHaveCount(2);

        foreach ($transfer->incomingPaths as $incomingPath) {
            expect(glob("{$incomingPath}*") ?: [])
                ->toBe([]);
        }
    } finally {
        sqlite_seed_remove_directory($sandbox);
    }
})->with([
    'missing prepared incoming' => 'incoming',
    'missing installing candidate' => 'candidate',
]);

it('keeps the installed inode when acknowledgement or cleanup is interrupted', function (string $interruption): void {
    $sandbox = sqlite_seed_sandbox();
    $sourcePath = "{$sandbox}/source/database.sqlite";
    sqlite_seed_create_database($sourcePath, rows: 40);

    try {
        $ssh = new SqliteSeedLocalSshExecutor(
            new NativeProcessRunner,
            $sandbox,
            lostAcknowledgement: $interruption,
        );
        $transfer = new SqliteSeedLocalTransfer($ssh);
        $seeder = sqlite_seed_seeder($ssh, $transfer);

        $unconfirmed = $seeder->seed(
            sqlite_seed_source_placement($sandbox),
            sqlite_seed_target_placement(),
            $sourcePath,
        );
        $installedInode = fileinode("{$sandbox}/target/database.sqlite");
        $installedDigest = hash_file('sha256', "{$sandbox}/target/database.sqlite");
        $retry = $seeder->seed(
            sqlite_seed_source_placement($sandbox),
            sqlite_seed_target_placement(),
            $sourcePath,
        );

        expect($unconfirmed->confirmed)
            ->toBeFalse()
            ->and($unconfirmed->changed)
            ->toBeNull();
        expect($retry->confirmed)
            ->toBeTrue()
            ->and($retry->changed)
            ->toBeFalse();
        expect(fileinode("{$sandbox}/target/database.sqlite"))
            ->toBe($installedInode)
            ->and(hash_file('sha256', "{$sandbox}/target/database.sqlite"))
            ->toBe($installedDigest)
            ->and(fileperms("{$sandbox}/target/database.sqlite") & 0777)
            ->toBe(0600)
            ->and(fileowner("{$sandbox}/target/database.sqlite"))
            ->toBe(posix_geteuid())
            ->and(sqlite_seed_integrity("{$sandbox}/target/database.sqlite"))
            ->toBe('ok');
        expect(glob("{$sandbox}/snapshots/*") ?: [])
            ->toBe([])
            ->and(file_get_contents("{$sandbox}/target/unrelated.txt"))
            ->toBe('target-unrelated')
            ->and($transfer->transfers)
            ->toHaveCount(1);
    } finally {
        sqlite_seed_remove_directory($sandbox);
    }
})->with([
    'target:install',
    'source:cleanup',
]);

it('retains owned source and target preparation after a lost target receipt', function (): void {
    $sandbox = sqlite_seed_sandbox();
    $sourcePath = "{$sandbox}/source/database.sqlite";
    sqlite_seed_create_database($sourcePath, rows: 30);

    try {
        $ssh = new SqliteSeedLocalSshExecutor(
            new NativeProcessRunner,
            $sandbox,
            lostAcknowledgement: 'target:prepare',
        );
        $transfer = new SqliteSeedLocalTransfer($ssh);
        $seeder = sqlite_seed_seeder($ssh, $transfer);

        try {
            $seeder->seed(
                sqlite_seed_source_placement($sandbox),
                sqlite_seed_target_placement(),
                $sourcePath,
            );
            $this->fail('The lost target preparation receipt unexpectedly reported success.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)
                ->toBe('sqlite.seed_preflight_failed');
        }

        expect(glob("{$sandbox}/snapshots/*") ?: [])
            ->toHaveCount(1)
            ->and(glob("{$sandbox}/state/*") ?: [])
            ->not->toBe([]);

        $retry = $seeder->seed(
            sqlite_seed_source_placement($sandbox),
            sqlite_seed_target_placement(),
            $sourcePath,
        );

        expect($retry->confirmed)
            ->toBeTrue()
            ->and($retry->changed)
            ->toBeTrue()
            ->and(sqlite_seed_integrity("{$sandbox}/target/database.sqlite"))
            ->toBe('ok')
            ->and($transfer->transfers)
            ->toHaveCount(1)
            ->and(glob("{$sandbox}/snapshots/*") ?: [])
            ->toBe([]);
    } finally {
        sqlite_seed_remove_directory($sandbox);
    }
});

it('regenerates a missing retained snapshot and replaces stale incomplete target state', function (bool $mutateSource): void {
    $sandbox = sqlite_seed_sandbox();
    $sourcePath = "{$sandbox}/source/database.sqlite";
    sqlite_seed_create_database($sourcePath, rows: 30);

    try {
        $ssh = new SqliteSeedLocalSshExecutor(new NativeProcessRunner, $sandbox);
        $transfer = new SqliteSeedLocalTransfer($ssh, failures: 1);
        $seeder = sqlite_seed_seeder($ssh, $transfer);

        try {
            $seeder->seed(
                sqlite_seed_source_placement($sandbox),
                sqlite_seed_target_placement(),
                $sourcePath,
            );
            $this->fail('The interrupted transfer unexpectedly reported success.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)
                ->toBe('sqlite.seed_transfer_failed');
        }

        $retainedSnapshots = glob("{$sandbox}/snapshots/*") ?: [];
        expect($retainedSnapshots)
            ->toHaveCount(1)
            ->and($transfer->incomingPaths)
            ->toHaveCount(1);
        unlink($retainedSnapshots[0]);

        if ($mutateSource) {
            $source = new PDO("sqlite:{$sourcePath}");
            $source->exec("INSERT INTO seed_events (value) VALUES ('after-interruption')");
            unset($source);
        }

        $retry = $seeder->seed(
            sqlite_seed_source_placement($sandbox),
            sqlite_seed_target_placement(),
            $sourcePath,
        );

        expect($retry->confirmed)
            ->toBeTrue()
            ->and($retry->changed)
            ->toBeTrue()
            ->and(sqlite_seed_row_count("{$sandbox}/target/database.sqlite"))
            ->toBe($mutateSource ? 31 : 30)
            ->and(sqlite_seed_integrity("{$sandbox}/target/database.sqlite"))
            ->toBe('ok')
            ->and(glob("{$sandbox}/snapshots/*") ?: [])
            ->toBe([])
            ->and(file_exists("{$sandbox}/state/source-11-target-22"))
            ->toBeFalse()
            ->and(file_exists("{$sandbox}/state/target-22/state.json"))
            ->toBeTrue()
            ->and(glob("{$sandbox}/target/.database.sqlite.orbit-*") ?: [])
            ->toBe([])
            ->and($transfer->transfers)
            ->toHaveCount(2);

        foreach ($transfer->incomingPaths as $incomingPath) {
            expect(glob("{$incomingPath}*") ?: [])
                ->toBe([]);
        }
    } finally {
        sqlite_seed_remove_directory($sandbox);
    }
})->with([
    'unchanged source' => false,
    'mutated source' => true,
]);

it('retains a completed target when the live source changes before an identical retry', function (): void {
    $sandbox = sqlite_seed_sandbox();
    $sourcePath = "{$sandbox}/source/database.sqlite";
    $destinationPath = "{$sandbox}/target/database.sqlite";
    sqlite_seed_create_database($sourcePath, rows: 20);

    try {
        $ssh = new SqliteSeedLocalSshExecutor(new NativeProcessRunner, $sandbox);
        $transfer = new SqliteSeedLocalTransfer($ssh);
        $seeder = sqlite_seed_seeder($ssh, $transfer);
        $first = $seeder->seed(
            sqlite_seed_source_placement($sandbox),
            sqlite_seed_target_placement(),
            $sourcePath,
        );
        $targetInode = fileinode($destinationPath);
        $targetDigest = hash_file('sha256', $destinationPath);
        $source = new PDO("sqlite:{$sourcePath}");
        $source->exec("INSERT INTO seed_events (value) VALUES ('after-completion')");
        unset($source);
        $retry = $seeder->seed(
            sqlite_seed_source_placement($sandbox),
            sqlite_seed_target_placement(),
            $sourcePath,
        );

        expect($first->confirmed)
            ->toBeTrue()
            ->and($first->changed)
            ->toBeTrue()
            ->and($retry->confirmed)
            ->toBeTrue()
            ->and($retry->changed)
            ->toBeFalse();
        expect(fileinode($destinationPath))
            ->toBe($targetInode)
            ->and(hash_file('sha256', $destinationPath))
            ->toBe($targetDigest)
            ->and(sqlite_seed_row_count($destinationPath))
            ->toBe(20)
            ->and(sqlite_seed_row_count($sourcePath))
            ->toBe(21)
            ->and($transfer->transfers)
            ->toHaveCount(1)
            ->and(glob("{$sandbox}/snapshots/*") ?: [])
            ->toBe([]);
    } finally {
        sqlite_seed_remove_directory($sandbox);
    }
});

function sqlite_seed_sandbox(): string
{
    $sandbox = sys_get_temp_dir().'/orbit-sqlite-seed-'.bin2hex(random_bytes(8));
    mkdir($sandbox, 0700);
    mkdir("{$sandbox}/source", 0700);
    mkdir("{$sandbox}/target", 0700);
    mkdir("{$sandbox}/state", 0700);
    mkdir("{$sandbox}/snapshots", 0700);
    file_put_contents("{$sandbox}/target/unrelated.txt", 'target-unrelated');

    return $sandbox;
}

function sqlite_seed_remove_directory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        if ($entry->isLink() || $entry->isFile()) {
            unlink($entry->getPathname());

            continue;
        }

        rmdir($entry->getPathname());
    }

    rmdir($directory);
}

function sqlite_seed_create_database(string $path, int $rows = 5, bool $wal = false): void
{
    $database = new PDO("sqlite:{$path}");

    if ($wal) {
        $database->exec('PRAGMA journal_mode = WAL');
    }

    $database->exec('CREATE TABLE seed_events (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT NOT NULL)');
    $insert = $database->prepare('INSERT INTO seed_events (value) VALUES (?)');

    for ($index = 1; $index <= $rows; $index++) {
        $insert->execute(["seed-row-{$index}"]);
    }
}

function sqlite_seed_row_count(string $path): int
{
    $database = new PDO("sqlite:{$path}");

    return (int) $database->query('SELECT COUNT(*) FROM seed_events')->fetchColumn();
}

function sqlite_seed_integrity(string $path): string
{
    $database = new PDO("sqlite:{$path}");

    return (string) $database->query('PRAGMA integrity_check')->fetchColumn();
}

function sqlite_seed_journal_mode(string $path): string
{
    $database = new PDO("sqlite:{$path}");

    return (string) $database->query('PRAGMA journal_mode')->fetchColumn();
}

function sqlite_seed_wal_writer(string $databasePath, string $stopPath, int $delayMicroseconds = 1_000): Process
{
    $program = <<<'PYTHON'
        import os, sqlite3, sys, time

        database_path, stop_path, delay_microseconds = sys.argv[1:]
        delay_seconds = int(delay_microseconds) / 1000000
        database = sqlite3.connect(database_path, timeout=5.0)
        database.execute("PRAGMA journal_mode = WAL")
        print("READY", flush=True)
        counter = 0
        while not os.path.exists(stop_path):
            counter += 1
            database.execute("INSERT INTO seed_events (value) VALUES (?)", (f"live-row-{counter}",))
            database.commit()
            if delay_seconds > 0:
                time.sleep(delay_seconds)
        database.close()
        PYTHON;

    return new Process(['python3', '-c', $program, $databasePath, $stopPath, (string) $delayMicroseconds]);
}

function sqlite_seed_source_placement(string $sandbox): SqliteSeedPlacement
{
    return new SqliteSeedPlacement(
        appInstanceId: 11,
        environment: 'development',
        basePath: "{$sandbox}/source",
        executionUser: sqlite_seed_user(),
        node: sqlite_seed_node(),
    );
}

function sqlite_seed_target_placement(): SqliteSeedPlacement
{
    return new SqliteSeedPlacement(
        appInstanceId: 22,
        environment: 'production',
        basePath: sqlite_seed_home(),
        executionUser: sqlite_seed_user(),
        node: sqlite_seed_node(),
    );
}

function sqlite_seed_node(): Node
{
    $node = new Node;
    $node->forceFill([
        'wireguard_ip' => '127.0.0.1',
        'user' => sqlite_seed_user(),
    ]);

    return $node;
}

function sqlite_seed_user(): string
{
    $account = posix_getpwuid(posix_geteuid());

    if (! is_array($account) || ! is_string($account['name'] ?? null)) {
        throw new RuntimeException('The test user is unavailable.');
    }

    return $account['name'];
}

function sqlite_seed_home(): string
{
    $account = posix_getpwuid(posix_geteuid());

    if (! is_array($account) || ! is_string($account['dir'] ?? null)) {
        throw new RuntimeException('The test home is unavailable.');
    }

    return $account['dir'];
}

function sqlite_seed_seeder(
    SshExecutor $ssh,
    SqliteSnapshotTransfer $transfer,
): RemoteAppInstanceSqliteSeeder {
    return new RemoteAppInstanceSqliteSeeder(
        $ssh,
        $transfer,
        sqlite_seed_key_provider(),
        sqlite_seed_known_hosts(),
    );
}

function sqlite_seed_key_provider(): SshKeyProvider
{
    return new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-sqlite-seed-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 synthetic';
        }
    };
}

function sqlite_seed_known_hosts(): KnownHostsStore
{
    return new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/orbit-sqlite-seed-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
}

final class SqliteSeedLocalTransfer implements SqliteSnapshotTransfer
{
    /** @var list<array{source: string, target: string, bytes: int, digest: string}> */
    public array $transfers = [];

    /** @var list<string> */
    public array $incomingPaths = [];

    public function __construct(
        private readonly SqliteSeedLocalSshExecutor $ssh,
        private int $failures = 0,
    ) {}

    public function transfer(
        SqliteSeedPlacement $source,
        string $sourcePath,
        SqliteSeedPlacement $target,
        string $targetPath,
        int $expectedBytes,
        string $expectedDigest,
    ): void {
        $this->transfers[] = [
            'source' => $sourcePath,
            'target' => $targetPath,
            'bytes' => $expectedBytes,
            'digest' => $expectedDigest,
        ];
        $this->incomingPaths[] = $targetPath;

        if ($this->failures > 0) {
            $this->failures--;

            throw new RuntimeException('Injected protected transfer interruption.');
        }

        $actualSource = $this->ssh->actualSnapshotPath($sourcePath);

        if (
            filesize($actualSource) !== $expectedBytes
            || hash_file('sha256', $actualSource) !== $expectedDigest
            || ! copy($actualSource, $targetPath)
        ) {
            throw new RuntimeException('The local protected transfer failed.');
        }
    }
}

final class SqliteSeedLocalSshExecutor implements SshExecutor
{
    /** @var list<array{role: string, mode: string, arguments: list<string>}> */
    public array $commands = [];

    /** @var array<string, string> */
    private array $snapshotPaths = [];

    private ?string $remainingFailurePoint;

    private ?string $remainingLostAcknowledgement;

    /**
     * @param  array<string, string>  $sourceProgramReplacements
     * @param  array<string, string>  $targetProgramReplacements
     */
    public function __construct(
        private readonly NativeProcessRunner $runner,
        private readonly string $sandbox,
        private readonly array $sourceProgramReplacements = [],
        private readonly array $targetProgramReplacements = [],
        ?string $failurePoint = null,
        ?string $lostAcknowledgement = null,
    ) {
        $this->remainingFailurePoint = $failurePoint;
        $this->remainingLostAcknowledgement = $lostAcknowledgement;
    }

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $separator = array_search('--', $command->arguments, true);

        if (! is_int($separator)) {
            throw new RuntimeException('The SQLite seed command has no privilege boundary.');
        }

        $arguments = array_values(array_slice($command->arguments, $separator + 1));
        $role = str_contains($arguments[2], 'source.backup(destination') ? 'source' : 'target';
        $mode = $arguments[3];
        $this->commands[] = [
            'role' => $role,
            'mode' => $mode,
            'arguments' => $command->arguments,
        ];

        $declaredSnapshotPath = null;
        $actualSnapshotPath = null;

        if ($role === 'source') {
            $declaredSnapshotPath = $arguments[10];
            $actualSnapshotPath = "{$this->sandbox}/snapshots/".basename($declaredSnapshotPath);
            $this->snapshotPaths[$declaredSnapshotPath] = $actualSnapshotPath;
            $arguments[9] = "{$this->sandbox}/state/".basename($arguments[9]);
            $arguments[10] = $actualSnapshotPath;
            $arguments[2] = str_replace(
                array_keys($this->sourceProgramReplacements),
                array_values($this->sourceProgramReplacements),
                $arguments[2],
            );
        } else {
            $arguments[4] = "{$this->sandbox}/target";
            $arguments[8] = "{$this->sandbox}/state/".basename($arguments[8]);
            $arguments[2] = str_replace(
                ['if home != target_account.pw_dir:', ...array_keys($this->targetProgramReplacements)],
                ['if False:', ...array_values($this->targetProgramReplacements)],
                $arguments[2],
            );
        }

        $key = "{$role}:{$mode}";

        if ($this->remainingFailurePoint === 'snapshot' && $key === 'source:prepare') {
            $arguments[2] = str_replace(
                'source.backup(destination, pages=-1, progress=progress, sleep=0.01)',
                '(_ for _ in ()).throw(sqlite3.DatabaseError())',
                $arguments[2],
            );
            $this->remainingFailurePoint = null;
        }

        if ($this->remainingFailurePoint === 'install' && $key === 'target:install') {
            $arguments[2] = str_replace(
                'os.link(candidate, destination_path, follow_symlinks=False)',
                '(_ for _ in ()).throw(OSError())',
                $arguments[2],
            );
            $this->remainingFailurePoint = null;
        }

        $result = $this->runner->run(new ProcessInvocation(
            arguments: $arguments,
            input: $command->input,
            protectedInput: $command->protectedInput,
            maxOutputBytes: $command->maxOutputBytes,
        ));

        if (is_string($declaredSnapshotPath) && is_string($actualSnapshotPath)) {
            $result = new CommandResult(
                $result->exitCode,
                str_replace($actualSnapshotPath, $declaredSnapshotPath, $result->stdout),
                $result->stderr,
                $result->durationMs,
                $result->truncated,
            );
        }

        if ($this->remainingLostAcknowledgement !== $key) {
            return $result;
        }

        $this->remainingLostAcknowledgement = null;

        return new CommandResult(255, '', 'Connection closed.', $result->durationMs, false);
    }

    public function actualSnapshotPath(string $declaredPath): string
    {
        return $this->snapshotPaths[$declaredPath]
            ?? throw new RuntimeException('The source snapshot path was not recorded.');
    }

    /** @return list<string> */
    public function commandModes(): array
    {
        return array_map(
            static fn (array $command): string => "{$command['role']}:{$command['mode']}",
            $this->commands,
        );
    }

    public function hasOnlyFixedPythonCommands(): bool
    {
        foreach ($this->commands as $command) {
            if (array_slice($command['arguments'], 0, 5) !== ['sudo', '-n', '--', 'python3', '-c']) {
                return false;
            }
        }

        return true;
    }
}

final class SqliteSeedScpProcessRunner implements ProcessRunner
{
    /** @var list<ProcessInvocation> */
    public array $invocations = [];

    public function __construct(
        private readonly ?string $failureDiagnostic = null,
    ) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->invocations[] = $invocation;

        if ($this->failureDiagnostic !== null) {
            return new CommandResult(1, $this->failureDiagnostic, $this->failureDiagnostic, 1, false);
        }

        $source = $invocation->arguments[array_key_last($invocation->arguments) - 1];
        $target = $invocation->arguments[array_key_last($invocation->arguments)];
        $sourcePath = $this->localPath($source);
        $targetPath = $this->localPath($target);

        if (! copy($sourcePath, $targetPath)) {
            return new CommandResult(1, '', '', 1, false);
        }

        return new CommandResult(0, '', '', 1, false);
    }

    private function localPath(string $path): string
    {
        if (! str_contains($path, '@')) {
            return $path;
        }

        if (preg_match('/\A[^@]+@(?:\[[^]]+\]|[^:]+):(.+)\z/D', $path, $matches) !== 1) {
            throw new RuntimeException('The fake received an invalid remote path.');
        }

        return $matches[1];
    }
}

final readonly class SqliteSeedObservationSshExecutor implements SshExecutor
{
    public function __construct(
        private CommandResult $result,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        return $this->result;
    }
}
