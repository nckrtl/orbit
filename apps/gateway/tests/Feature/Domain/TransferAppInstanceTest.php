<?php

declare(strict_types=1);

use App\Actions\AppInstances\TransferAppInstanceAction;
use App\Data\AppInstances\TransferAppInstanceData;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\VitePortAllocator;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContextResolver;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentImporter;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentRenderer;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentStore;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentValidator;
use App\Domain\AppInstances\Transfer\AppInstanceTransferStatus;
use App\Domain\AppInstances\Transfer\AppInstanceTransferStep;
use App\Domain\AppInstances\Transfer\TransferDestinationAttempt;
use App\Domain\AppInstances\Transfer\TransferSourceAttempt;
use App\Domain\Clusters\ClusterState;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppInstances\RemoteAppInstanceEnvironmentAccess;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceTransfer;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Process;
use App\Models\Route;
use App\Models\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
use Tests\Support\Orb368RouterLock;

beforeEach(function (): void {
    $this->orbitApp = OrbitApp::query()->create([
        'name' => 'Transfer shop',
        'slug' => 'shop',
        'repository_url' => 'https://example.test/shop.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    [$this->sourceCluster, $this->sourceNode] = orb245_clustered_app_dev(
        'source',
        '10.44.45.10',
        'dev.orbit',
    );
    [$this->destinationCluster, $this->destinationNode] = orb245_clustered_app_dev(
        'destination',
        '10.44.45.11',
        'other.orbit',
    );
    $this->instance = orb245_instance($this->orbitApp, $this->sourceNode, 'web', 'checkout');
    $this->route = orb245_route($this->instance, 'web.shop.dev.orbit', RouteProvenance::Generated);
    $this->process = Process::query()->create([
        'owner_type' => AppInstance::class,
        'owner_id' => $this->instance->id,
        'name' => 'queue',
        'runtime' => 'systemd',
        'working_directory' => $this->instance->checkout_path,
        'runtime_config' => ['command' => ['php', 'artisan', 'queue:work']],
        'restart_policy' => 'always',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);
    $this->schedule = Schedule::query()->create([
        'target_type' => AppInstance::class,
        'target_id' => $this->instance->id,
        'host_node_id' => $this->sourceNode->id,
        'name' => 'nightly',
        'calendar' => '*-*-* 02:00:00',
        'command' => 'php artisan schedule:run',
        'timeout_seconds' => 60,
        'desired_timer_state' => DesiredTimerState::Enabled,
        'status' => LifecycleStatus::Active,
    ]);
    $this->instance->environmentValues()->create([
        'env_key' => 'APP_KEY',
        'env_value' => 'base64:stored-app-key',
    ]);
    $this->instance->environmentValues()->create([
        'env_key' => 'APP_URL',
        'env_value' => 'https://{{app_instance.domain}}/{{app_instance.environment}}',
    ]);
    $this->accounts = new Orb245Accounts;
    $this->destinationGuard = new Orb245DestinationGuard;
    $this->sources = new Orb245TransferSource;
    $this->runtime = new Orb245TransferRuntime;
    $this->sqlite = new Orb245SqliteSeeder;
    $this->reader = new Orb245EnvironmentReader;
    $this->writer = new Orb245EnvironmentWriter;
    $this->projection = new Orb245Projection;
    $this->projectionOwner = new class implements DevelopmentProjectionOperationLock
    {
        public ?Closure $beforeRun = null;

        public function run(Closure $operation): mixed
        {
            ($this->beforeRun ?? static fn () => null)();

            return $operation();
        }
    };
    $this->environmentLock = new Orb245EnvironmentLock;
    $this->routerLock = new Orb368RouterLock;
    $this->action = new TransferAppInstanceAction(
        $this->accounts,
        app(StorageRootResolver::class),
        app(NodeSettingsNormalizer::class),
        app(ManagedCheckoutOverlap::class),
        $this->destinationGuard,
        $this->environmentLock,
        new Orb245SourceLock,
        $this->sources,
        $this->runtime,
        $this->sqlite,
        new AppInstanceEnvironmentContextResolver,
        $this->reader,
        new AppInstanceEnvironmentImporter,
        new AppInstanceEnvironmentStore(
            new AppInstanceEnvironmentContextResolver,
            new AppInstanceEnvironmentValidator,
        ),
        new AppInstanceEnvironmentRenderer,
        $this->writer,
        new RouteStateResolver,
        $this->projection,
        $this->projection,
        $this->projectionOwner,
        $this->routerLock,
    );
    $this->data = new TransferAppInstanceData(
        nodeId: $this->destinationNode->id,
        name: null,
        sqliteSourcePath: null,
    );
});

it('records archive intent and exact prepared identities before capture and clears only confirmed cleanup', function (): void {
    $result = $this->action->execute($this->instance, $this->data);

    expect($this->sources->archiveCalls)->toBe(['prepare', 'cleanup'])
        ->and($this->sources->preparedArchiveEvidence)->toHaveCount(1)
        ->and($this->sources->preparedArchiveEvidence[0]['source']['receipt'])->toBeNull()
        ->and($this->sources->preparedArchiveEvidence[0]['destination']['receipt'])->toBeNull()
        ->and($this->sources->capturedArchiveEvidence)->toHaveCount(1)
        ->and($this->sources->capturedArchiveEvidence[0]['source']['receipt'])->toBe([
            'root' => '1:1', 'workspace' => '1:2', 'archive' => '1:3', 'bundle' => '1:4',
        ])
        ->and($this->sources->capturedArchiveEvidence[0]['destination']['receipt'])->toBe([
            'root' => '2:1', 'workspace' => '2:2', 'archive' => '2:3', 'bundle' => '2:4',
        ])
        ->and($result['transfer']->archive_attempt)->toBeNull()
        ->and($result['transfer']->toArray())->not->toHaveKey('archive_attempt');
});

it('records exact source ownership before capture and retains it through cleanup', function (): void {
    $result = $this->action->execute($this->instance, $this->data);

    expect($this->sources->preparedSourceEvidence)->toHaveCount(1)
        ->and($this->sources->preparedSourceEvidence[0]['phase'])->toBe('acquiring')
        ->and($this->sources->preparedSourceEvidence[0]['receipt'])->toBeNull()
        ->and($this->sources->capturedSourceEvidence)->toHaveCount(1)
        ->and($this->sources->capturedSourceEvidence[0]['phase'])->toBe('owned')
        ->and($this->sources->capturedSourceEvidence[0]['receipt']['checkout'])->toBe('4:4')
        ->and($this->sources->cleanedSourceEvidence)->toBe($this->sources->capturedSourceEvidence)
        ->and($result['transfer']->source_attempt)->toBeNull()
        ->and($result['transfer']->toArray())->not->toHaveKey('source_attempt');
});

it('does not perform remote work when source intent cannot be reserved', function (): void {
    DB::unprepared("CREATE TRIGGER fail_source_intent BEFORE INSERT ON app_instance_transfers WHEN NEW.source_attempt IS NOT NULL BEGIN SELECT RAISE(FAIL, 'source intent refused'); END");

    try {
        expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER fail_source_intent');
    }

    $this->assertDatabaseCount('app_instance_transfers', 0);
    expect($this->sources->archiveCalls)->toBeEmpty()
        ->and($this->sources->calls)->toBeEmpty()
        ->and($this->sources->preparedSourceEvidence)->toBeEmpty();
});

it('retains the same source attempt across failed pre-cutover database writes', function (string $predicate, string $phase, int $captured): void {
    DB::unprepared("CREATE TRIGGER fail_source_checkpoint BEFORE UPDATE ON app_instance_transfers WHEN {$predicate} BEGIN SELECT RAISE(FAIL, 'source checkpoint refused'); END");

    try {
        expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER fail_source_checkpoint');
    }
    $transfer = AppInstanceTransfer::query()->sole();
    $id = $transfer->source_attempt['id'];
    expect($transfer->source_attempt['phase'])->toBe($phase)
        ->and($transfer->cutover_at)->toBeNull()
        ->and($this->sources->capturedSourceEvidence)->toHaveCount($captured)
        ->and($this->sources->cleanedSourceEvidence)->toBeEmpty()
        ->and($this->instance->refresh()->node_id)->toBe($this->sourceNode->id)
        ->and($this->runtime->calls)->not->toContain('relocate');
    $result = $this->action->execute($this->instance, $this->data);
    expect($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed)
        ->and($result['transfer']->source_attempt)->toBeNull()
        ->and($this->sources->cleanedSourceEvidence[0]['id'])->toBe($id);
})->with([
    'observation intent' => ["json_extract(NEW.source_attempt, '$.phase') = 'acquiring'", 'reserved', 0],
    'owned receipt' => ["json_extract(NEW.source_attempt, '$.phase') = 'owned'", 'acquiring', 0],
    'capture checkpoint' => ["NEW.current_step = 'source-captured'", 'owned', 1],
]);

it('retries only forward cleanup when its completion database checkpoint fails', function (): void {
    DB::unprepared("CREATE TRIGGER fail_source_completion BEFORE UPDATE ON app_instance_transfers WHEN NEW.current_step = 'completed' BEGIN SELECT RAISE(FAIL, 'cleanup checkpoint refused'); END");
    try {
        expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER fail_source_completion');
    }
    $transfer = AppInstanceTransfer::query()->sole();
    $receipt = $transfer->source_attempt;
    expect($receipt['phase'])->toBe('owned')
        ->and($transfer->cutover_at)->not->toBeNull()
        ->and($this->instance->refresh()->node_id)->toBe($this->destinationNode->id)
        ->and($this->sources->capturedSourceEvidence)->toHaveCount(1)
        ->and($this->sources->cleanedSourceEvidence)->toBe([$receipt])
        ->and($this->runtime->calls)->not->toContain('restore');
    $result = $this->action->execute($this->instance->refresh(), $this->data);
    expect($result['transfer']->source_attempt)->toBeNull()
        ->and($this->sources->cleanedSourceEvidence)->toBe([$receipt, $receipt])
        ->and($this->sources->capturedSourceEvidence)->toHaveCount(1)
        ->and($this->runtime->calls)->not->toContain('restore');
});

it('retains unproven legacy or malformed source evidence without post-cutover adoption', function (string $fault): void {
    $this->sources->cleanupIncomplete = true;
    expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(ResourceOperationException::class);
    $transfer = AppInstanceTransfer::query()->sole();
    $evidence = $transfer->source_attempt;
    $evidence = match ($fault) {
        'legacy' => null,
        'scalar' => 'unknown-secret-sentinel',
        'wrong path' => [...$evidence, 'source_path' => '/foreign/source'],
        'wrong node' => [...$evidence, 'node_id' => $this->destinationNode->id],
        'unknown field' => [...$evidence, 'unknown' => true],
        'missing receipt' => [...$evidence, 'receipt' => null],
    };
    $stored = $evidence === null ? null : json_encode($evidence, JSON_THROW_ON_ERROR);
    DB::table('app_instance_transfers')->where('id', $transfer->id)->update(['source_attempt' => $stored]);
    $this->sources->cleanupIncomplete = false;

    expect(fn () => $this->action->execute($this->instance->refresh(), $this->data))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->getMessage())->not->toContain('unknown-secret-sentinel'));
    expect(DB::table('app_instance_transfers')->where('id', $transfer->id)->value('source_attempt'))->toBe($stored)
        ->and($this->sources->cleanedSourceEvidence)->toHaveCount(1)
        ->and($this->sources->capturedSourceEvidence)->toHaveCount(1)
        ->and($this->instance->refresh()->node_id)->toBe($this->destinationNode->id)
        ->and($this->runtime->calls)->not->toContain('restore');
})->with(['legacy', 'scalar', 'wrong path', 'wrong node', 'unknown field', 'missing receipt']);

