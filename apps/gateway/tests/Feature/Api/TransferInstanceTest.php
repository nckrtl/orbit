<?php

declare(strict_types=1);

use App\Actions\Instances\TransferInstanceAction;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Sqlite\InstanceSqliteSeeder;
use App\Domain\Instances\Transfer\InstanceTransferRouteProjector;
use App\Domain\Instances\Transfer\InstanceTransferRuntime;
use App\Domain\Instances\Transfer\InstanceTransferSource;
use App\Domain\Instances\Transfer\InstanceTransferStatus;
use App\Domain\Instances\Transfer\InstanceTransferStep;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\LocalInstanceTransferTransport;
use Tests\Support\Orb245Accounts;
use Tests\Support\Orb245DestinationGuard;
use Tests\Support\Orb245EnvironmentLock;
use Tests\Support\Orb245EnvironmentReader;
use Tests\Support\Orb245EnvironmentWriter;
use Tests\Support\Orb245Projection;
use Tests\Support\Orb245SourceLock;
use Tests\Support\Orb245SqliteSeeder;
use Tests\Support\Orb245TransferRuntime;
use Tests\Support\Orb245TransferSource;

beforeEach(function (): void {
    $this->caller = transfer_api_node('transfer-api-caller');
    $this->caller->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $sourceCluster = Cluster::query()->create([
        'name' => 'transfer-api-source',
        'tld' => 'dev.orbit',
        'state' => ClusterState::Active,
    ]);
    $destinationCluster = Cluster::query()->create([
        'name' => 'transfer-api-destination',
        'tld' => 'other.orbit',
        'state' => ClusterState::Active,
    ]);
    $this->sourceNode = transfer_api_app_dev('transfer-api-source', $sourceCluster, '10.44.46.10');
    $this->destinationNode = transfer_api_app_dev('transfer-api-destination', $destinationCluster, '10.44.46.11');
    $project = Project::query()->create([
        'name' => 'Transfer API',
        'slug' => 'transfer-api',
        'repository_url' => 'https://example.test/transfer-api.git',
        'default_branch' => 'main',
        'apps' => fixture_apps(null),
    ]);
    $this->instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $this->sourceNode->id,
        'name' => 'web',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/apps/transfer-api/web',
        'branch' => 'main',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'cluster_id' => $sourceCluster->id,
        'generation_basis_node_id' => $this->sourceNode->id,
        'domain' => 'web.web.transfer-api.dev.orbit',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create([
        'instance_id' => $this->instance->id,
        'position' => 0,
    ]);
    $route->update(['status' => RouteStatus::Active]);
    $this->transferUrl = "/api/v1/instances/{$this->instance->id}/transfer";
});