it('records destination acquisition before remote creation and exact ownership before materialization', function (): void {
    $result = $this->action->execute($this->instance, $this->data);

    expect($this->sources->preparedDestinationEvidence)->toHaveCount(1)
        ->and($this->sources->preparedDestinationEvidence[0]['phase'])->toBe('acquiring')
        ->and($this->sources->preparedDestinationEvidence[0]['receipt'])->toBeNull()
        ->and($this->sources->materializedDestinationEvidence)->toHaveCount(1)
        ->and($this->sources->materializedDestinationEvidence[0]['phase'])->toBe('owned')
        ->and($this->sources->materializedDestinationEvidence[0]['receipt'])->toBe([
            'root' => '3:1', 'parent' => '3:2', 'scope' => '3:3', 'checkout' => '3:4',
        ])
        ->and($result['transfer']->destination_attempt)->toBeNull()
        ->and($result['transfer']->toArray())->not->toHaveKey('destination_attempt')
        ->and($this->sources->discardedDestinationAttempts)->toBeEmpty();
});

it('performs no remote transfer work when destination intent cannot be reserved', function (): void {
    DB::unprepared("CREATE TRIGGER fail_destination_intent BEFORE INSERT ON app_instance_transfers WHEN NEW.destination_attempt IS NOT NULL BEGIN SELECT RAISE(FAIL, 'destination reservation refused'); END");

    try {
        expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER fail_destination_intent');
    }

    $this->assertDatabaseCount('app_instance_transfers', 0);
    expect($this->sources->archiveCalls)->toBeEmpty()
        ->and($this->sources->calls)->toBeEmpty()
        ->and($this->sources->preparedDestinationEvidence)->toBeEmpty()
        ->and($this->runtime->calls)->toBeEmpty()
        ->and($this->instance->refresh()->node_id)->toBe($this->sourceNode->id);
});

it('retains exact destination cleanup authority across failed database checkpoints', function (
    string $predicate,
    string $discardPhase,
    int $prepared,
    int $materialized,
): void {
    DB::unprepared("CREATE TRIGGER fail_destination_checkpoint BEFORE UPDATE ON app_instance_transfers WHEN {$predicate} BEGIN SELECT RAISE(FAIL, 'destination checkpoint refused'); END");

    try {
        expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER fail_destination_checkpoint');
    }

    $transfer = AppInstanceTransfer::query()->sole();
    expect($this->sources->preparedDestinationEvidence)->toHaveCount($prepared)
        ->and($this->sources->materializedDestinationEvidence)->toHaveCount($materialized)
        ->and($this->sources->discardedDestinationAttempts)->toHaveCount(1)
        ->and($this->sources->discardedDestinationAttempts[0]->phase)->toBe($discardPhase)
        ->and($transfer->destination_attempt['phase'])->toBe('cleaned')
        ->and($transfer->cutover_at)->toBeNull()
        ->and($this->instance->refresh()->node_id)->toBe($this->sourceNode->id)
        ->and($this->runtime->calls)->not->toContain('pause')
        ->and($this->runtime->calls)->not->toContain('relocate')
        ->and($this->route->refresh()->status)->toBe(RouteStatus::Active);
    $result = $this->action->execute($this->instance, $this->data);
    expect($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed)
        ->and($result['transfer']->destination_attempt)->toBeNull();
})->with([
    'acquisition intent' => ["json_extract(NEW.destination_attempt, '$.phase') = 'acquiring'", 'reserved', 0, 0],
    'owned receipt' => ["json_extract(NEW.destination_attempt, '$.phase') = 'owned'", 'acquiring', 1, 0],
    'materialized checkpoint' => ["NEW.current_step = 'destination-checkout-created'", 'owned', 1, 1],
]);

it('blocks recapture and retains destination reservations until owned cleanup is confirmed', function (): void {
    $this->sources->failMaterialize = true;
    $this->sources->destinationCleanupIncomplete = true;
    expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(ResourceOperationException::class);
    $transfer = AppInstanceTransfer::query()->sole();
    $attempt = $transfer->destination_attempt;

    expect($attempt['phase'])->toBe('owned')
        ->and($transfer->recovery_evidence['incomplete'])->toContain('destination-checkout')
        ->and($this->sources->captures)->toHaveCount(1);
    expect(DB::table('vite_port_assignments')->where('app_instance_id', $this->instance->id)->count())->toBe(2);
    $this->sources->failMaterialize = false;
    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.transfer_destination_cleanup_incomplete'));
    expect($this->sources->captures)->toHaveCount(1)
        ->and($this->instance->refresh()->node_id)->toBe($this->sourceNode->id);

    $this->sources->destinationCleanupIncomplete = false;
    $result = $this->action->execute($this->instance, $this->data);
    expect($this->sources->captures)->toHaveCount(2)
        ->and($this->sources->preparedDestinationEvidence[1]['id'])->not->toBe($attempt['id'])
        ->and($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed)
        ->and($result['transfer']->destination_attempt)->toBeNull();
});

it('refuses legacy incomplete destination cleanup without adopting its current path', function (): void {
    $transfer = orb245_prepared_transfer($this->instance, $this->route, $this->destinationNode, AppInstanceTransferStep::Reserved);
    $transfer->update(['destination_attempt' => null]);

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.transfer_destination_cleanup_incomplete'));
    expect($this->sources->calls)->toBeEmpty()
        ->and($this->sources->discardedDestinationAttempts)->toBeEmpty()
        ->and($transfer->refresh()->destination_attempt)->toBeNull()
        ->and($transfer->recovery_evidence['incomplete'])->toContain('destination-checkout')
        ->and($transfer->cutover_at)->toBeNull()
        ->and($this->instance->refresh()->node_id)->toBe($this->sourceNode->id);
});

it('retains ownership when cleanup succeeds but its database acknowledgement is rejected', function (): void {
    $this->sources->failMaterialize = true;
    DB::unprepared("CREATE TRIGGER fail_destination_cleanup_checkpoint BEFORE UPDATE ON app_instance_transfers WHEN json_extract(NEW.destination_attempt, '$.phase') = 'cleaned' BEGIN SELECT RAISE(FAIL, 'destination cleanup checkpoint refused'); END");

    try {
        expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(ResourceOperationException::class);
    } finally {
        DB::unprepared('DROP TRIGGER fail_destination_cleanup_checkpoint');
    }

    $transfer = AppInstanceTransfer::query()->sole();
    $attemptId = $transfer->destination_attempt['id'];
    expect($transfer->destination_attempt['phase'])->toBe('owned')
        ->and($transfer->recovery_evidence['incomplete'])->toContain('destination-checkout')
        ->and($this->sources->discardedDestinationAttempts)->toHaveCount(1)
        ->and($this->sources->discardedDestinationAttempts[0]->id)->toBe($attemptId)
        ->and($transfer->cutover_at)->toBeNull();

    $this->sources->failMaterialize = false;
    $result = $this->action->execute($this->instance, $this->data);
    expect($this->sources->discardedDestinationAttempts[1]->id)->toBe($attemptId)
        ->and($this->sources->preparedDestinationEvidence[1]['id'])->not->toBe($attemptId)
        ->and($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed);
});

it('retains malformed destination ownership without another capture or remote discard', function (string $change): void {
    $this->sources->failMaterialize = true;
    $this->sources->destinationCleanupIncomplete = true;
    expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(ResourceOperationException::class);
    $transfer = AppInstanceTransfer::query()->sole();
    $evidence = $transfer->destination_attempt;
    $evidence = match ($change) {
        'scalar' => 'destination-secret-sentinel',
        'unknown field' => [...$evidence, 'destination-secret-sentinel' => true],
        'wrong node' => [...$evidence, 'node_id' => $this->sourceNode->id],
        'wrong path' => [...$evidence, 'destination_path' => '/srv/foreign'],
        'missing owned receipt' => [...$evidence, 'receipt' => null],
    };
    $stored = json_encode($evidence, JSON_THROW_ON_ERROR);
    DB::table('app_instance_transfers')->where('id', $transfer->id)->update(['destination_attempt' => $stored]);
    $before = count($this->sources->discardedDestinationAttempts);

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.transfer_destination_cleanup_incomplete')
                ->and($exception->getMessage())->not->toContain('destination-secret-sentinel');
        });
    expect(DB::table('app_instance_transfers')->where('id', $transfer->id)->value('destination_attempt'))->toBe($stored)
        ->and($this->sources->captures)->toHaveCount(1)
        ->and(count($this->sources->discardedDestinationAttempts))->toBe($before)
        ->and($this->instance->refresh()->node_id)->toBe($this->sourceNode->id);
})->with(['scalar', 'unknown field', 'wrong node', 'wrong path', 'missing owned receipt']);

it('cleans prepared archives across failed database checkpoints without advancing payload work', function (
    string $predicate,
    array $expectedArchiveCalls,
    array $expectedSourceCalls,
): void {
    DB::unprepared("CREATE TRIGGER fail_archive_checkpoint BEFORE UPDATE ON app_instance_transfers WHEN {$predicate} BEGIN SELECT RAISE(FAIL, 'archive checkpoint refused'); END");

    try {
        expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER fail_archive_checkpoint');
    }

    $transfer = AppInstanceTransfer::query()->sole();
    expect($this->sources->archiveCalls)->toBe($expectedArchiveCalls)
        ->and($this->sources->calls)->toBe($expectedSourceCalls)
        ->and($this->sources->materialized)->toBeEmpty()
        ->and($transfer->archive_attempt)->toBeNull()
        ->and($transfer->cutover_at)->toBeNull()
        ->and($this->instance->refresh()->node_id)->toBe($this->sourceNode->id)
        ->and($this->route->refresh()->status)->toBe(RouteStatus::Active);

    $result = $this->action->execute($this->instance, $this->data);
    expect($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed)
        ->and($result['transfer']->archive_attempt)->toBeNull();
})->with([
    'intent' => ['NEW.archive_attempt IS NOT NULL', [], []],
    'prepared identities' => ["json_extract(NEW.archive_attempt, '$.source.receipt') IS NOT NULL", ['prepare', 'cleanup'], []],
    'source checkpoint' => ["NEW.current_step = 'source-captured'", ['prepare', 'cleanup'], ['capture']],
]);

it('retains pending archive cleanup and blocks another attempt until the identical retry confirms it', function (): void {
    $this->sources->archiveCleanupPending = ['source'];

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.transfer_archive_cleanup_incomplete'));
    $transfer = AppInstanceTransfer::query()->sole();
    $attemptId = $transfer->archive_attempt['id'];
    expect($transfer->archive_attempt['cleanup_pending'])->toBe(['source'])
        ->and($transfer->archive_attempt['source']['receipt']['workspace'])->toBe('1:2')
        ->and($transfer->recovery_evidence['incomplete'])->toContain('transfer-archives')
        ->and($transfer->cutover_at)->toBeNull()
        ->and($this->instance->refresh()->node_id)->toBe($this->sourceNode->id)
        ->and($this->runtime->calls)->not->toContain('pause')
        ->and($this->runtime->calls)->not->toContain('relocate')
        ->and($this->runtime->calls)->not->toContain('activate');
    expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(ResourceOperationException::class);
    expect($this->sources->capturedArchiveAttempts)->toHaveCount(1);

    $this->sources->archiveCleanupPending = [];
    $result = $this->action->execute($this->instance, $this->data);
    expect($this->sources->capturedArchiveAttempts)->toHaveCount(2)
        ->and($this->sources->capturedArchiveAttempts[1]->id)->not->toBe($attemptId)
        ->and($result['transfer']->archive_attempt)->toBeNull()
        ->and($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed);
});

it('retains malformed archive evidence without starting another attempt or exposing its contents', function (string $change): void {
    $this->sources->archiveCleanupPending = ['source'];
    expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(ResourceOperationException::class);
    $transfer = AppInstanceTransfer::query()->sole();
    $evidence = $transfer->archive_attempt;
    $evidence = match ($change) {
        'json null' => null,
        'scalar' => 'archive-secret-sentinel',
        'unknown field' => [...$evidence, 'archive-secret-sentinel' => true],
        'wrong node' => [...$evidence, 'source' => [...$evidence['source'], 'node_id' => $this->destinationNode->id]],
        'wrong receipt' => [...$evidence, 'source' => [...$evidence['source'], 'receipt' => ['archive' => 'archive-secret-sentinel']]],
    };
    $stored = json_encode($evidence, JSON_THROW_ON_ERROR);
    DB::table('app_instance_transfers')->where('id', $transfer->id)->update(['archive_attempt' => $stored]);
    $before = $this->sources->archiveCalls;
    $this->sources->archiveCleanupPending = [];

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.transfer_archive_cleanup_incomplete')
                ->and($exception->getMessage())->not->toContain('archive-secret-sentinel');
        });
    expect(DB::table('app_instance_transfers')->where('id', $transfer->id)->value('archive_attempt'))->toBe($stored)
        ->and($this->sources->archiveCalls)->toBe($before)
        ->and($this->sources->capturedArchiveAttempts)->toHaveCount(1)
        ->and($this->instance->refresh()->node_id)->toBe($this->sourceNode->id);
})->with(['json null', 'scalar', 'unknown field', 'wrong node', 'wrong receipt']);

it('transfers a development AppInstance to another app-dev Node in the same Cluster', function (): void {
    $this->destinationNode->update([
        'cluster_id' => $this->sourceCluster->id,
        'tld' => null,
    ]);
    $result = $this->action->execute($this->instance, $this->data);
    $instance = $result['appInstance'];
    $transfer = $result['transfer'];
    $route = $instance->authoritativeRoute();

    expect($result['created'])->toBeTrue()
        ->and($instance->id)->toBe($this->instance->id)
        ->and($instance->app_id)->toBe($this->orbitApp->id)
        ->and($instance->node_id)->toBe($this->destinationNode->id)
        ->and($instance->name)->toBe('web')
        ->and($instance->checkout_path)->toBe('/srv/orbit/apps/shop/web')
        ->and($instance->source_layout)->toBe('checkout')
        ->and($route?->id)->toBe($this->route->id)
        ->and($route?->domain)->toBe('web.shop.dev.orbit')
        ->and($route?->cluster_id)->toBe($this->sourceCluster->id)
        ->and($transfer->status)->toBe(AppInstanceTransferStatus::Completed)
        ->and($transfer->current_step)->toBe(AppInstanceTransferStep::Completed)
        ->and($transfer->cleanup_completed ?? $transfer->completed_at)->not->toBeNull()
        ->and($this->sources->calls)->toBe(['capture', 'materialize', 'cleanup'])
        ->and($this->runtime->calls)->toBe(['pause', 'relocate', 'activate', 'cleanup'])
        ->and($this->sqlite->calls)->toBeEmpty()
        ->and($this->projection->httpChecks)->toBe(0)
        ->and($this->process->refresh()->id)->toBe($this->process->id)
        ->and($this->process->desired_state)->toBe(DesiredProcessState::Running)
        ->and($this->process->working_directory)->toBe('/srv/orbit/apps/shop/web')
        ->and($this->schedule->refresh()->id)->toBe($this->schedule->id)
        ->and($this->schedule->host_node_id)->toBe($this->destinationNode->id)
        ->and($this->schedule->desired_timer_state)->toBe(DesiredTimerState::Enabled)
        ->and($this->writer->path)->toBe('/srv/orbit/apps/shop/web')
        ->and($this->writer->domain)->toBe('web.shop.dev.orbit')
        ->and($this->writer->contents)
        ->toBe("APP_KEY=\"base64:stored-app-key\"\nAPP_URL=\"https://web.shop.dev.orbit/development\"\nNEW_FROM_ENV=\"imported\"\n");
});

it('transfers a development AppInstance across Clusters and replaces a generated domain', function (): void {
    $this->destinationCluster->update(['tld' => 'other.orbit']);
    $this->destinationNode->update(['tld' => null]);
    $result = $this->action->execute($this->instance, $this->data);
    $instance = $result['appInstance'];
    $route = $instance->authoritativeRoute();

    expect($instance->node_id)->toBe($this->destinationNode->id)
        ->and($route?->id)->not->toBe($this->route->id)
        ->and($route?->domain)->toBe('web.shop.other.orbit')
        ->and($route?->cluster_id)->toBe($this->destinationCluster->id)
        ->and($route?->provenance)->toBe(RouteProvenance::Generated)
        ->and(Route::query()->whereKey($this->route->id)->exists())->toBeFalse();
});

it('rolls back every Route preparation write when its next database boundary fails', function (string $trigger): void {
    $sourceRoute = $this->route->getAttributes();
    $sourceTargets = $this->route->targets()->get()->toArray();
    $this->travel(1)->seconds();
    $this->freezeSecond();
    DB::unprepared($trigger);

    try {
        expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER transfer_preparation_failure');
    }

    $transfer = AppInstanceTransfer::query()->sole();
    expect($this->route->refresh()->getAttributes())->toBe($sourceRoute);
    expect($this->route->targets()->get()->toArray())->toBe($sourceTargets);
    $this->assertDatabaseCount('routes', 1);
    $this->assertDatabaseCount('route_targets', 1);
    expect($transfer->destination_route_id)->toBeNull();
    expect($transfer->cutover_at)->toBeNull();
    expect($transfer->current_step)->toBe(AppInstanceTransferStep::Reserved);
    expect($this->instance->refresh()->node_id)->toBe($this->sourceNode->id);
    expect($this->runtime->calls)->toBe(['pause', 'restore']);
    expect($this->projection->calls)->toBe([]);

    $retry = $this->action->execute($this->instance, $this->data);

    expect($retry['created'])->toBeFalse();
    expect($retry['transfer']->id)->toBe($transfer->id);
    expect($retry['transfer']->status)->toBe(AppInstanceTransferStatus::Completed);
    $this->assertDatabaseCount('routes', 1);
    $this->assertDatabaseCount('route_targets', 1);
})->with([
    'candidate created' => <<<'SQL'
        CREATE TEMP TRIGGER transfer_preparation_failure BEFORE INSERT ON route_targets
        WHEN (SELECT replaces_route_id FROM routes WHERE id = NEW.route_id) IS NOT NULL
        BEGIN SELECT RAISE(ABORT, 'injected target failure'); END
        SQL,
    'target inserted' => <<<'SQL'
        CREATE TEMP TRIGGER transfer_preparation_failure BEFORE UPDATE ON routes
        WHEN NEW.replaced_by_route_id IS NOT NULL AND OLD.replaced_by_route_id IS NULL
        BEGIN SELECT RAISE(ABORT, 'injected pointer failure'); END
        SQL,
    'source pointer updated' => <<<'SQL'
        CREATE TEMP TRIGGER transfer_preparation_failure BEFORE UPDATE ON app_instance_transfers
        WHEN NEW.destination_route_id IS NOT NULL AND OLD.destination_route_id IS NULL
        BEGIN SELECT RAISE(ABORT, 'injected recovery identity failure'); END
        SQL,
    'recovery identity persisted' => <<<'SQL'
        CREATE TEMP TRIGGER transfer_preparation_failure AFTER UPDATE ON app_instance_transfers
        WHEN NEW.destination_route_id IS NOT NULL AND OLD.destination_route_id IS NULL
        BEGIN SELECT RAISE(FAIL, 'injected recovery checkpoint failure'); END
        SQL,
]);

it('records the Route recovery identity only together with its prepared checkpoint', function (bool $sameDomain): void {
    if ($sameDomain) {
        $this->destinationNode->update(['cluster_id' => $this->sourceCluster->id, 'tld' => null]);
    }
    DB::unprepared(<<<'SQL'
        CREATE TEMP TRIGGER transfer_preparation_failure BEFORE UPDATE ON app_instance_transfers
        WHEN NEW.destination_route_id IS NOT NULL AND OLD.destination_route_id IS NULL
            AND NEW.current_step <> 'route-prepared'
        BEGIN SELECT RAISE(ABORT, 'uncheckpointed recovery identity'); END
        SQL);

    try {
        $result = $this->action->execute($this->instance, $this->data);
    } finally {
        DB::unprepared('DROP TRIGGER transfer_preparation_failure');
    }

    expect($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed);
    expect($result['transfer']->destination_route_id)->toBe($result['appInstance']->routes->sole()->id);
})->with(['same domain' => true, 'changed domain' => false]);

it('rolls back same-domain Route evidence when the prepared checkpoint cannot persist', function (): void {
    $this->destinationNode->update(['cluster_id' => $this->sourceCluster->id, 'tld' => null]);
    $sourceRoute = $this->route->getAttributes();
    DB::unprepared(<<<'SQL'
        CREATE TEMP TRIGGER transfer_preparation_failure BEFORE UPDATE ON app_instance_transfers
        WHEN NEW.current_step = 'route-prepared'
        BEGIN SELECT RAISE(ABORT, 'injected checkpoint failure'); END
        SQL);

    try {
        expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER transfer_preparation_failure');
    }

    expect($this->route->refresh()->getAttributes())->toBe($sourceRoute);
    expect(AppInstanceTransfer::query()->sole()->destination_route_id)->toBeNull();
    $this->assertDatabaseCount('routes', 1);
    $this->assertDatabaseCount('route_targets', 1);
});