it('returns 422 for malformed duplicate unknown or forbidden transfer input before mutation', function (
    string $body,
): void {
    $sentinel = 'TRANSFER_API_INPUT_SENTINEL';

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call(
            'POST',
            $this->transferUrl,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: str_replace('__SENTINEL__', $sentinel, $body),
        );

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect($this->instance->refresh()->node_id)
        ->toBe($this->sourceNode->id)
        ->and(json_encode(Activity::query()->sole()->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain($sentinel);
})->with([
    'malformed JSON' => ['{"node_id":'],
    'array body' => ['[]'],
    'duplicate member' => ['{"node_id":1,"node_id":2}'],
    'unknown member' => ['{"node_id":1,"unknown":"__SENTINEL__"}'],
    'destination path' => ['{"node_id":1,"destination_path":"__SENTINEL__"}'],
    'missing required members' => ['{}'],
    'malformed Node ID' => ['{"node_id":"bad"}'],
    'signed Node ID' => ['{"node_id":"+3"}'],
    'boolean Node ID' => ['{"node_id":true}'],
    'invalid target name' => ['{"node_id":1,"name":"Not Normalized"}'],
    'relative SQLite path' => ['{"node_id":1,"sqlite_source_path":"database.sqlite"}'],
]);

it('returns 403 before transfer when the caller lacks destination Node access', function (): void {
    $caller = transfer_api_node('transfer-api-direct-caller');
    $caller->accessibleNodes()->attach($this->sourceNode);
    $sqliteSourcePath = '/srv/orbit/apps/transfer-api/web/TRANSFER_SQLITE_PATH_SENTINEL.sqlite';

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->postJson($this->transferUrl, [
            'node_id' => $this->destinationNode->id,
            'name' => 'preview',
            'sqlite_source_path' => $sqliteSourcePath,
        ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required')
        ->assertJsonPath('error.details.serving_node.id', $this->destinationNode->id);

    $activity = Activity::query()->where('command', 'instance:transfer')->sole();
    expect($this->instance->refresh()->node_id)
        ->toBe($this->sourceNode->id)
        ->and($activity->properties?->get('input'))
        ->toBe([
            'node_id' => $this->destinationNode->id,
            'name' => 'preview',
            'sqlite_selected' => true,
        ])
        ->and(json_encode($activity->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain($sqliteSourcePath, 'sqlite_source_path');
});

it('transfers the Instance and records sanitized activity', function (): void {
    transfer_api_bind_fakes();

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->transferUrl, [
            'node_id' => $this->destinationNode->id,
        ]);

    $instance = $this->instance->refresh()->load('routes');
    $response
        ->assertCreated()
        ->assertJsonPath('data.id', $instance->id)
        ->assertJsonPath('data.node_id', $this->destinationNode->id)
        ->assertJsonPath('data.name', 'web')
        ->assertJsonPath('data.checkout_path', '/srv/orbit/apps/transfer-api/web')
        ->assertJsonPath('data.domain', 'web.web.transfer-api.other.orbit')
        ->assertJsonPath('data.transfer.status', 'completed')
        ->assertJsonPath('data.transfer.cleanup_completed', true)
        ->assertJsonPath('data.transfer.sqlite_selected', false);

    $activity = Activity::query()->where('command', 'instance:transfer')->sole();
    expect($activity->status)->toBe('succeeded')
        ->and($activity->subject_id)->toBe($instance->id)
        ->and($activity->properties?->get('input'))
        ->toBe([
            'node_id' => $this->destinationNode->id,
            'sqlite_selected' => false,
        ]);
});

it('returns 200 for an identical completed transfer request', function (): void {
    transfer_api_bind_fakes();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->transferUrl, ['node_id' => $this->destinationNode->id])
        ->assertCreated();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->transferUrl, ['node_id' => $this->destinationNode->id])
        ->assertOk()
        ->assertJsonPath('data.transfer.status', 'completed');
});

it('transfers a selected SQLite file inside the checkout using the real archive and snapshot programs', function (string $layout, bool $retryAfterRollback = false): void {
    transfer_api_bind_fakes();
    $sandbox = sys_get_temp_dir().'/orbit-transfer-sqlite-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $sourcePath = $sandbox.'/source-'.basename($sandbox);
    $files->ensureDirectoryExists($sourcePath.'/database', 0700);
    $files->ensureDirectoryExists($sandbox.'/snapshots', 0700);
    $files->ensureDirectoryExists($sandbox.'/state', 0700);
    $databasePath = $sourcePath.'/database/selected [1].sqlite';
    $captureArchives = [];

    try {
        new Process(['git', 'init', '--quiet', '--initial-branch=main'], $sourcePath)->mustRun();
        $database = new PDO('sqlite:'.$databasePath);
        $database->exec('CREATE TABLE entries (value TEXT NOT NULL)');
        $database->exec("INSERT INTO entries VALUES ('committed')");
        file_put_contents($sourcePath.'/database/selected 1.sqlite', 'unselected wildcard neighbour');
        file_put_contents($sourcePath.'/README.md', 'checkout contents');
        new Process(['git', 'add', '.'], $sourcePath)->mustRun();
        new Process(['git', '-c', 'user.name=Orbit', '-c', 'user.email=orbit@example.test', 'commit', '--quiet', '-m', 'Start'], $sourcePath)->mustRun();
        unset($database);

        if ($layout === 'worktree') {
            $worktree = $sandbox.'/worktree-'.basename($sandbox);
            new Process(['git', 'worktree', 'add', '--quiet', '-b', 'preview', $worktree], $sourcePath)->mustRun();
            $sourcePath = $worktree;
            $databasePath = $sourcePath.'/database/selected [1].sqlite';
        }
        $database = new PDO('sqlite:'.$databasePath);
        $database->exec('PRAGMA journal_mode = WAL');
        $database->exec("INSERT INTO entries VALUES ('from WAL snapshot')");
        expect(is_file($databasePath.'-wal'))->toBeTrue()
            ->and(is_file($databasePath.'-shm'))->toBeTrue();
        $account = posix_getpwuid(posix_geteuid());
        $this->sourceNode->update(['user' => $account['name']]);
        $this->destinationNode->update(['user' => $account['name'], 'settings' => ['apps' => ['path' => $sandbox.'/destination']]]);
        $this->instance->update(['checkout_path' => $sourcePath, 'source_layout' => $layout]);
        $transport = new LocalInstanceTransferTransport($sandbox);
        app()->instance(InstanceTransferSource::class, $transport->source());
        app()->instance(InstanceSqliteSeeder::class, $transport->seeder());
        app()->forgetInstance(TransferInstanceAction::class);
        if ($retryAfterRollback) {
            $failOnce = true;
            Route::creating(static function (Route $route) use (&$failOnce): void {
                if ($route->status === RouteStatus::Pending && $failOnce) {
                    $failOnce = false;
                    throw new ResourceOperationException('route.prepare_failed', 'Route preparation failed.', 409);
                }
            });
            $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
                ->postJson($this->transferUrl, [
                    'node_id' => $this->destinationNode->id,
                    'sqlite_source_path' => $databasePath,
                ])->assertConflict()->assertJsonPath('error.code', 'route.prepare_failed');
            expect(InstanceTransfer::query()->sole()->cutover_at)->toBeNull();
        }

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
            ->postJson($this->transferUrl, [
                'node_id' => $this->destinationNode->id,
                'sqlite_source_path' => $databasePath,
            ]);
        $captureArchives = glob('/tmp/orbit-transfer-'.basename($sourcePath).'-*.tar') ?: [];
        expect($response->json('error.code'))->toBeNull();
        $response->assertCreated()->assertJsonPath('data.transfer.status', 'completed');

        $target = $sandbox.'/destination/transfer-api/web';
        expect(file_exists($target.'/database/selected [1].sqlite-wal'))->toBeFalse()
            ->and(file_exists($target.'/database/selected [1].sqlite-shm'))->toBeFalse()
            ->and(file_get_contents($target.'/database/selected 1.sqlite'))->toBe('unselected wildcard neighbour')
            ->and(file_get_contents($target.'/README.md'))->toBe('checkout contents')
            ->and($this->instance->refresh()->node_id)->toBe($this->destinationNode->id);
        $snapshot = new PDO('sqlite:'.$target.'/database/selected [1].sqlite');
        expect($snapshot->query('SELECT value FROM entries ORDER BY rowid')->fetchAll(PDO::FETCH_COLUMN))
            ->toBe(['committed', 'from WAL snapshot']);
    } finally {
        unset($database, $snapshot);
        $files->deleteDirectory($sandbox);
        foreach ($captureArchives as $archive) {
            unlink($archive);
        }
        // The materializer removes this on success; a failed upload can leave it behind.
    }
})->with(['checkout', 'worktree', 'checkout after finished rollback' => ['checkout', true]]);

it('preserves post-restore SQLite writes on an identical retry after a copy failure and lost destination rollback response', function (): void {
    [$sandbox, $database, $transport] = transfer_api_sqlite_recovery_fixture($this->instance, $this->sourceNode, $this->destinationNode);
    $transport->snapshotCopyFailures = 1;
    $transport->lostDiscardResponse = true;
    $snapshotsAtRestore = [];
    app(InstanceTransferRuntime::class)->onRestore = static function () use ($database, $transport, &$snapshotsAtRestore): void {
        if ($snapshotsAtRestore !== []) {
            return;
        }
        $snapshotsAtRestore[] = file_exists($transport->snapshotPaths()[0]);
        $database->exec("INSERT INTO entries VALUES ('committed after restore')");
    };
    $input = ['node_id' => $this->destinationNode->id, 'sqlite_source_path' => $this->instance->checkout_path.'/database.sqlite'];

    try {
        $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
            ->postJson($this->transferUrl, $input)->assertConflict()->assertJsonPath('error.code', 'sqlite.seed_transfer_failed');
        $failed = InstanceTransfer::query()->sole();
        expect($failed->recovery_evidence['incomplete'])->toContain('destination-checkout')
            ->and(is_dir($sandbox.'/destination/transfer-api/web'))->toBeFalse()
            ->and(app(InstanceTransferRuntime::class)->calls)->toContain('restore')
            ->and($snapshotsAtRestore)->toBe([false]);

        $this->postJson($this->transferUrl, $input)->assertOk()->assertJsonPath('data.transfer.status', 'completed');

        $target = new PDO('sqlite:'.$sandbox.'/destination/transfer-api/web/database.sqlite');
        expect($target->query('SELECT value FROM entries ORDER BY rowid')->fetchAll(PDO::FETCH_COLUMN))
            ->toBe(['original', 'committed after restore'])
            ->and($transport->copiedDigests)->toHaveCount(2)
            ->and($transport->copiedDigests[1])->not->toBe($transport->copiedDigests[0])
            ->and(InstanceTransfer::query()->sole()->id)->toBe($failed->id);
    } finally {
        unset($database, $target);
        transfer_api_clean_sqlite_recovery_fixture($this->instance, $sandbox, $transport);
    }
});

it('closes a failed SQLite transfer only after removing its owned snapshot and incoming payload', function (): void {
    [$sandbox, $database, $transport] = transfer_api_sqlite_recovery_fixture($this->instance, $this->sourceNode, $this->destinationNode);
    $transport->snapshotCopyFailures = 1;
    file_put_contents($sandbox.'/unrelated.sqlite', 'unrelated sentinel');

    try {
        $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
            ->postJson($this->transferUrl, [
                'node_id' => $this->destinationNode->id,
                'sqlite_source_path' => $this->instance->checkout_path.'/database.sqlite',
            ])->assertConflict()->assertJsonPath('error.code', 'sqlite.seed_transfer_failed');

        $failed = InstanceTransfer::query()->sole();
        expect($failed->recovery_evidence)->toBeNull()
            ->and(InstanceTransfer::query()->closed()->whereKey($failed->id)->exists())->toBeTrue()
            ->and($transport->snapshotPaths())->toHaveCount(1)
            ->and($transport->incomingPaths)->toHaveCount(1);
        foreach ([...$transport->snapshotPaths(), ...$transport->incomingPaths] as $payload) {
            expect(file_exists($payload))->toBeFalse();
        }
        expect(glob($sandbox.'/state/*') ?: [])->toBe([])
            ->and(file_get_contents($sandbox.'/unrelated.sqlite'))->toBe('unrelated sentinel');
    } finally {
        unset($database);
        transfer_api_clean_sqlite_recovery_fixture($this->instance, $sandbox, $transport);
    }
});

it('finishes persisted rollback before fresh capture after interruption at a forward SQLite checkpoint', function (string $boundary): void {
    [$sandbox, $database, $transport] = transfer_api_sqlite_recovery_fixture($this->instance, $this->sourceNode, $this->destinationNode);
    $destination = $sandbox.'/destination/transfer-api/web';
    $persistedAtInterruption = null;
    $destinationSaved = false;
    $failOnce = true;
    Route::creating(static function (Route $route) use (&$failOnce): void {
        if ($route->status === RouteStatus::Pending && $failOnce) {
            $failOnce = false;
            throw new ResourceOperationException('route.prepare_failed', 'Injected failure after the SQLite forward checkpoint.', 409);
        }
    });
    app(InstanceTransferRuntime::class)->onRestore = static function () use ($database, $boundary, $sandbox, $destination, &$persistedAtInterruption, &$destinationSaved): void {
        $database->exec("INSERT INTO entries VALUES ('committed after source restoration')");
        if ($boundary === 'source restored') {
            $persistedAtInterruption = InstanceTransfer::query()->sole()->getAttributes();
            $destinationSaved = new Filesystem()->copyDirectory($destination, $sandbox.'/saved-destination');
        }
    };
    if ($boundary === 'destination deleted') {
        $transport->onDiscard = static function () use (&$persistedAtInterruption): void {
            $persistedAtInterruption = InstanceTransfer::query()->sole()->getAttributes();
        };
    }
    $input = ['node_id' => $this->destinationNode->id, 'sqlite_source_path' => $this->instance->checkout_path.'/database.sqlite'];

    try {
        $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
            ->postJson($this->transferUrl, $input)->assertConflict()->assertJsonPath('error.code', 'route.prepare_failed');
        expect($persistedAtInterruption)->toBeArray()
            ->and($persistedAtInterruption['current_step'])->toBe(InstanceTransferStep::RuntimeRelocated->value)
            ->and($persistedAtInterruption['cutover_at'])->toBeNull()
            ->and($destinationSaved)->toBe($boundary === 'source restored');
        expect($database->query('SELECT value FROM entries ORDER BY rowid')->fetchAll(PDO::FETCH_COLUMN))
            ->toBe(['original', 'committed after source restoration']);

        // Replay exactly the row persisted at the interruption, not the later rollback-completion write.
        DB::table('instance_transfers')->where('id', $persistedAtInterruption['id'])->update($persistedAtInterruption);
        if ($boundary === 'source restored') {
            expect(new Filesystem()->copyDirectory($sandbox.'/saved-destination', $destination))->toBeTrue();
        }
        app(InstanceTransferRuntime::class)->onRestore = null;
        $transport->onDiscard = null;
        app()->forgetInstance(TransferInstanceAction::class);
        $this->postJson($this->transferUrl, [...$input, 'name' => 'other'])->assertConflict()->assertJsonPath('error.code', 'instance.transfer_retry_conflict');
        $this->deleteJson('/api/v1/instances/'.$this->instance->id)->assertConflict()->assertJsonPath('error.code', 'instance.transfer_incomplete');

        $this->postJson($this->transferUrl, $input)->assertOk()->assertJsonPath('data.transfer.status', 'completed');

        expect(is_file($destination.'/database.sqlite'))->toBeTrue();
        $target = new PDO('sqlite:'.$destination.'/database.sqlite');
        expect($target->query('SELECT value FROM entries ORDER BY rowid')->fetchAll(PDO::FETCH_COLUMN))
            ->toBe(['original', 'committed after source restoration'])
            ->and($transport->stagedPaths)->toHaveCount(4)
            ->and(array_count_values(app(InstanceTransferRuntime::class)->calls)['pause'])->toBe(2)
            ->and($transport->copiedDigests)->toHaveCount(2)
            ->and($transport->copiedDigests[1])->not->toBe($transport->copiedDigests[0])
            ->and(InstanceTransfer::query()->sole()->id)->toBe($persistedAtInterruption['id'])
            ->and($persistedAtInterruption['status'])->toBe(InstanceTransferStatus::Failed->value)
            ->and(json_decode($persistedAtInterruption['recovery_evidence'], true, flags: JSON_THROW_ON_ERROR)['rollback_pending'])->toBeTrue();
    } finally {
        unset($database, $target);
        transfer_api_clean_sqlite_recovery_fixture($this->instance, $sandbox, $transport);
    }
})->with(['source restored', 'destination deleted']);

it('keeps failed seed cleanup open and blocks recapture until an identical retry confirms cleanup', function (string $side): void {
    [$sandbox, $database, $transport] = transfer_api_sqlite_recovery_fixture($this->instance, $this->sourceNode, $this->destinationNode);
    $transport->snapshotCopyFailures = 1;
    $transport->failSourceCleanup = $side === 'source';
    $transport->failTargetCleanup = $side === 'target';
    $input = ['node_id' => $this->destinationNode->id, 'sqlite_source_path' => $this->instance->checkout_path.'/database.sqlite'];

    try {
        $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
            ->postJson($this->transferUrl, $input)->assertConflict()->assertJsonPath('error.code', 'sqlite.seed_transfer_failed');
        $failed = InstanceTransfer::query()->sole();
        expect($failed->recovery_evidence['incomplete'])->toContain('sqlite-seed')
            ->and(InstanceTransfer::query()->open()->whereKey($failed->id)->exists())->toBeTrue()
            ->and(file_exists($transport->snapshotPaths()[0]))->toBe($side === 'source')
            ->and(file_exists($transport->incomingPaths[0]))->toBe($side === 'target');
        $database->exec("INSERT INTO entries VALUES ('committed after restore')");
        $this->postJson($this->transferUrl, [...$input, 'name' => 'other'])->assertConflict()->assertJsonPath('error.code', 'instance.transfer_retry_conflict');
        $this->deleteJson('/api/v1/instances/'.$this->instance->id)->assertConflict()->assertJsonPath('error.code', 'instance.transfer_incomplete');
        $this->postJson($this->transferUrl, $input)->assertConflict()->assertJsonPath('error.code', 'instance.transfer_failed');
        expect($transport->stagedPaths)->toHaveCount(2)
            ->and($transport->copiedDigests)->toHaveCount(1)
            ->and($failed->refresh()->recovery_evidence['incomplete'])->toContain('sqlite-seed');

        $transport->failSourceCleanup = false;
        $transport->failTargetCleanup = false;
        $this->postJson($this->transferUrl, $input)->assertOk()->assertJsonPath('data.transfer.status', 'completed');

        $target = new PDO('sqlite:'.$sandbox.'/destination/transfer-api/web/database.sqlite');
        expect($target->query('SELECT value FROM entries ORDER BY rowid')->fetchAll(PDO::FETCH_COLUMN))
            ->toBe(['original', 'committed after restore']);
        foreach ([...$transport->snapshotPaths(), ...$transport->incomingPaths] as $payload) {
            expect(file_exists($payload))->toBeFalse();
        }
    } finally {
        unset($database, $target);
        transfer_api_clean_sqlite_recovery_fixture($this->instance, $sandbox, $transport);
    }
})->with(['source', 'target']);

it('repeats seed cleanup safely after its successful response is lost without recopying stale data', function (string $side): void {
    [$sandbox, $database, $transport] = transfer_api_sqlite_recovery_fixture($this->instance, $this->sourceNode, $this->destinationNode);
    $transport->snapshotCopyFailures = 1;
    $transport->lostSourceCleanupResponse = $side === 'source';
    $transport->lostTargetCleanupResponse = $side === 'target';
    $input = ['node_id' => $this->destinationNode->id, 'sqlite_source_path' => $this->instance->checkout_path.'/database.sqlite'];

    try {
        $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
            ->postJson($this->transferUrl, $input)->assertConflict()->assertJsonPath('error.code', 'sqlite.seed_transfer_failed');
        $failed = InstanceTransfer::query()->sole();
        expect($failed->recovery_evidence['incomplete'])->toContain('sqlite-seed');
        foreach ([...$transport->snapshotPaths(), ...$transport->incomingPaths] as $payload) {
            expect(file_exists($payload))->toBeFalse();
        }
        expect(glob($sandbox.'/state/*') ?: [])->toBe([]);
        $database->exec("INSERT INTO entries VALUES ('committed after restore')");

        $this->postJson($this->transferUrl, $input)->assertOk()->assertJsonPath('data.transfer.status', 'completed');

        $target = new PDO('sqlite:'.$sandbox.'/destination/transfer-api/web/database.sqlite');
        expect($target->query('SELECT value FROM entries ORDER BY rowid')->fetchAll(PDO::FETCH_COLUMN))
            ->toBe(['original', 'committed after restore'])
            ->and(InstanceTransfer::query()->sole()->id)->toBe($failed->id);
    } finally {
        unset($database, $target);
        transfer_api_clean_sqlite_recovery_fixture($this->instance, $sandbox, $transport);
    }
})->with(['source', 'target']);

it('starts a new transfer with different input after a failed pre-cutover transfer finishes rollback', function (): void {
    transfer_api_bind_fakes();
    $source = app(InstanceTransferSource::class);
    $source->failMaterialize = true;
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->transferUrl, ['node_id' => $this->destinationNode->id])
        ->assertConflict()->assertJsonPath('error.code', 'instance.transfer_failed');
    $failed = InstanceTransfer::query()->sole();
    expect($failed->status)->toBe(InstanceTransferStatus::Failed)
        ->and($failed->cutover_at)->toBeNull()
        ->and($failed->recovery_evidence)->toBeNull();

    $source->failMaterialize = false;
    $this->postJson($this->transferUrl, ['node_id' => $this->destinationNode->id, 'name' => 'preview'])
        ->assertCreated()->assertJsonPath('data.name', 'preview')
        ->assertJsonPath('data.transfer.status', 'completed');
    expect(InstanceTransfer::query()->count())->toBe(2)
        ->and($failed->refresh()->status)->toBe(InstanceTransferStatus::Failed);
});

/** @return array{string, PDO, LocalInstanceTransferTransport} */
function transfer_api_sqlite_recovery_fixture(Instance $instance, Node $sourceNode, Node $destinationNode): array
{
    transfer_api_bind_fakes();
    $sandbox = sys_get_temp_dir().'/orbit-transfer-recovery-'.bin2hex(random_bytes(8));
    $sourcePath = $sandbox.'/source-'.basename($sandbox);
    $files = new Filesystem;
    foreach ([$sourcePath, $sandbox.'/snapshots', $sandbox.'/state'] as $directory) {
        $files->ensureDirectoryExists($directory, 0700);
    }
    new Process(['git', 'init', '--quiet', '--initial-branch=main'], $sourcePath)->mustRun();
    new Process(['git', '-c', 'user.name=Orbit', '-c', 'user.email=orbit@example.test', 'commit', '--quiet', '--allow-empty', '-m', 'Start'], $sourcePath)->mustRun();
    $database = new PDO('sqlite:'.$sourcePath.'/database.sqlite');
    $database->exec('PRAGMA journal_mode = WAL');
    $database->exec('CREATE TABLE entries (value TEXT NOT NULL)');
    $database->exec("INSERT INTO entries VALUES ('original')");
    $account = posix_getpwuid(posix_geteuid());
    $sourceNode->update(['user' => $account['name']]);
    $destinationNode->update(['user' => $account['name'], 'settings' => ['apps' => ['path' => $sandbox.'/destination']]]);
    $instance->update(['checkout_path' => $sourcePath]);
    $transport = new LocalInstanceTransferTransport($sandbox);
    app()->instance(InstanceTransferSource::class, $transport->source());
    app()->instance(InstanceSqliteSeeder::class, $transport->seeder());
    app()->forgetInstance(TransferInstanceAction::class);

    return [$sandbox, $database, $transport];
}

function transfer_api_clean_sqlite_recovery_fixture(Instance $instance, string $sandbox, LocalInstanceTransferTransport $transport): void
{
    foreach (array_unique($transport->incomingPaths) as $incoming) {
        if (file_exists($incoming)) {
            unlink($incoming);
        }
    }
    foreach (glob('/tmp/orbit-transfer-source-'.basename($sandbox).'-*.tar') ?: [] as $archive) {
        unlink($archive);
    }
    new Filesystem()->deleteDirectory($sandbox);
}

function transfer_api_node(string $name): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "{$name}.example.test",
        'wireguard_ip' => '10.44.10.'.(Node::query()->count() + 2),
        'user' => 'orbit',
    ]);
}