it('resumes recorded Route preparation with the same candidate identity after interruption', function (bool $sameDomain, AppInstanceTransferStep $step): void {
    if ($sameDomain) {
        $this->destinationNode->update(['cluster_id' => $this->sourceCluster->id, 'tld' => null]);
    }
    $transfer = orb245_prepared_transfer($this->instance, $this->route, $this->destinationNode, $step);
    $candidateId = $transfer->destination_route_id;

    $result = $this->action->execute($this->instance, $this->data);

    expect($result['created'])->toBeFalse();
    expect($result['transfer']->id)->toBe($transfer->id);
    expect($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed);
    expect($result['appInstance']->routes->sole()->id)->toBe($candidateId);
    expect($result['appInstance']->routes->sole()->domain)->toBe($transfer->destination_domain);
    expect($result['appInstance']->routes->sole()->provenance)->toBe(RouteProvenance::Generated);
    expect($result['appInstance']->routes->sole()->publication)->toBe(RoutePublication::Private);
    expect($result['appInstance']->routes->sole()->cluster_id)->toBe($this->destinationNode->cluster_id);
    expect($result['appInstance']->routes->sole()->targets->sole()->app_instance_id)->toBe($this->instance->id);
    expect($result['appInstance']->routes->sole()->targets->sole()->position)->toBe(0);
    expect($this->sources->calls)->toBe(['cleanup']);
    expect($this->writer->contents)->toBeNull();
    expect($this->runtime->calls)->toBe(['relocate', 'activate', 'cleanup']);
    $this->assertDatabaseCount('routes', 1);
    $this->assertDatabaseCount('route_targets', 1);
})->with(['same domain' => true, 'changed domain' => false])
    ->with([AppInstanceTransferStep::RuntimeRelocated, AppInstanceTransferStep::RoutePrepared]);

it('refuses changed recorded replacement evidence before transfer cutover', function (string $change): void {
    $transfer = orb245_prepared_transfer($this->instance, $this->route, $this->destinationNode, AppInstanceTransferStep::RuntimeRelocated);
    $candidate = Route::query()->findOrFail($transfer->destination_route_id);
    if ($change === 'domain') {
        $transfer->update(['destination_domain' => 'unexpected.shop.other.orbit']);
    } elseif ($change === 'placement') {
        $candidate->update(['generation_basis_node_id' => $this->sourceNode->id]);
    } elseif ($change === 'pointer') {
        $this->route->update(['replaced_by_route_id' => null]);
    } else {
        $candidate->targets()->update(['position' => 1]);
    }

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.lifecycle_conflict');
        });

    expect($transfer->refresh()->cutover_at)->toBeNull();
    expect($this->instance->refresh()->node_id)->toBe($this->sourceNode->id);
    expect($this->projection->calls)->toBe([]);
    expect($this->runtime->calls)->toBe(['restore']);
    expect($this->route->refresh()->status)->toBe(RouteStatus::Active);
})->with(['domain', 'placement', 'pointer', 'position']);

it('refuses changed same-domain recovery evidence before transfer cutover', function (): void {
    $this->destinationNode->update(['cluster_id' => $this->sourceCluster->id, 'tld' => null]);
    $transfer = orb245_prepared_transfer($this->instance, $this->route, $this->destinationNode, AppInstanceTransferStep::RuntimeRelocated);
    $candidate = Route::query()->create([
        'app_id' => $this->instance->app_id,
        'cluster_id' => $this->sourceCluster->id,
        'domain' => 'unexpected.dev.orbit',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
        'replaces_route_id' => $this->route->id,
        'replacement_step' => RouteReplacementStep::Reserved,
    ]);
    $candidate->targets()->create(['app_instance_id' => $this->instance->id, 'position' => 0]);
    $this->route->update(['replaced_by_route_id' => $candidate->id]);

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.lifecycle_conflict');
        });

    expect($transfer->refresh()->cutover_at)->toBeNull();
    expect($this->instance->refresh()->node_id)->toBe($this->sourceNode->id);
    expect($this->projection->calls)->toBe([]);
    $this->assertModelExists($candidate);
});

it('preserves changed Route owners and rollback evidence through identical retries', function (Closure $change, AppInstanceTransferStep $step): void {
    $transfer = orb245_prepared_transfer($this->instance, $this->route, $this->destinationNode, $step);
    $candidate = Route::query()->findOrFail($transfer->destination_route_id);
    $change($this, $transfer, $candidate);
    $routes = Route::query()->orderBy('id')->get()->toArray();
    $targets = DB::table('route_targets')->orderBy('id')->get()->toArray();
    $candidateId = $transfer->refresh()->destination_route_id;

    if ($step === AppInstanceTransferStep::RuntimeRelocated) {
        $this->environmentLock->beforeRun = static fn () => throw new ResourceOperationException('instance.transfer_failed', 'Interrupted before cutover.', 409);
    }
    expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(ResourceOperationException::class);
    $this->environmentLock->beforeRun = null;
    expect(fn () => $this->action->execute($this->instance->refresh(), $this->data))->toThrow(ResourceOperationException::class);

    expect(Route::query()->orderBy('id')->get()->toArray())->toBe($routes);
    expect(DB::table('route_targets')->orderBy('id')->get()->toArray())->toEqual($targets);
    expect($transfer->refresh()->destination_route_id)->toBe($candidateId);
    expect($transfer->current_step)->toBe($step);
    expect($transfer->status)->toBe(AppInstanceTransferStatus::Failed);
    expect($transfer->cutover_at)->toBeNull();
    expect($transfer->recovery_evidence['incomplete'])->toContain('destination-route');
    expect($this->sources->calls)->toBe([]);
    expect($this->projection->calls)->toBe([]);
    expect($this->runtime->calls)->not->toContain('relocate');
    expect($this->runtime->calls)->not->toContain('activate');
    expect($this->instance->refresh()->node_id)->toBe($this->sourceNode->id);
    expect($this->instance->name)->toBe('web');
    expect($this->instance->checkout_path)->toBe($transfer->source_path);
})->with([
    'foreign Project candidate identity' => [function (object $test, AppInstanceTransfer $transfer): void {
        $foreign = OrbitApp::query()->create(['name' => 'Foreign', 'slug' => 'foreign', 'repository_url' => 'https://example.test/foreign.git', 'default_branch' => 'main', 'root' => 'public']);
        $route = Route::query()->create(['app_id' => $foreign->id, 'cluster_id' => $test->destinationCluster->id, 'domain' => 'foreign.other.orbit', 'provenance' => RouteProvenance::Explicit, 'publication' => RoutePublication::Private]);
        $transfer->update(['destination_route_id' => $route->id]);
    }],
    'generation basis Node' => [function (object $test, AppInstanceTransfer $transfer, Route $candidate): void {
        $candidate->update(['generation_basis_node_id' => $test->sourceNode->id]);
    }],
    'placement Cluster' => [function (object $test, AppInstanceTransfer $transfer, Route $candidate): void {
        $candidate->update(['cluster_id' => $test->sourceCluster->id]);
    }],
    'target owner' => [function (object $test, AppInstanceTransfer $transfer, Route $candidate): void {
        $other = orb245_instance($test->orbitApp, $test->sourceNode, 'sibling', 'checkout');
        $candidate->targets()->update(['app_instance_id' => $other->id]);
    }],
    'target order' => [function (object $test, AppInstanceTransfer $transfer, Route $candidate): void {
        $candidate->targets()->update(['position' => 1]);
    }],
    'replacement progress' => [function (object $test, AppInstanceTransfer $transfer, Route $candidate): void {
        $candidate->update(['replacement_step' => RouteReplacementStep::WorkloadCertificate]);
    }],
    'candidate source link' => [function (object $test, AppInstanceTransfer $transfer, Route $candidate): void {
        $candidate->update(['replaces_route_id' => null]);
    }],
    'source candidate link' => [function (object $test): void {
        $test->route->update(['replaced_by_route_id' => null]);
    }],
    'source owns another candidate' => [function (object $test): void {
        $other = Route::query()->create(['app_id' => $test->orbitApp->id, 'cluster_id' => $test->sourceCluster->id, 'domain' => 'other.dev.orbit', 'provenance' => RouteProvenance::Explicit, 'publication' => RoutePublication::Private]);
        $test->route->update(['replaced_by_route_id' => $other->id]);
    }],
    'candidate became active' => [function (object $test, AppInstanceTransfer $transfer, Route $candidate): void {
        $candidate->update(['status' => RouteStatus::Active]);
    }],
    'candidate became failed' => [function (object $test, AppInstanceTransfer $transfer, Route $candidate): void {
        $candidate->update(['status' => RouteStatus::Failed, 'failed_step' => 'workload-certificate', 'error_code' => 'route.failed']);
    }],
    'candidate acquired target-set work' => [function (object $test, AppInstanceTransfer $transfer, Route $candidate): void {
        $candidate->update(['target_set_step' => 'reserved', 'target_set_intent' => ['pending' => true]]);
    }],
    'another replacement claims the candidate' => [function (object $test, AppInstanceTransfer $transfer, Route $candidate): void {
        Route::query()->create(['app_id' => $test->orbitApp->id, 'cluster_id' => $test->sourceCluster->id, 'domain' => 'successor.dev.orbit', 'provenance' => RouteProvenance::Explicit, 'publication' => RoutePublication::Private, 'replaces_route_id' => $candidate->id, 'replacement_step' => RouteReplacementStep::Reserved]);
    }],
])->with([AppInstanceTransferStep::RuntimeRelocated, AppInstanceTransferStep::RoutePrepared]);

it('preserves a pending transfer Route changed by the public Route update endpoint', function (AppInstanceTransferStep $step): void {
    $transfer = orb245_prepared_transfer($this->instance, $this->route, $this->destinationNode, $step);
    $this->markAsGateway($this->sourceNode);
    $this->withServerVariables(['REMOTE_ADDR' => $this->sourceNode->wireguard_ip]);
    $metrics = Mockery::mock(MetricsFleetReconciler::class);
    $metrics->shouldReceive('reconcile')->once();
    app()->instance(MetricsFleetReconciler::class, $metrics);

    $this->patchJson("/api/v1/routes/{$transfer->destination_route_id}", ['publication' => 'public'])
        ->assertOk()
        ->assertJsonPath('data.publication', 'public');
    $candidate = Route::query()->with('targets')->findOrFail($transfer->destination_route_id);
    $before = $candidate->toArray();
    expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(ResourceOperationException::class);

    expect($candidate->refresh()->load('targets')->toArray())->toBe($before);
    expect($transfer->refresh()->current_step)->toBe($step);
    expect($transfer->cutover_at)->toBeNull();
    expect($this->instance->refresh()->node_id)->toBe($this->sourceNode->id);
    expect($transfer->recovery_evidence['incomplete'])->toContain('destination-route');
    expect($this->route->refresh()->replaced_by_route_id)->toBe($candidate->id);
    expect($this->projection->calls)->toBe([]);
    expect($this->runtime->calls)->not->toContain('relocate');
    expect($this->runtime->calls)->not->toContain('activate');
})->with([AppInstanceTransferStep::RuntimeRelocated, AppInstanceTransferStep::RoutePrepared]);

it('refuses changed source evidence at a prepared same-domain or replacement cutover', function (string $change, bool $sameDomain): void {
    if ($sameDomain) {
        $this->destinationNode->update(['cluster_id' => $this->sourceCluster->id, 'tld' => null]);
    }
    $transfer = orb245_prepared_transfer($this->instance, $this->route, $this->destinationNode, AppInstanceTransferStep::RoutePrepared);
    if ($change === 'source generation basis') {
        $this->route->update(['generation_basis_node_id' => $this->destinationNode->id]);
    } elseif ($change === 'source target') {
        $other = orb245_instance($this->orbitApp, $this->sourceNode, 'sibling', 'checkout');
        $this->instance->update(['status' => AppInstanceState::Reserved]);
        $this->route->targets()->update(['app_instance_id' => $other->id]);
    } elseif ($change === 'source lifecycle') {
        $this->route->update(['status' => RouteStatus::Activating]);
    } else {
        $this->route->update(['target_set_step' => 'reserved', 'target_set_intent' => ['pending' => true]]);
    }
    $routes = Route::query()->orderBy('id')->get()->toArray();
    $targets = DB::table('route_targets')->orderBy('id')->get()->toArray();
    $placement = $this->instance->refresh()->only(['node_id', 'name', 'checkout_path', 'source_layout', 'vite_port', 'agentation_port']);

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.transfer_cleanup_conflict'));

    expect($this->instance->refresh()->only(array_keys($placement)))->toBe($placement);
    expect(Route::query()->orderBy('id')->get()->toArray())->toBe($routes);
    expect(DB::table('route_targets')->orderBy('id')->get()->toArray())->toEqual($targets);
    expect($transfer->refresh()->current_step)->toBe(AppInstanceTransferStep::RoutePrepared);
    expect($transfer->cutover_at)->toBeNull();
    expect($transfer->completed_at)->toBeNull();
    expect($this->runtime->calls)->not->toContain('relocate');
    expect($this->runtime->calls)->not->toContain('activate');
    expect($this->projection->calls)->toBe([]);
})->with(['source generation basis', 'source target', 'source lifecycle', 'source target-set work'])
    ->with(['same domain' => true, 'replacement' => false]);

it('rechecks transfer and Instance admission after acquiring the cutover owner', function (Closure $change): void {
    $transfer = orb245_prepared_transfer($this->instance, $this->route, $this->destinationNode, AppInstanceTransferStep::RoutePrepared);
    $this->projectionOwner->beforeRun = function () use ($change, $transfer): void {
        $this->projectionOwner->beforeRun = null;
        $change($this, $transfer);
    };

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.transfer_cleanup_conflict'));

    expect($this->instance->refresh()->node_id)->toBe($this->sourceNode->id);
    expect($this->instance->name)->toBe('web');
    expect($transfer->refresh()->cutover_at)->toBeNull();
    expect($transfer->completed_at)->toBeNull();
    expect($this->runtime->calls)->not->toContain('relocate');
    expect($this->runtime->calls)->not->toContain('activate');
    expect($this->projection->calls)->toBe([]);
})->with([
    'transfer owner' => [function (object $test, AppInstanceTransfer $transfer): void {
        $other = orb245_instance($test->orbitApp, $test->sourceNode, 'sibling', 'checkout');
        $transfer->update(['app_instance_id' => $other->id]);
    }],
    'source placement' => [function (object $test, AppInstanceTransfer $transfer): void {
        $transfer->update(['source_path' => '/srv/orbit/apps/shop/changed']);
    }],
    'source layout' => [function (object $test, AppInstanceTransfer $transfer): void {
        $transfer->update(['source_layout' => AppInstanceSourceLayout::Worktree]);
    }],
    'destination Node' => [function (object $test, AppInstanceTransfer $transfer): void {
        $transfer->update(['destination_node_id' => $test->sourceNode->id]);
    }],
    'destination path' => [function (object $test, AppInstanceTransfer $transfer): void {
        $transfer->update(['destination_path' => '/srv/orbit/apps/shop/changed']);
    }],
    'prepared checkpoint' => [function (object $test, AppInstanceTransfer $transfer): void {
        $transfer->update(['current_step' => AppInstanceTransferStep::SourcePaused]);
    }],
    'missing prepared identity' => [function (object $test, AppInstanceTransfer $transfer): void {
        $transfer->update(['destination_route_id' => null]);
    }],
    'Instance source path' => [function (object $test): void {
        $test->instance->update(['checkout_path' => '/srv/orbit/apps/shop/changed']);
    }],
    'Instance lifecycle' => [function (object $test): void {
        $test->instance->update(['status' => AppInstanceState::Reserved]);
    }],
    'Instance migration' => [function (object $test): void {
        $test->instance->update(['migration_required' => true]);
    }],
    'Instance checkpoint' => [function (object $test): void {
        $test->instance->update(['provisioning_step' => 'source-resolved']);
    }],
    'destination Cluster drift' => [function (object $test): void {
        $test->destinationNode->update(['cluster_id' => $test->sourceCluster->id, 'tld' => null]);
    }],
]);

it('refuses missing Route evidence at cutover without moving Instance authority', function (bool $sourceMissing): void {
    $transfer = orb245_prepared_transfer($this->instance, $this->route, $this->destinationNode, AppInstanceTransferStep::RoutePrepared);
    $candidate = Route::query()->findOrFail($transfer->destination_route_id);
    $this->projectionOwner->beforeRun = function () use ($candidate, $sourceMissing): void {
        $this->projectionOwner->beforeRun = null;
        if ($sourceMissing) {
            $this->instance->update(['status' => AppInstanceState::Reserved]);
            $this->route->delete();
        } else {
            $candidate->delete();
        }
    };

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.transfer_cleanup_conflict'));

    expect($this->instance->refresh()->node_id)->toBe($this->sourceNode->id);
    expect($transfer->refresh()->cutover_at)->toBeNull();
    expect($this->runtime->calls)->not->toContain('relocate');
    expect($this->runtime->calls)->not->toContain('activate');
    expect($this->projection->calls)->toBe([]);
    $this->assertModelExists($sourceMissing ? $candidate : $this->route);
})->with(['source' => true, 'candidate' => false]);

it('rolls back every cutover write when the database handoff fails', function (string $trigger): void {
    $transfer = orb245_prepared_transfer($this->instance, $this->route, $this->destinationNode, AppInstanceTransferStep::RoutePrepared);
    $candidateId = $transfer->destination_route_id;
    $routes = Route::query()->orderBy('id')->get()->toArray();
    $targets = DB::table('route_targets')->orderBy('id')->get()->toArray();
    $placement = $this->instance->refresh()->only(['node_id', 'name', 'checkout_path', 'source_layout', 'vite_port', 'agentation_port']);
    DB::unprepared($trigger);
    DB::unprepared("CREATE TEMP TRIGGER transfer_recovery_block BEFORE DELETE ON route_targets BEGIN SELECT RAISE(ABORT, 'retain candidate for recovery'); END");

    try {
        expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER transfer_cutover_failure');
        DB::unprepared('DROP TRIGGER transfer_recovery_block');
    }

    expect($this->instance->refresh()->only(array_keys($placement)))->toBe($placement);
    expect(Route::query()->orderBy('id')->get()->toArray())->toBe($routes);
    expect(DB::table('route_targets')->orderBy('id')->get()->toArray())->toEqual($targets);
    expect($transfer->refresh()->current_step)->toBe(AppInstanceTransferStep::RoutePrepared);
    expect($transfer->destination_route_id)->toBe($candidateId);
    expect($transfer->cutover_at)->toBeNull();
    expect($this->runtime->calls)->not->toContain('relocate');
    expect($this->runtime->calls)->not->toContain('activate');
    $result = $this->action->execute($this->instance->refresh(), $this->data);
    expect($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed);
    $calls = $this->sources->calls;
    $routeId = $result['appInstance']->authoritativeRoute()->id;
    $retry = $this->action->execute($this->instance->refresh(), $this->data);
    expect($retry['appInstance']->authoritativeRoute()->id)->toBe($routeId);
    expect($this->sources->calls)->toBe($calls);
})->with([
    'Instance movement' => <<<'SQL'
        CREATE TEMP TRIGGER transfer_cutover_failure AFTER UPDATE OF node_id ON app_instances
        WHEN OLD.node_id <> NEW.node_id
        BEGIN SELECT RAISE(FAIL, 'injected Instance cutover failure'); END
        SQL,
    'source retirement' => <<<'SQL'
        CREATE TEMP TRIGGER transfer_cutover_failure AFTER UPDATE OF status ON routes
        WHEN OLD.status = 'active' AND NEW.status = 'retiring'
        BEGIN SELECT RAISE(FAIL, 'injected source cutover failure'); END
        SQL,
    'candidate activation' => <<<'SQL'
        CREATE TEMP TRIGGER transfer_cutover_failure AFTER UPDATE OF status ON routes
        WHEN OLD.status = 'pending' AND NEW.status = 'active'
        BEGIN SELECT RAISE(FAIL, 'injected candidate cutover failure'); END
        SQL,
    'cutover journal' => <<<'SQL'
        CREATE TEMP TRIGGER transfer_cutover_failure AFTER UPDATE ON app_instance_transfers
        WHEN OLD.cutover_at IS NULL AND NEW.cutover_at IS NOT NULL
        BEGIN SELECT RAISE(FAIL, 'injected journal cutover failure'); END
        SQL,
]);

it('rolls back owned Route preparation atomically and retries from a fresh preparation', function (bool $sameDomain): void {
    if ($sameDomain) {
        $this->destinationNode->update(['cluster_id' => $this->sourceCluster->id, 'tld' => null]);
    }
    $transfer = orb245_prepared_transfer($this->instance, $this->route, $this->destinationNode, AppInstanceTransferStep::RoutePrepared);
    $this->environmentLock->beforeRun = static fn () => throw new ResourceOperationException('instance.transfer_failed', 'Interrupted before cutover.', 409);

    expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(ResourceOperationException::class);

    expect($transfer->refresh()->current_step)->toBe(AppInstanceTransferStep::Reserved);
    expect($transfer->destination_route_id)->toBeNull();
    expect($transfer->failed_step)->toBe(AppInstanceTransferStep::RoutePrepared);
    expect($transfer->recovery_evidence)->toBeNull();
    expect($this->route->refresh()->replaced_by_route_id)->toBeNull();
    $this->assertModelExists($this->route);
    $this->assertDatabaseCount('routes', 1);
    $this->assertDatabaseCount('route_targets', 1);
    $this->environmentLock->beforeRun = null;
    $result = $this->action->execute($this->instance->refresh(), $this->data);

    expect($result['transfer']->id)->toBe($transfer->id);
    expect($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed);
    expect($this->sources->calls)->toBe(['capture', 'materialize', 'cleanup']);
})->with(['same domain' => true, 'changed domain' => false]);