function transfer_api_app_dev(string $name, Cluster $cluster, string $address): Node
{
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => null,
        'cluster_id' => $cluster->id,
        'public_ssh_host' => "{$name}.example.test",
        'wireguard_ip' => $address,
        'user' => 'orbit',
        'settings' => ['apps' => ['path' => '/srv/orbit/apps']],
    ]);
    $node->roles()->create([
        'role' => RoleName::AppDev,
        'status' => LifecycleStatus::Active,
    ]);
    $router = transfer_api_node("{$name}-router");
    $router->update(['cluster_id' => $cluster->id]);
    $router->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
    ]);

    return $node;
}

function transfer_api_bind_fakes(): void
{
    app()->instance(ManagedUserAccountResolver::class, new Orb245Accounts);
    app()->instance(InstanceDestinationGuard::class, new Orb245DestinationGuard);
    app()->instance(InstanceEnvironmentOperationLock::class, new Orb245EnvironmentLock);
    app()->instance(AppDevSourceOperationLock::class, new Orb245SourceLock);
    app()->instance(InstanceTransferSource::class, new Orb245TransferSource);
    app()->instance(InstanceTransferRuntime::class, new Orb245TransferRuntime);
    app()->instance(InstanceSqliteSeeder::class, new Orb245SqliteSeeder);
    app()->instance(InstanceEnvironmentReader::class, new Orb245EnvironmentReader);
    app()->instance(InstanceEnvironmentWriter::class, new Orb245EnvironmentWriter);
    app()->instance(DevelopmentRouteProjector::class, new Orb245Projection);
    app()->instance(InstanceTransferRouteProjector::class, new Orb245Projection);
    app()->instance(TransferInstanceAction::class, app(TransferInstanceAction::class));
}