it('retains all Route rollback writes and journal identity when a database cleanup boundary fails', function (string $trigger): void {
    $transfer = orb245_prepared_transfer($this->instance, $this->route, $this->destinationNode, AppInstanceTransferStep::RoutePrepared);
    $candidateId = $transfer->destination_route_id;
    $routes = Route::query()->orderBy('id')->get()->toArray();
    $targets = DB::table('route_targets')->orderBy('id')->get()->toArray();
    $this->environmentLock->beforeRun = static fn () => throw new ResourceOperationException('instance.transfer_failed', 'Interrupted before cutover.', 409);
    DB::unprepared($trigger);

    try {
        expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(ResourceOperationException::class);
    } finally {
        DB::unprepared('DROP TRIGGER transfer_rollback_failure');
    }

    expect(Route::query()->orderBy('id')->get()->toArray())->toBe($routes);
    expect(DB::table('route_targets')->orderBy('id')->get()->toArray())->toEqual($targets);
    expect($transfer->refresh()->destination_route_id)->toBe($candidateId);
    expect($transfer->current_step)->toBe(AppInstanceTransferStep::RoutePrepared);
    expect($transfer->failed_step)->toBe(AppInstanceTransferStep::RoutePrepared);
    expect($transfer->recovery_evidence['incomplete'])->toContain('destination-route');
    expect($transfer->cutover_at)->toBeNull();
    $this->environmentLock->beforeRun = null;
    $result = $this->action->execute($this->instance->refresh(), $this->data);

    expect($result['transfer']->id)->toBe($transfer->id);
    expect($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed);
    expect($result['appInstance']->authoritativeRoute()->id)->not->toBe($candidateId);
    expect($this->sources->calls)->toBe(['capture', 'materialize', 'cleanup']);
})->with([
    'after target deletion' => <<<'SQL'
        CREATE TEMP TRIGGER transfer_rollback_failure AFTER DELETE ON route_targets
        BEGIN SELECT RAISE(FAIL, 'injected rollback target failure'); END
        SQL,
    'after candidate deletion' => <<<'SQL'
        CREATE TEMP TRIGGER transfer_rollback_failure AFTER DELETE ON routes
        BEGIN SELECT RAISE(FAIL, 'injected rollback Route failure'); END
        SQL,
    'after source pointer clearing' => <<<'SQL'
        CREATE TEMP TRIGGER transfer_rollback_failure AFTER UPDATE OF replaced_by_route_id ON routes
        WHEN OLD.replaced_by_route_id IS NOT NULL AND NEW.replaced_by_route_id IS NULL
        BEGIN SELECT RAISE(FAIL, 'injected rollback link failure'); END
        SQL,
    'after journal reset' => <<<'SQL'
        CREATE TEMP TRIGGER transfer_rollback_failure AFTER UPDATE ON app_instance_transfers
        WHEN OLD.destination_route_id IS NOT NULL AND NEW.destination_route_id IS NULL
        BEGIN SELECT RAISE(FAIL, 'injected rollback journal failure'); END
        SQL,
]);

it('accepts an already absent candidate only with consistent remaining Route ownership', function (): void {
    $transfer = orb245_prepared_transfer($this->instance, $this->route, $this->destinationNode, AppInstanceTransferStep::RuntimeRelocated);
    Route::query()->findOrFail($transfer->destination_route_id)->delete();

    expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(ResourceOperationException::class);

    expect($transfer->refresh()->current_step)->toBe(AppInstanceTransferStep::Reserved);
    expect($transfer->destination_route_id)->toBeNull();
    expect($transfer->recovery_evidence)->toBeNull();
    expect($this->route->refresh()->replaced_by_route_id)->toBeNull();
    $result = $this->action->execute($this->instance->refresh(), $this->data);
    expect($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed);
});

it('retains missing candidate evidence when another Route claims its domain', function (): void {
    $transfer = orb245_prepared_transfer($this->instance, $this->route, $this->destinationNode, AppInstanceTransferStep::RuntimeRelocated);
    $missingId = $transfer->destination_route_id;
    Route::query()->findOrFail($missingId)->delete();
    $other = Route::query()->create(['app_id' => $this->orbitApp->id, 'cluster_id' => $this->destinationCluster->id, 'domain' => $transfer->destination_domain, 'provenance' => RouteProvenance::Explicit, 'publication' => RoutePublication::Private]);

    expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(ResourceOperationException::class);

    $this->assertModelExists($other);
    expect($transfer->refresh()->destination_route_id)->toBe($missingId);
    expect($transfer->current_step)->toBe(AppInstanceTransferStep::RuntimeRelocated);
    expect($transfer->recovery_evidence['incomplete'])->toContain('destination-route');
    expect($this->route->refresh()->replaced_by_route_id)->toBeNull();
});

it('finishes unresolved Route rollback before recopying after its owner is repaired', function (AppInstanceTransferStep $step): void {
    $transfer = orb245_prepared_transfer($this->instance, $this->route, $this->destinationNode, $step);
    $candidate = Route::query()->findOrFail($transfer->destination_route_id);
    $candidate->targets()->update(['position' => 1]);

    expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(ResourceOperationException::class);
    expect($transfer->refresh()->current_step)->toBe($step);
    expect($this->sources->calls)->toBe([]);
    $candidate->targets()->update(['position' => 0]);
    $result = $this->action->execute($this->instance->refresh(), $this->data);

    expect($result['transfer']->id)->toBe($transfer->id);
    expect($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed);
    expect($result['transfer']->recovery_evidence)->toBeNull();
    expect($result['appInstance']->authoritativeRoute()->id)->not->toBe($candidate->id);
    expect($this->sources->calls)->toBe(['capture', 'materialize', 'cleanup']);
    $routeId = $result['appInstance']->authoritativeRoute()->id;
    $retry = $this->action->execute($this->instance->refresh(), $this->data);
    expect($retry['appInstance']->authoritativeRoute()->id)->toBe($routeId);
    expect($this->sources->calls)->toBe(['capture', 'materialize', 'cleanup']);
})->with([AppInstanceTransferStep::RuntimeRelocated, AppInstanceTransferStep::RoutePrepared]);

it('preserves candidate evidence when the source Route disappears before rollback', function (): void {
    $transfer = orb245_prepared_transfer($this->instance, $this->route, $this->destinationNode, AppInstanceTransferStep::RuntimeRelocated);
    $candidate = Route::query()->findOrFail($transfer->destination_route_id);
    $this->environmentLock->beforeRun = function (): void {
        $this->instance->update(['status' => AppInstanceState::Reserved]);
        $this->route->delete();
        throw new ResourceOperationException('instance.transfer_failed', 'Interrupted before cutover.', 409);
    };

    expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(ResourceOperationException::class);

    $this->assertModelExists($candidate);
    expect($candidate->refresh()->targets()->count())->toBe(1);
    expect($transfer->refresh()->destination_route_id)->toBe($candidate->id);
    expect($transfer->current_step)->toBe(AppInstanceTransferStep::RuntimeRelocated);
    expect($transfer->recovery_evidence['incomplete'])->toContain('destination-route');
});

it('keeps an explicit Route identity while moving its scope', function (): void {
    $this->instance->update([
        'status' => AppInstanceState::Reserved,
        'provisioning_step' => 'reserved',
    ]);
    $this->route->targets()->delete();
    $this->route->delete();
    $this->instance->refresh();
    $this->route = orb245_route($this->instance, 'shop.example.test', RouteProvenance::Explicit);
    $this->instance->update([
        'status' => AppInstanceState::Active,
        'provisioning_step' => 'active',
    ]);
    $this->destinationNode->update(['tld' => null]);

    $result = $this->action->execute($this->instance, $this->data);
    $route = $result['appInstance']->authoritativeRoute();

    expect($route?->id)->toBe($this->route->id)
        ->and($route?->domain)->toBe('shop.example.test')
        ->and($route?->cluster_id)->toBe($this->destinationCluster->id);
});

it('finalizes a generated Route replacement so environment access and immediate reverse transfer succeed', function (): void {
    $forward = $this->action->execute($this->instance, $this->data);
    $route = $forward['appInstance']->authoritativeRoute();

    expect($route->replacement_step)->toBeNull()
        ->and($route->replaces_route_id)->toBeNull()
        ->and($route->replaced_by_route_id)->toBeNull();
    $context = new AppInstanceEnvironmentContextResolver()->resolve($forward['appInstance'], true);
    expect($context->nodeId)->toBe($this->destinationNode->id)
        ->and($context->routeDomain)->toBe('web.shop.other.orbit');

    $reverse = $this->action->execute($forward['appInstance'], new TransferAppInstanceData(
        nodeId: $this->sourceNode->id,
        name: null,
        sqliteSourcePath: null,
    ));

    expect($reverse['appInstance']->id)->toBe($this->instance->id)
        ->and($reverse['appInstance']->node_id)->toBe($this->sourceNode->id)
        ->and($reverse['transfer']->status)->toBe(AppInstanceTransferStatus::Completed)
        ->and($reverse['appInstance']->routes)->toHaveCount(1);
    expect(new AppInstanceEnvironmentContextResolver()->resolve($reverse['appInstance'], true)->routeDomain)
        ->toBe('web.shop.dev.orbit');
});

it('owns the source Cluster Router before waiting for the environment lock shared with Cluster updates', function (): void {
    $this->environmentLock->beforeRun = function (): void {
        expect($this->routerLock->ownedClusterId)->toBe($this->sourceCluster->id);
    };

    $result = $this->action->execute($this->instance, $this->data);

    expect($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed)
        ->and($this->routerLock->ownedClusterId)->toBeNull();
});

it('returns a completed legacy transfer without requiring original Router evidence', function (): void {
    $completed = $this->action->execute($this->instance, $this->data);
    $completed['transfer']->update(['source_router_node_id' => null]);
    $calls = $this->sources->calls;

    $result = $this->action->execute($completed['appInstance'], $this->data);

    expect($result['created'])->toBeFalse()
        ->and($result['transfer']->id)->toBe($completed['transfer']->id)
        ->and($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed)
        ->and($this->sources->calls)->toBe($calls);
});

it('retains the source Route and Vite reservation until projection retirement can be retried', function (): void {
    $this->projection->failRetirementOnce = true;
    expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(ResourceOperationException::class);
    $transfer = AppInstanceTransfer::query()->sole();
    $destination = Route::query()->findOrFail($transfer->destination_route_id);

    expect($this->route->refresh()->status)->toBe(RouteStatus::Retiring)
        ->and($destination->replacement_step)->toBe(RouteReplacementStep::DatabaseCutover)
        ->and($transfer->source_router_node_id)->toBe($this->sourceCluster->routerAssignment->node_id)
        ->and($transfer->completed_at)->toBeNull()
        ->and($this->sources->calls)->toBe(['capture', 'materialize'])
        ->and($this->runtime->calls)->toBe(['pause', 'relocate', 'activate']);
    expect(DB::table('vite_port_assignments')->where('app_instance_id', $this->instance->id)->count())->toBe(2);

    $result = $this->action->execute($this->instance->refresh(), $this->data);

    expect($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed)
        ->and($this->projection->calls)->toBe(['converge', 'retire', 'retire'])
        ->and($this->sources->calls)->toBe(['capture', 'materialize', 'cleanup'])
        ->and($this->runtime->calls)->not->toContain('restore');
    $this->assertModelMissing($this->route);
});

it('refuses cleanup before remote deletion when the replacement ownership changed', function (): void {
    $this->projection->failRetirementOnce = true;
    expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(ResourceOperationException::class);
    $transfer = AppInstanceTransfer::query()->sole();
    $destination = Route::query()->findOrFail($transfer->destination_route_id);
    $destination->update(['replaces_route_id' => null]);

    expect(fn () => $this->action->execute($this->instance->refresh(), $this->data))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.transfer_cleanup_conflict'));

    expect($this->projection->calls)->toBe(['converge', 'retire'])
        ->and($this->sources->calls)->toBe(['capture', 'materialize'])
        ->and($this->runtime->calls)->not->toContain('cleanup')
        ->and($this->runtime->calls)->not->toContain('restore')
        ->and($transfer->refresh()->completed_at)->toBeNull();
    $this->assertModelExists($this->route);
});

it('retains legacy post-cutover state when the original Router identity is unknown', function (): void {
    $this->projection->failRetirementOnce = true;
    expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(ResourceOperationException::class);
    $transfer = AppInstanceTransfer::query()->sole();
    $transfer->update(['source_router_node_id' => null]);

    expect(fn () => $this->action->execute($this->instance->refresh(), $this->data))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.transfer_source_router_unknown'));

    expect($this->projection->calls)->toBe(['converge', 'retire'])
        ->and($this->sources->calls)->toBe(['capture', 'materialize'])
        ->and($transfer->refresh()->completed_at)->toBeNull();
    $this->assertModelExists($this->route);
});

it('refuses ineligible sources and destinations before source mutation', function (
    Closure $mutate,
    string $code,
): void {
    $mutate($this);
    $instance = $this->instance->refresh();

    expect(fn () => $this->action->execute($instance, $this->data))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe($code));

    expect($this->sources->calls)->toBeEmpty()
        ->and($instance->refresh()->node_id)->toBe($this->sourceNode->id)
        ->and($instance->name)->toBe('web');
})->with([
    'production' => [function (object $test): void {
        $test->instance->update(['environment' => 'production']);
    }, 'instance.production_refused'],
    'inactive instance' => [function (object $test): void {
        $test->instance->update(['status' => AppInstanceState::Reserved]);
    }, 'instance.lifecycle_conflict'],
    'migration required' => [function (object $test): void {
        $test->instance->update(['migration_required' => true]);
    }, 'instance.migration_required'],
    'same Node' => [function (object $test): void {
        $test->data = new TransferAppInstanceData($test->sourceNode->id, null, null);
    }, 'instance.same_node'],
    'inactive destination' => [function (object $test): void {
        $test->destinationNode->update(['status' => LifecycleStatus::Failed]);
    }, 'instance.node_inactive'],
    'destination without app-dev' => [function (object $test): void {
        $test->destinationNode->roles()->where('role', RoleName::AppDev)->delete();
    }, 'instance.node_not_app_dev'],
    'standalone destination' => [function (object $test): void {
        $test->destinationNode->update(['cluster_id' => null]);
    }, 'instance.standalone_unsupported'],
]);

it('refuses an occupied destination path with a rename hint before source mutation', function (): void {
    AppInstance::query()->create([
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->destinationNode->id,
        'name' => 'other',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/apps/shop/web',
        'branch' => 'main',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
    ]);

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.destination_exists')
                ->and($exception->getMessage())->toContain('destination already exists')
                ->and($exception->getMessage())->toContain('name');
        });

    expect($this->sources->calls)->toBeEmpty()
        ->and($this->instance->refresh()->name)->toBe('web');
});

it('recalculates destination path and generated domain for an explicit rename', function (): void {
    $data = new TransferAppInstanceData($this->destinationNode->id, 'preview', null);
    $result = $this->action->execute($this->instance, $data);
    $instance = $result['appInstance'];
    $route = $instance->authoritativeRoute();

    expect($instance->name)->toBe('preview')
        ->and($instance->checkout_path)->toBe('/srv/orbit/apps/shop/preview')
        ->and($route?->domain)->toBe('preview.shop.other.orbit');
});

it('rejects a colliding rename identity and leaves the original name unchanged', function (): void {
    AppInstance::query()->create([
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->destinationNode->id,
        'name' => 'preview',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/apps/shop/preview',
        'branch' => 'main',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
    ]);

    expect(fn () => $this->action->execute(
        $this->instance,
        new TransferAppInstanceData($this->destinationNode->id, 'preview', null),
    ))->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.identity_conflict'));

    expect($this->instance->refresh()->name)->toBe('web')
        ->and($this->sources->calls)->toBeEmpty();
});

it('captures source as an independent destination checkout without mutating source Git', function (): void {
    $this->action->execute($this->instance, $this->data);

    expect($this->sources->captures[0]->detached)->toBeTrue()
        ->and($this->sources->captures[0]->head)->toBe(str_repeat('a', 40))
        ->and($this->sources->mutatedSource)->toBeFalse()
        ->and($this->sources->materialized[0]->layout)->toBe(AppInstanceSourceLayout::Checkout)
        ->and($this->sources->materialized[0]->path)->toBe('/srv/orbit/apps/shop/web');
});

it('converts a source worktree into an independent destination checkout and preserves the common repository', function (): void {
    $this->instance->update([
        'source_layout' => AppInstanceSourceLayout::Worktree,
        'registration_common_repository_path' => '/home/orbit/.orbit/worktrees/shop.git',
    ]);
    $this->sources->layout = AppInstanceSourceLayout::Worktree;
    $this->sources->common = '/home/orbit/.orbit/worktrees/shop.git';

    $result = $this->action->execute($this->instance->refresh(), $this->data);

    expect($result['appInstance']->source_layout)->toBe('checkout')
        ->and($this->sources->cleanupCommon)->toBe('/home/orbit/.orbit/worktrees/shop.git')
        ->and($this->sources->deletedCommon)->toBeFalse()
        ->and($this->sources->materialized[0]->layout)->toBe(AppInstanceSourceLayout::Checkout);
});

it('transfers only the selected SQLite snapshot after the source pause', function (): void {
    $data = new TransferAppInstanceData(
        $this->destinationNode->id,
        null,
        '/srv/orbit/apps/shop/web/database/database.sqlite',
    );
    $this->action->execute($this->instance, $data);

    expect($this->sqlite->calls)->toHaveCount(1)
        ->and($this->sqlite->sourcePath)->toBe('/srv/orbit/apps/shop/web/database/database.sqlite')
        ->and($this->sqlite->sourceBase)->toBe($this->instance->checkout_path)
        ->and($this->sqlite->destinationBase)->toBe('/srv/orbit/apps/shop/web')
        ->and($this->runtime->calls[0])->toBe('pause')
        ->and(array_search('pause', $this->runtime->calls, true))
        ->toBeLessThan(array_search('relocate', $this->runtime->calls, true));
});

it('imports source .env without overwriting stored keys and rebuilds destination values', function (): void {
    $this->reader->contents = "APP_KEY=from-file\nNEW_FROM_ENV=imported\n";
    $this->action->execute($this->instance, $this->data);

    $stored = $this->instance->environmentValues()->pluck('env_value', 'env_key')->all();

    expect($stored['APP_KEY'])->toBe('base64:stored-app-key')
        ->and($stored['NEW_FROM_ENV'])->toBe('imported')
        ->and($this->writer->contents)->not->toContain('from-file')
        ->and(json_encode($this->writer->observed))->not->toContain('from-file');
});

it('preserves source authority and stored configuration when environment observation or parsing fails', function (
    CommandResult|Throwable $observation,
    string $errorCode,
): void {
    $ssh = new TransferEnvironmentObservationSsh($observation);
    $this->reader->delegate = transfer_environment_access($ssh);
    $storedBefore = DB::table('app_instance_environment_values')
        ->where('app_instance_id', $this->instance->id)->orderBy('env_key')->pluck('env_value', 'env_key')->all();
    $sourcePath = $this->instance->checkout_path;

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(function (ResourceOperationException $exception) use ($errorCode): void {
            expect($exception->errorCode)->toBe($errorCode)
                ->and($exception->getMessage())->not->toContain('environment-secret-sentinel')
                ->and($exception->getPrevious())->toBeNull()
                ->and($exception->details)->toBeEmpty();
        });

    $transfer = AppInstanceTransfer::query()->where('app_instance_id', $this->instance->id)->sole();
    expect($transfer->status)->toBe(AppInstanceTransferStatus::Failed)
        ->and($transfer->failed_step)->toBe(AppInstanceTransferStep::SqliteTransferred)
        ->and($transfer->error_code)->toBe($errorCode)
        ->and($transfer->cutover_at)->toBeNull()
        ->and($transfer->completed_at)->toBeNull()
        ->and($transfer->destination_route_id)->toBeNull()
        ->and(json_encode($transfer->toArray(), JSON_THROW_ON_ERROR))->not->toContain('environment-secret-sentinel');
    expect($this->instance->refresh()->node_id)->toBe($this->sourceNode->id)
        ->and($this->instance->checkout_path)->toBe($sourcePath)
        ->and($this->instance->authoritativeRoute()?->id)->toBe($this->route->id)
        ->and($this->route->refresh()->status)->toBe(RouteStatus::Active)
        ->and($this->route->replaced_by_route_id)->toBeNull()
        ->and($this->process->refresh()->working_directory)->toBe($sourcePath)
        ->and($this->process->desired_state)->toBe(DesiredProcessState::Running)
        ->and($this->schedule->refresh()->host_node_id)->toBe($this->sourceNode->id)
        ->and($this->schedule->desired_timer_state)->toBe(DesiredTimerState::Enabled);
    expect(DB::table('app_instance_environment_values')
        ->where('app_instance_id', $this->instance->id)->orderBy('env_key')->pluck('env_value', 'env_key')->all())
        ->toBe($storedBefore);
    expect($this->writer->contents)->toBeNull()
        ->and($this->projection->calls)->toBeEmpty()
        ->and($this->runtime->calls)->toBe(['pause', 'restore'])
        ->and($this->sources->calls)->toBe(['capture', 'materialize'])
        ->and($this->sources->discarded)->toBe(['/srv/orbit/apps/shop/web'])
        ->and($ssh->commands)->toHaveCount(1)
        ->and($ssh->commands[0]->arguments)->toContain('read', $sourcePath)
        ->and($ssh->commands[0]->arguments)->not->toContain('environment-secret-sentinel');

    $ssh->observation = new CommandResult(
        0,
        base64_encode("APP_KEY=from-file\nSOURCE_ONLY=environment-secret-sentinel\n"),
        '',
        1,
        false,
    );
    $result = $this->action->execute($this->instance->refresh(), $this->data);
    $imported = $this->instance->environmentValues()->where('env_key', 'SOURCE_ONLY')->sole();

    expect($result['created'])->toBeFalse()
        ->and($result['transfer']->id)->toBe($transfer->id)
        ->and($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed)
        ->and($result['appInstance']->node_id)->toBe($this->destinationNode->id)
        ->and($imported->env_value)->toBe('environment-secret-sentinel')
        ->and($imported->getRawOriginal('env_value'))->not->toContain('environment-secret-sentinel')
        ->and($this->instance->environmentValues()->where('env_key', 'APP_KEY')->sole()->env_value)->toBe('base64:stored-app-key')
        ->and($this->writer->contents)->toContain('SOURCE_ONLY="environment-secret-sentinel"')
        ->and(json_encode($this->writer->observed, JSON_THROW_ON_ERROR))->not->toContain('environment-secret-sentinel')
        ->and($this->sources->calls)->toBe(['capture', 'materialize', 'capture', 'materialize', 'cleanup']);
})->with([
    'reader refusal' => [new CommandResult(42, "REFUSED\n", '', 1, false), 'env.import_preflight_failed'],
    'transport failure' => [new RuntimeException('environment-secret-sentinel'), 'env.import_preflight_failed'],
    'nonzero result' => [new CommandResult(1, 'environment-secret-sentinel', 'environment-secret-sentinel', 1, false), 'env.import_preflight_failed'],
    'truncated result' => [new CommandResult(0, base64_encode('SOURCE_ONLY=environment-secret-sentinel'), '', 1, true), 'env.import_preflight_failed'],
    'malformed result' => [new CommandResult(0, '%environment-secret-sentinel%', '', 1, false), 'env.import_preflight_failed'],
    'unexpected diagnostics' => [new CommandResult(0, base64_encode('SOURCE_ONLY=environment-secret-sentinel'), 'environment-secret-sentinel', 1, false), 'env.import_preflight_failed'],
    'malformed dotenv' => [new CommandResult(0, base64_encode("SOURCE_ONLY environment-secret-sentinel\n"), '', 1, false), 'env.import_invalid'],
    'unterminated dotenv' => [new CommandResult(0, base64_encode("BEFORE=valid\nSOURCE_ONLY=\"environment-secret-sentinel\n"), '', 1, false), 'env.import_invalid'],
]);

it('accepts a positively observed empty environment file without losing stored keys', function (): void {
    $ssh = new TransferEnvironmentObservationSsh(new CommandResult(0, '', '', 1, false));
    $this->reader->delegate = transfer_environment_access($ssh);

    $result = $this->action->execute($this->instance, $this->data);

    expect($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed)
        ->and($this->instance->environmentValues()->orderBy('env_key')->pluck('env_value', 'env_key')->all())->toBe([
            'APP_KEY' => 'base64:stored-app-key',
            'APP_URL' => 'https://{{app_instance.domain}}/{{app_instance.environment}}',
        ])
        ->and($this->writer->contents)->toBe("APP_KEY=\"base64:stored-app-key\"\nAPP_URL=\"https://web.shop.other.orbit/development\"\n")
        ->and($ssh->commands)->toHaveCount(1);
});

it('restores the source and discards destination state when transfer fails before cutover', function (): void {
    $this->sources->failMaterialize = true;

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(ResourceOperationException::class);

    $transfer = AppInstanceTransfer::query()->where('app_instance_id', $this->instance->id)->sole();

    expect($this->instance->refresh()->node_id)->toBe($this->sourceNode->id)
        ->and($this->instance->name)->toBe('web')
        ->and($this->runtime->calls)->toBe(['restore'])
        ->and($this->sources->discarded)->toBe(['/srv/orbit/apps/shop/web'])
        ->and($transfer->cutover_at)->toBeNull()
        ->and($transfer->current_step)->toBe(AppInstanceTransferStep::Reserved)
        ->and($transfer->status)->toBe(AppInstanceTransferStatus::Failed);

    expect(fn () => $this->action->execute(
        $this->instance->refresh(),
        new TransferAppInstanceData($this->destinationNode->id, 'other', null),
    ))->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.transfer_retry_conflict'));

    expect(DB::table('vite_port_assignments')->where('app_instance_id', $this->instance->id)->pluck('node_id')->all())->toBe([$this->sourceNode->id]);
    $this->sources->failMaterialize = false;
    $result = $this->action->execute($this->instance->refresh(), $this->data);

    expect($result['created'])->toBeFalse()
        ->and($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed)
        ->and($result['appInstance']->node_id)->toBe($this->destinationNode->id);
});

it('continues only forward after cutover and does not recopy source', function (): void {
    $this->projection->failOnce = true;

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(ResourceOperationException::class);

    $transfer = AppInstanceTransfer::query()->where('app_instance_id', $this->instance->id)->sole();

    expect($this->instance->refresh()->node_id)->toBe($this->destinationNode->id)
        ->and($transfer->cutover_at)->not->toBeNull()
        ->and($this->runtime->calls)->not->toContain('restore')
        ->and($this->sources->calls)->toBe(['capture', 'materialize']);

    $result = $this->action->execute($this->instance->refresh(), $this->data);

    expect($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed)
        ->and($this->sources->calls)->toBe(['capture', 'materialize', 'cleanup'])
        ->and($this->runtime->calls)->not->toContain('restore');
});

it('reports incomplete old-placement cleanup and retries only cleanup', function (): void {
    $this->sources->cleanupIncomplete = true;

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.transfer_cleanup_incomplete'));

    $transfer = AppInstanceTransfer::query()->where('app_instance_id', $this->instance->id)->sole();

    expect($this->instance->refresh()->node_id)->toBe($this->destinationNode->id)
        ->and($transfer->recovery_evidence)->toHaveKey('incomplete')
        ->and($transfer->destination_attempt['phase'])->toBe('owned')
        ->and($this->sources->discardedDestinationAttempts)->toBeEmpty()
        ->and($this->route->refresh()->status)->toBe(RouteStatus::Retiring)
        ->and(Route::query()->findOrFail($transfer->destination_route_id)->replacement_step)->toBe(RouteReplacementStep::DatabaseCutover)
        ->and($this->sources->calls)->toBe(['capture', 'materialize', 'cleanup']);

    expect(DB::table('vite_port_assignments')->where('app_instance_id', $this->instance->id)->count())->toBe(2);
    $this->sources->cleanupIncomplete = false;
    $result = $this->action->execute($this->instance->refresh(), $this->data);

    expect($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed)
        ->and($result['transfer']->destination_attempt)->toBeNull()
        ->and($this->sources->discardedDestinationAttempts)->toBeEmpty()
        ->and($this->sources->calls)->toBe(['capture', 'materialize', 'cleanup', 'cleanup']);
    expect(DB::table('vite_port_assignments')->where('app_instance_id', $this->instance->id)->pluck('node_id')->all())->toBe([$this->destinationNode->id]);
});

it('completes transfer from verified placement state without application HTTP health', function (): void {
    $this->action->execute($this->instance, $this->data);

    expect($this->projection->httpChecks)->toBe(0)
        ->and($this->projection->calls)->toBe(['converge', 'retire']);
});

/**
 * @return array{Cluster, Node}
 */
function orb245_clustered_app_dev(string $role, string $address, string $tld): array
{
    $cluster = Cluster::query()->create([
        'name' => "transfer-{$role}",
        'tld' => $tld,
        'state' => ClusterState::Active,
    ]);
    $node = Node::query()->create([
        'name' => "transfer-{$role}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => $tld,
        'cluster_id' => $cluster->id,
        'public_ssh_host' => "{$role}.example.test",
        'wireguard_ip' => $address,
        'user' => 'orbit',
        'settings' => ['apps' => ['path' => '/srv/orbit/apps']],
    ]);
    $node->roles()->create([
        'role' => RoleName::AppDev,
        'status' => LifecycleStatus::Active,
    ]);
    $router = Node::query()->create([
        'name' => "transfer-{$role}-router",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'cluster_id' => $cluster->id,
        'public_ssh_host' => "{$role}-router.example.test",
        'wireguard_ip' => preg_replace('/\.\d+$/', '.2'.substr($address, -1), $address) ?: $address,
        'user' => 'orbit',
    ]);
    $router->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
    ]);

    return [$cluster, $node];
}

function orb245_prepared_transfer(AppInstance $instance, Route $source, Node $destination, AppInstanceTransferStep $step): AppInstanceTransfer
{
    app(VitePortAllocator::class)->assign($instance);
    app(VitePortAllocator::class)->assign($instance, $destination);
    $domain = $source->cluster_id === $destination->cluster_id ? $source->domain : 'web.shop.other.orbit';
    $candidate = $source;
    if ($domain !== $source->domain) {
        $candidate = Route::query()->create([
            'app_id' => $instance->app_id,
            'cluster_id' => $destination->cluster_id,
            'generation_basis_node_id' => $destination->id,
            'domain' => $domain,
            'provenance' => RouteProvenance::Generated,
            'publication' => $source->publication,
            'status' => RouteStatus::Pending,
            'replaces_route_id' => $source->id,
            'replacement_step' => RouteReplacementStep::Reserved,
        ]);
        $candidate->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
        $source->update(['replaced_by_route_id' => $candidate->id]);
    }

    $transfer = AppInstanceTransfer::query()->create([
        'app_instance_id' => $instance->id,
        'source_node_id' => $instance->node_id,
        'source_router_node_id' => $instance->node->cluster->routerAssignment->node_id,
        'destination_node_id' => $destination->id,
        'requested_name' => null,
        'destination_name' => $instance->name,
        'destination_path' => '/srv/orbit/apps/shop/web',
        'destination_domain' => $domain,
        'source_layout' => $instance->source_layout,
        'source_path' => $instance->checkout_path,
        'source_route_id' => $source->id,
        'destination_route_id' => $candidate->id,
        'status' => AppInstanceTransferStatus::InProgress,
        'current_step' => $step,
    ]);
    $attempt = TransferDestinationAttempt::create($transfer, new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
    if (! in_array($step, [AppInstanceTransferStep::Reserved, AppInstanceTransferStep::SourceCaptured], true)) {
        $attempt = $attempt->acquiring()->withReceipt(['root' => '3:1', 'parent' => '3:2', 'scope' => '3:3', 'checkout' => '3:4']);
    }
    $transfer->update(['destination_attempt' => $attempt->toArray()]);
    $sourceAttempt = TransferSourceAttempt::create($transfer, new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
    if ($step !== AppInstanceTransferStep::Reserved) {
        $sourceAttempt = $sourceAttempt->acquiring()->withReceipt([
            'root' => '4:1', 'parent' => '4:2', 'scope' => '4:3', 'checkout' => '4:4',
            'common_path' => $transfer->source_layout->value === 'worktree' ? '/home/orbit/.orbit/worktrees/shop.git' : null,
            'common' => $transfer->source_layout->value === 'worktree' ? '4:5' : null,
        ]);
    }
    $transfer->update(['source_attempt' => $sourceAttempt->toArray()]);

    return $transfer;
}

function orb245_instance(OrbitApp $app, Node $node, string $name, string $layout): AppInstance
{
    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $name,
        'environment' => 'development',
        'source_layout' => $layout,
        'checkout_path' => "/srv/orbit/apps/{$app->slug}/{$name}",
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
    ]);
}

function orb245_route(AppInstance $instance, string $domain, RouteProvenance $provenance): Route
{
    $route = Route::query()->create([
        'app_id' => $instance->app_id,
        'cluster_id' => $instance->node->cluster_id,
        'generation_basis_node_id' => $provenance === RouteProvenance::Generated ? $instance->node_id : null,
        'domain' => $domain,
        'provenance' => $provenance,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create([
        'app_instance_id' => $instance->id,
        'position' => 0,
    ]);
    $route->update(['status' => RouteStatus::Active]);

    return $route->refresh();
}

function transfer_environment_access(SshExecutor $ssh): RemoteAppInstanceEnvironmentAccess
{
    return new RemoteAppInstanceEnvironmentAccess(
        $ssh,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/tmp/transfer-key';
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
                return '/tmp/transfer-known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    );
}

final class TransferEnvironmentObservationSsh implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    public function __construct(public CommandResult|Throwable $observation) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;
        if ($this->observation instanceof Throwable) {
            throw $this->observation;
        }

        return $this->observation;
    }
}
