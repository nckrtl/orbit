<?php

declare(strict_types=1);

use App\E2E\AcquisitionRollback;
use App\E2E\Git\GitRepository;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\PreparedStateFingerprint;
use App\E2E\StandbyManifestStore;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationJournal;
use App\E2E\State\OperationLock;
use App\E2E\State\SecretRedactor;
use App\E2E\State\StatePaths;
use App\E2E\TopologyAcquirer;
use App\E2E\TopologyConverger;
use App\E2E\TopologyManifestStore;
use App\E2E\TopologyVerifier;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\IncusNetwork;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofResult;
use App\E2E\Value\SourceState;
use App\E2E\Value\StandbyGeneration;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationReport;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Process;

uses(Tests\TestCase::class);

it('successful verify persists the returned verification report', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    featureTopologyFixture($repositoryRoot, $paths);
    $events = [];
    fakePinnedWorktreeProcesses(featureTarget('NCK-123'), $events);

    $verified = taskNineAcquirer($repositoryRoot, $paths)->verify('NCK-123', attemptId());
    $persisted = new TopologyManifestStore(new AtomicJsonStore($paths), $paths)->active('NCK-123');

    expect($verified->verification->probes)
        ->not->toBe(['fixture' => true])->and($persisted?->verification->toArray())->toBe(
            $verified->verification->toArray(),
        )->and($events)
        ->not->toBeEmpty();
});

it('journals successful execution with redacted argv and output without persisting stdin', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    featureTopologyFixture($repositoryRoot, $paths);
    $topology = new TopologyManifestStore(new AtomicJsonStore($paths), $paths)->active('NCK-123');
    expect($topology)->not->toBeNull();
    $target = featureTarget('NCK-123');
    $instance = $target->instance('gateway');
    $redactor = new SecretRedactor(['configured-secret']);
    $journal = new OperationJournal($paths, $redactor);
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use ($instance, $target, $topology) {
        if (($process->command[3] ?? null) === 'list') {
            return Process::result(topologyVmJson(
                $instance,
                [
                    'user.orbit.e2e.owner' => 'orbit-e2e',
                    'user.orbit.e2e.issue' => 'NCK-123',
                    'user.orbit.e2e.attempt' => attemptId()->value,
                    'user.orbit.e2e.generation' => $topology->generation->id,
                ],
                $target->network(),
            ));
        }

        return Process::result('visible configured-secret', 'Bearer configured-secret', 0);
    });

    $result = taskNineAcquirer(
        $repositoryRoot,
        $paths,
        journal: $journal,
        redactor: $redactor,
    )->execute(
        'NCK-123',
        attemptId(),
        'gateway',
        ['tool', '--token', 'token-value', '--password=password-value', 'https://user:pass@example.test'],
        'stdin-secret',
    );
    $entries = $journal->entries(new OperationId(str_repeat('a', 32)));
    $journalText = (string) file_get_contents($paths->path('journals/'.str_repeat('a', 32).'.jsonl'));

    expect($result->stdout)
        ->toContain('configured-secret')
        ->and($entries)
        ->toHaveCount(2)
        ->and($entries[0])
        ->toMatchArray([
            'event' => 'topology.exec',
            'state' => 'started',
            'issue' => 'NCK-123',
            'role' => 'gateway',
            'target' => $instance,
            'argv' => [
                'tool',
                '--token',
                '[REDACTED]',
                '--password=[REDACTED]',
                'https://[REDACTED]@example.test',
            ],
        ])
        ->and($entries[1])
        ->toMatchArray([
            'event' => 'topology.exec',
            'state' => 'completed',
            'target' => $instance,
            'exit_code' => 0,
            'stdout' => "visible [REDACTED]\n",
            'stderr' => "Bearer [REDACTED]\n",
        ])
        ->and($journalText)
        ->not->toContain('stdin-secret')
        ->not->toContain('token-value')
        ->not->toContain('password-value')
        ->not->toContain('configured-secret')
        ->not->toContain('user:pass');
});

it('nonzero execute writes a completed topology.exec entry', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    featureTopologyFixture($repositoryRoot, $paths);
    $topology = new TopologyManifestStore(new AtomicJsonStore($paths), $paths)->active('NCK-123');
    expect($topology)->not->toBeNull();
    $target = featureTarget('NCK-123');
    $instance = $target->instance('gateway');
    $redactor = new SecretRedactor;
    $journal = new OperationJournal($paths, $redactor);
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use ($instance, $target, $topology) {
        if (($process->command[3] ?? null) === 'list') {
            return Process::result(topologyVmJson(
                $instance,
                [
                    'user.orbit.e2e.owner' => 'orbit-e2e',
                    'user.orbit.e2e.issue' => 'NCK-123',
                    'user.orbit.e2e.attempt' => attemptId()->value,
                    'user.orbit.e2e.generation' => $topology->generation->id,
                ],
                $target->network(),
            ));
        }

        return Process::result('failed output', 'failed error', 17);
    });

    $result = taskNineAcquirer(
        $repositoryRoot,
        $paths,
        journal: $journal,
        redactor: $redactor,
    )->execute('NCK-123', attemptId(), 'gateway', ['tool']);
    $entries = $journal->entries(new OperationId(str_repeat('a', 32)));

    expect($result->exitCode)
        ->toBe(17)
        ->and($entries)
        ->toHaveCount(2)
        ->and($entries[1])
        ->toMatchArray([
            'event' => 'topology.exec',
            'state' => 'completed',
            'target' => $instance,
            'exit_code' => 17,
            'stdout' => "failed output\n",
            'stderr' => "failed error\n",
        ]);
});

it('rejects a replaced VM before guest execution', function (string $mismatch) {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    featureTopologyFixture($repositoryRoot, $paths);
    $topology = new TopologyManifestStore(new AtomicJsonStore($paths), $paths)->active('NCK-123');
    expect($topology)->not->toBeNull();
    $target = featureTarget('NCK-123');
    $instance = $target->instance('gateway');
    $metadata = [
        'user.orbit.e2e.owner' => 'orbit-e2e',
        'user.orbit.e2e.issue' => 'NCK-123',
        'user.orbit.e2e.attempt' => attemptId()->value,
        'user.orbit.e2e.generation' => $topology->generation->id,
    ];
    $network = $target->network();
    match ($mismatch) {
        'owner' => $metadata['user.orbit.e2e.owner'] = 'foreign',
        'issue' => $metadata['user.orbit.e2e.issue'] = 'NCK-999',
        'generation' => $metadata['user.orbit.e2e.generation'] = 'foreign-generation',
        'network' => $network = 'oe-foreign',
    };
    $executed = false;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$executed,
        $instance,
        $metadata,
        $network,
    ) {
        if (($process->command[3] ?? null) === 'list') {
            return Process::result(topologyVmJson($instance, $metadata, $network));
        }
        if (($process->command[3] ?? null) === 'exec') {
            $executed = true;
        }

        return Process::result();
    });

    expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->execute(
        'NCK-123',
        attemptId(),
        'gateway',
        ['tool'],
    ))
        ->toThrow(RuntimeException::class, 'identity does not match')
        ->and($executed)
        ->toBeFalse();
})->with(['owner', 'issue', 'generation', 'network']);

/** One promoted generation for the fixture repository, so discovery acquisition can start. */
function promoteDiscoveryGeneration(string $repositoryRoot, StatePaths $paths): void
{
    $store = new AtomicJsonStore($paths);
    $prepared = topologyFinalPreparedFingerprint($repositoryRoot);
    $mainSha = new GitRepository($repositoryRoot)->commit();
    $structural = new PreparedStateFingerprint(new GitRepository($repositoryRoot))->forCommit($mainSha);
    new StandbyManifestStore($store, $paths, new TopologyManifestStore($store, $paths))->promote(new StandbyGeneration(
        substr($mainSha, 0, 12).'-'.substr($prepared->value, 0, 12),
        $mainSha,
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $prepared->value,
        str_repeat('b', 64),
        topologyPromotedLaravel(),
        $structural->value,
        $structural->manifest['schema'],
        $structural->manifest['cold_epoch'],
        $structural->manifest['base_image_alias'],
        $structural->manifest['topology']['profile'],
        $structural->manifest['topology']['roles'],
        $structural->manifest['topology']['checkout_roles'],
    ));
}

it('refuses discovery before any Incus mutation when a vendor autoload is missing', function (string $project) {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $worktree = pinnedFeatureWorktree($repositoryRoot, 'vendor');
    promoteDiscoveryGeneration($repositoryRoot, $paths);
    unlink($worktree.'/'.$project.'/vendor/autoload.php');
    $events = [];
    fakePinnedWorktreeProcesses(featureTarget('NCK-123'), $events);

    try {
        expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->acquireDiscovery(
            new TopologyRequest('NCK-123', $worktree),
        ))
            ->toThrow(RuntimeException::class, "missing {$project}/vendor/autoload.php; run bin/bootstrap")
            ->and(collect($events)->contains(
                static fn (array $command): bool => (
                    array_intersect(
                        $command,
                        ['create', 'copy', 'start', 'exec'],
                    ) !== []
                ),
            ))
            ->toBeFalse()
            ->and(new AtomicJsonStore($paths)->read('leases/NCK-123.json'))
            ->toBeNull();
    } finally {
        Process::run(['git', '-C', $repositoryRoot, 'worktree', 'remove', '--force', $worktree]);
    }
})->with(['apps/gateway', 'apps/cli', 'packages/php-sdk']);

it('rolls back discovery when the worktree is not mounted or the gateway environment is missing', function (
    string $failure,
    string $message,
) {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $worktree = pinnedFeatureWorktree($repositoryRoot, 'mount-'.$failure);
    promoteDiscoveryGeneration($repositoryRoot, $paths);
    $target = featureTarget('NCK-123');
    $events = [];
    fakeDiscoveryMountFailureProcesses($target, $failure, $events);
    $mutations = [];
    $rollback = discoveryRollback($target, $mutations);

    try {
        expect(fn () => taskNineAcquirer($repositoryRoot, $paths, $rollback)->acquireDiscovery(
            new TopologyRequest('NCK-123', $worktree),
        ))
            ->toThrow(RuntimeException::class, $message)
            ->and(collect($events)->contains(
                static fn (array $command): bool => str_contains(implode(' ', $command), 'retarget-vpn.sh'),
            ))
            ->toBeFalse()
            ->and($mutations)
            ->toContain('delete:'.$target->instance('gateway'), 'network:'.$target->network())
            ->and(new AtomicJsonStore($paths)->read('leases/NCK-123.json'))
            ->toBeNull()
            ->and(new AtomicJsonStore($paths)->read('failures/NCK-123.json')['phase'] ?? null)
            ->toBe('mount.source')
            ->and(new TopologyManifestStore(new AtomicJsonStore($paths), $paths)->active('NCK-123'))
            ->toBeNull();
    } finally {
        Process::run(['git', '-C', $repositoryRoot, 'worktree', 'remove', '--force', $worktree]);
    }
})->with([
    'mountpoint' => ['mountpoint', 'The worktree is not mounted on mountpoint.gateway, mountpoint.app-dev.'],
    'environment' => ['environment', 'promoted standby generation must be refreshed'],
]);

it('refuses a worktree path that cannot become an Incus mount source before any Incus mutation', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    // Incus receives the source inside `key=value` device configuration.
    $worktree = pinnedFeatureWorktree($repositoryRoot, 'unmount=able');
    promoteDiscoveryGeneration($repositoryRoot, $paths);
    $events = [];
    fakePinnedWorktreeProcesses(featureTarget('NCK-123'), $events);

    try {
        expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->acquireDiscovery(
            new TopologyRequest('NCK-123', $worktree),
        ))
            ->toThrow(RuntimeException::class, 'The worktree cannot be mounted')
            ->and(collect($events)->contains(
                static fn (array $command): bool => (
                    array_intersect($command, ['create', 'copy', 'start', 'exec']) !== []
                ),
            ))
            ->toBeFalse()
            ->and(new AtomicJsonStore($paths)->read('leases/NCK-123.json'))
            ->toBeNull();
    } finally {
        Process::run(['git', '-C', $repositoryRoot, 'worktree', 'remove', '--force', $worktree]);
    }
});

it('refuses discovery while a legacy flat pending release record exists', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $worktree = pinnedFeatureWorktree($repositoryRoot, 'legacy-pending');
    promoteDiscoveryGeneration($repositoryRoot, $paths);
    new AtomicJsonStore($paths)->write('release-pending/NCK-123.json', ['schema' => 2, 'issue' => 'NCK-123']);
    $events = [];
    fakePinnedWorktreeProcesses(featureTarget('NCK-123'), $events);

    try {
        expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->acquireDiscovery(
            new TopologyRequest('NCK-123', $worktree),
        ))
            ->toThrow(RuntimeException::class, 'legacy pending record; release with the previous harness')
            ->and(collect($events)->contains(
                static fn (array $command): bool => (
                    array_intersect($command, ['create', 'copy', 'start', 'exec']) !== []
                ),
            ))
            ->toBeFalse()
            ->and(new AtomicJsonStore($paths)->read('leases/NCK-123.json'))
            ->toBeNull();
    } finally {
        Process::run(['git', '-C', $repositoryRoot, 'worktree', 'remove', '--force', $worktree]);
    }
});

/**
 * Discovery guest preparation fails at one exact point; every Incus read then
 * answers with the acquiring identity so rollback revalidates exact ownership.
 *
 * @param list<array<array-key, mixed>> $events
 */
function fakeDiscoveryMountFailureProcesses(TopologyTarget $target, string $failure, array &$events): void
{
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$events,
        $realProcess,
        $target,
        $failure,
    ) {
        $command = $process->command;
        if (($command[0] ?? null) === 'git') {
            return $realProcess
                ->path((string) ($process->path ?: getcwd()))
                ->input($process->input)
                ->run($command);
        }
        $batch = pinnedWorktreeBatchResult(
            $process,
            $events,
            static fn (array $guest): ?\Illuminate\Contracts\Process\ProcessResult => $failure === 'mountpoint'
                && ($guest[0] ?? null) === 'mountpoint'
                    ? Process::result('', '', 32)
                    : null,
        );
        if ($batch !== null) {
            return $batch;
        }
        $events[] = $command;
        if ($failure === 'environment' && in_array('/var/lib/orbit-e2e/gateway.env', $command, true)) {
            return Process::result('', 'install: cannot stat', 1);
        }

        return discoveryAcquiringInventoryResult($command, $target) ?? pinnedWorktreeGuestResult($command);
    });
}

/**
 * The pinned inventory with the acquiring issue, attempt, and operation stamped on
 * every resource of the attempt.
 *
 * @param list<string> $command
 */
function discoveryAcquiringInventoryResult(
    array $command,
    TopologyTarget $target,
): ?\Illuminate\Contracts\Process\ProcessResult {
    $inventory = pinnedWorktreeInventoryResult($command, $target);
    if ($inventory === null) {
        return null;
    }
    $identity = [
        'user.orbit.e2e.issue' => 'NCK-123',
        'user.orbit.e2e.attempt' => attemptId()->value,
        'user.orbit.e2e.operation' => str_repeat('a', 32),
    ];
    if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'list') {
        return Process::result(json_encode([[
            'name' => $target->network(),
            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e', 'ipv4.address' => '10.232.2.1/24', ...$identity],
        ]], JSON_THROW_ON_ERROR));
    }
    if (($command[3] ?? null) !== 'list') {
        return $inventory;
    }
    $resources = json_decode($inventory->output(), true, 16, JSON_THROW_ON_ERROR);
    foreach ($resources as &$resource) {
        if (str_starts_with((string) ($resource['name'] ?? ''), 'orbit-e2e-nck-123-')) {
            $resource['config'] += $identity;
        }
    }
    unset($resource);

    return Process::result(json_encode($resources, JSON_THROW_ON_ERROR));
}

/** @param list<string> $mutations */
function discoveryRollback(TopologyTarget $target, array &$mutations): AcquisitionRollback
{
    $identity = [
        'user.orbit.e2e.owner' => 'orbit-e2e',
        'user.orbit.e2e.issue' => 'NCK-123',
        'user.orbit.e2e.attempt' => attemptId()->value,
        'user.orbit.e2e.operation' => str_repeat('a', 32),
    ];

    return serialAcquisitionRollback(
        static fn (string $resource): IncusInstance|IncusNetwork => $resource === $target->network()
            ? new IncusNetwork('local', 'default', $resource, $identity)
            : new IncusInstance(
                'local',
                'default',
                $resource,
                'default',
                $identity,
                network: $target->network(),
                mac: $target->mac(substr($resource, strlen($target->instance('gateway')) - strlen('gateway'))),
            ),
        static function (string $resource) use (&$mutations): void {
            $mutations[] = 'stop:'.$resource;
        },
        static function (string $resource) use (&$mutations): void {
            $mutations[] = 'delete:'.$resource;
        },
        static function (string $resource) use (&$mutations): void {
            $mutations[] = 'network:'.$resource;
        },
    );
}

it('refuses to touch a proved attempt or one the lease and active pointer do not name', function (string $case) {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    featureTopologyFixture($repositoryRoot, $paths);
    $store = new AtomicJsonStore($paths);
    $worktree = pinnedFeatureWorktree($repositoryRoot, 'exact-'.$case);
    $attempt = attemptId();
    $message = match ($case) {
        'proved' => 'proved and cannot be changed',
        'lease' => 'lease names another attempt',
        'record' => 'exact topology attempt does not exist',
        'pointer' => 'not the active topology attempt',
    };
    match ($case) {
        'proved' => $store->write('evidence/proofs/NCK-123/'.$attempt->value.'.json', ['status' => 'proved']),
        'lease' => $attempt = attemptId('b'),
        'record' => $store->write('topologies/NCK-123/active.json', [
            'schema' => 2,
            'issue' => 'NCK-123',
            'attempt' => attemptId('b')->value,
        ]) ?? $store->write('leases/NCK-123.json', [
            ...($store->read('leases/NCK-123.json') ?? []),
            'attempt' => attemptId('b')->value,
        ]),
        'pointer' => null,
    };
    if ($case === 'record') {
        $attempt = attemptId('b');
    }
    if ($case === 'pointer') {
        $record = $store->read('topologies/NCK-123/'.$attempt->value.'.json') ?? [];
        $store->write('topologies/NCK-123/'.attemptId('b')->value.'.json', [
            ...$record,
            'attempt_id' => attemptId('b')->value,
            'network' => featureTarget('NCK-123', 'b')->network(),
            'instances' => array_combine(
                TopologyProfile::ROLES,
                array_map(featureTarget('NCK-123', 'b')->instance(...), TopologyProfile::ROLES),
            ),
        ]);
        $store->write('topologies/NCK-123/active.json', [
            'schema' => 2,
            'issue' => 'NCK-123',
            'attempt' => attemptId('b')->value,
        ]);
    }
    $events = [];
    fakePinnedWorktreeProcesses(featureTarget('NCK-123'), $events);
    $acquirer = taskNineAcquirer($repositoryRoot, $paths);

    try {
        expect(fn () => $acquirer->sync('NCK-123', $attempt, $worktree))
            ->toThrow(RuntimeException::class, $message)
            ->and(fn () => $acquirer->verify('NCK-123', $attempt))
            ->toThrow(RuntimeException::class, $message)
            ->and(fn () => $acquirer->execute('NCK-123', $attempt, 'gateway', ['true']))
            ->toThrow(RuntimeException::class, $message)
            ->and(collect($events)->contains(
                static fn (array $command): bool => in_array('exec', $command, true),
            ))
            ->toBeFalse();
    } finally {
        Process::run(['git', '-C', $repositoryRoot, 'worktree', 'remove', '--force', $worktree]);
    }
})->with(['proved', 'lease', 'record', 'pointer']);

/** @mago-expect lint:excessive-parameter-list The helper exposes each injectable test dependency explicitly. */
function taskNineAcquirer(
    string $repositoryRoot,
    StatePaths $paths,
    ?AcquisitionRollback $rollback = null,
    ?IncusHost $host = null,
    ?AtomicJsonStore $store = null,
    ?OperationJournal $journal = null,
    ?SecretRedactor $redactor = null,
): TopologyAcquirer {
    $store ??= new AtomicJsonStore($paths);
    $host ??= new IncusHost;
    $operation = new OperationId(str_repeat('a', 32));
    $redactor ??= new SecretRedactor;
    $journal ??= new OperationJournal($paths, $redactor);

    return new TopologyAcquirer(
        $host,
        new IncusNetworkLifecycle($host),
        new PreparedStateFingerprint(new GitRepository($repositoryRoot)),
        new StandbyManifestStore($store, $paths, new TopologyManifestStore($store, $paths)),
        new TopologyManifestStore($store, $paths),
        new WorktreeSynchronizer($host, $repositoryRoot, $operation),
        new TopologyConverger($host),
        new TopologyVerifier($host, readinessTimeoutSeconds: 1, readinessPollIntervalMicroseconds: 0),
        $store,
        $paths,
        $operation,
        $journal,
        $redactor,
        new \App\E2E\HostCapacity($store, $paths, $operation, 12),
        new \App\E2E\ProofRecordReader($store),
        $repositoryRoot,
        $rollback,
        attempts: static fn (): \App\E2E\Value\AttemptId => attemptId(),
    );
}

/**
 * @param Closure(string, string, string, StatePaths, stdClass): void $inject
 * @return array{string, string, StatePaths, AtomicJsonStore, stdClass}
 */
function topologyAcquisitionBoundaryFixture(Closure $inject): array
{
    $repositoryRoot = preparedTopologyRepository();
    $worktree = pinnedFeatureWorktree($repositoryRoot, 'acquisition-boundary');
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $fault = (object) ['ready_lease' => false];
    $leaseWrites = 0;
    $store = new AtomicJsonStore(
        $paths,
        failure: function (string $phase, string $temporary, string $file) use (
            $inject,
            $paths,
            $fault,
            &$leaseWrites,
        ): void {
            $readyLease =
                $phase === 'before_rename' && str_ends_with($file, '/leases/NCK-123.json') && ++$leaseWrites === 2;
            if ($readyLease) {
                $fault->ready_lease = true;
            }

            $inject($phase, $temporary, $file, $paths, $fault);

            if ($readyLease) {
                throw new RuntimeException('primary acquisition Bearer primary-secret');
            }
        },
    );
    $prepared = topologyFinalPreparedFingerprint($repositoryRoot);
    $structural = new PreparedStateFingerprint(new GitRepository($repositoryRoot))->forCommit();
    new StandbyManifestStore($store, $paths, new TopologyManifestStore($store, $paths))->promote(new StandbyGeneration(
        preparedGenerationId($repositoryRoot, $prepared->value),
        new GitRepository($repositoryRoot)->commit(),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $prepared->value,
        str_repeat('b', 64),
        topologyPromotedLaravel(),
        $structural->value,
        $structural->manifest['schema'],
        $structural->manifest['cold_epoch'],
        $structural->manifest['base_image_alias'],
        $structural->manifest['topology']['profile'],
        $structural->manifest['topology']['roles'],
        $structural->manifest['topology']['checkout_roles'],
    ));

    return [$repositoryRoot, $worktree, $paths, $store, $fault];
}

/** @param list<string> $reads */
function absentAcquisitionRollback(array &$reads): AcquisitionRollback
{
    return serialAcquisitionRollback(
        function (string $resource) use (&$reads): null {
            $reads[] = $resource;

            return null;
        },
        static function (): void {},
        static function (): void {},
        static function (): void {},
    );
}

/** Adapt serial fault injectors to the production-only batch rollback contract. */
function serialAcquisitionRollback(
    Closure $read,
    Closure $stop,
    Closure $deleteInstance,
    Closure $deleteNetwork,
): AcquisitionRollback {
    $deleted = [];

    return new AcquisitionRollback(
        static function (array $resources) use ($read, &$deleted): array {
            $inventory = [];
            foreach ($resources as $resource) {
                $inventory[$resource] = isset($deleted[$resource]) ? null : $read($resource);
            }

            return $inventory;
        },
        static function (array $resources) use ($stop): void {
            foreach (array_reverse($resources) as $resource) {
                $stop($resource);
            }
        },
        static function (array $resources) use ($deleteInstance, &$deleted): void {
            foreach (array_reverse($resources) as $resource) {
                $deleteInstance($resource);
                $deleted[$resource] = true;
            }
        },
        static function (string $resource) use ($deleteNetwork, &$deleted): void {
            $deleteNetwork($resource);
            $deleted[$resource] = true;
        },
    );
}

/** @param Closure(): mixed $operation */
function capturedTopologyAcquisitionFailure(Closure $operation): Throwable
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception;
    }

    throw new RuntimeException('The topology acquisition was expected to fail.');
}

/** @param list<string> $command */
function topologyFirewallResult(array $command): ?\Illuminate\Contracts\Process\ProcessResult
{
    if (
        ($command[0] ?? null) === 'python3'
        && str_ends_with((string) ($command[1] ?? ''), '/resources/host/reconcile-firewall.py')
    ) {
        return Process::result(json_encode(['changed' => false], JSON_THROW_ON_ERROR));
    }

    if (array_slice($command, 0, 5) !== ['sudo', '-n', 'iptables', '-w', '5']) {
        return null;
    }

    return in_array('-C', $command, true) ? Process::result('', '', 1) : Process::result();
}

/** @return list<string> */
function topologyIncus(string ...$arguments): array
{
    return ['incus', '--project', 'default', ...$arguments];
}

function topology_creation_failure_process_result(
    \Illuminate\Process\PendingProcess $process,
    TopologyTarget $target,
    string $repositoryRoot,
    ProcessFactory $realProcess,
): \Illuminate\Contracts\Process\ProcessResult {
    $hostResult = topology_creation_failure_host_result($process, $target, $repositoryRoot, $realProcess);
    if ($hostResult !== null) {
        return $hostResult;
    }

    return topology_creation_failure_incus_result($process->command, $target);
}

function topology_creation_failure_host_result(
    \Illuminate\Process\PendingProcess $process,
    TopologyTarget $target,
    string $repositoryRoot,
    ProcessFactory $realProcess,
): ?\Illuminate\Contracts\Process\ProcessResult {
    if (($firewall = topologyFirewallResult($process->command)) !== null) {
        return $firewall;
    }
    if (($process->command[0] ?? null) === 'git') {
        return $realProcess->path($repositoryRoot)->input($process->input)->run($process->command);
    }
    if (in_array('image', $process->command, true) && in_array('list', $process->command, true)) {
        return Process::result(preparedBaseImageJson(str_repeat('a', 64)));
    }
    if (in_array('network', $process->command, true) && in_array('list', $process->command, true)) {
        return Process::result(json_encode([[
            'name' => $target->network(),
            'config' => [
                'user.orbit.e2e.owner' => 'orbit-e2e',
                'user.orbit.e2e.issue' => 'NCK-123',
                'user.orbit.e2e.attempt' => attemptId()->value,
                'ipv4.address' => '10.232.2.1/24',
            ],
        ]], JSON_THROW_ON_ERROR));
    }

    return null;
}

/** @param list<string> $command */
function topology_creation_failure_incus_result(
    array $command,
    TopologyTarget $target,
): \Illuminate\Contracts\Process\ProcessResult {
    if ($command === topologyIncus('list', 'local:'.$target->instance('gateway'), '--format=json')) {
        return Process::result(topologyVmJson($target->instance('gateway')));
    }
    if (in_array('list', $command, true) && ! in_array('snapshot', $command, true)) {
        return Process::result(standbyVmInventoryJson());
    }
    if (in_array('snapshot', $command, true) && in_array('list', $command, true)) {
        $instance = preg_replace('/\A[^:]+:/', '', (string) ($command[5] ?? ''));

        return Process::result(standbySnapshotInventoryJson($instance));
    }
    if (
        ($command[3] ?? null) === 'copy'
        && ($command[5] ?? null) === 'local:'.$target->instance('gateway')
    ) {
        return Process::result('', 'copy failed', 1);
    }

    return Process::result();
}

function preparedTopologyRepository(): string
{
    $root = temporaryPath('orbit-prepared-topology-', 8);
    $e2e = dirname(__DIR__, 3);
    $manifestPath = 'apps/e2e/resources/prepared-state.json';
    mkdir($root.'/'.dirname($manifestPath), 0700, true);
    copy($e2e.'/resources/prepared-state.json', $root.'/'.$manifestPath);
    $manifest = json_decode((string) file_get_contents($root.'/'.$manifestPath), true, 512, JSON_THROW_ON_ERROR);

    foreach ($manifest['paths'] as $pattern) {
        if ($pattern === $manifestPath) {
            continue;
        }
        $path = str_replace(['**/', '*'], ['nested/', 'placeholder'], $pattern);
        $directory = $root.'/'.dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($root.'/'.$path, 'prepared');
    }
    $guestSource = $e2e.'/resources/guest';
    $guestTarget = $root.'/apps/e2e/resources/guest';
    foreach (glob($guestSource.'/*.sh') ?: [] as $script) {
        copy($script, $guestTarget.'/'.basename($script));
        chmod($guestTarget.'/'.basename($script), 0755);
    }
    // Vendor trees and the gateway environment stay out of the tree, as in Orbit itself.
    file_put_contents($root.'/.gitignore', "/vendor/\nvendor/\n.env\n");
    hydrateFixtureVendor($root);

    foreach ([
        ['git', 'init', '-q', '-b', 'feature/NCK-123', $root],
        ['git', '-C', $root, 'config', 'user.email', 'developer@example.com'],
        ['git', '-C', $root, 'config', 'user.name', 'Orbit Developer'],
        ['git', '-C', $root, 'add', '.'],
        ['git', '-C', $root, 'commit', '-q', '-m', 'Prepared state'],
        ['git', '-C', $root, 'branch', 'main'],
    ] as $command) {
        if (! Process::run($command)->successful()) {
            throw new RuntimeException('Unable to prepare the topology fixture repository.');
        }
    }

    return $root;
}

/** Host `bin/bootstrap` owns vendor; discovery requires every autoload before any Incus mutation. */
function hydrateFixtureVendor(string $worktree): void
{
    foreach (['apps/gateway', 'apps/cli', 'packages/php-sdk'] as $project) {
        if (! is_dir($worktree.'/'.$project.'/vendor')) {
            mkdir($worktree.'/'.$project.'/vendor', 0700, true);
        }
        file_put_contents($worktree.'/'.$project.'/vendor/autoload.php', "<?php\n");
    }
}

function standbyVmInventoryJson(): string
{
    $roles = \App\E2E\Value\TopologyProfile::ROLES;
    $instances = array_merge(
        array_map(static fn (string $role): string => TopologyTarget::standby()->instance($role), $roles),
        array_map(static fn (string $role): string => featureTarget('NCK-123')->instance($role), $roles),
    );

    return json_encode(array_map(
        static fn (string $name): array => [
            'name' => $name,
            'type' => 'virtual-machine',
            'status' => 'Stopped',
            'status_code' => 102,
            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
            'devices' => [
                'root' => ['pool' => 'default'],
                'eth0' => ['network' => TopologyTarget::standby()->network()],
            ],
        ],
        $instances,
    ), JSON_THROW_ON_ERROR);
}

function standbySnapshotInventoryJson(string $instance, bool $include = true, string $owner = 'orbit-e2e'): string
{
    $role = str_replace('orbit-e2e-standby-', '', $instance);
    $snapshot = match ($role) {
        'gateway' => 'main-gateway',
        'app-dev' => 'main-app-dev',
        'app-prod' => 'main-app-prod',
        default => throw new RuntimeException('Unexpected standby fixture instance.'),
    };

    return json_encode(
        $include
            ? [[
                'name' => $snapshot,
                'config' => ['user.orbit.e2e.owner' => $owner],
            ]] : [],
        JSON_THROW_ON_ERROR,
    );
}

function topologyVmJson(
    string $name,
    array $metadata = ['user.orbit.e2e.owner' => 'orbit-e2e'],
    ?string $network = null,
): string {
    $devices = ['root' => ['pool' => 'default']];
    if ($network !== null) {
        $role = match (true) {
            str_ends_with($name, '-gateway') => 'gateway',
            str_ends_with($name, '-app-dev') => 'app-dev',
            default => 'app-prod',
        };
        $devices['eth0'] = [
            'network' => $network,
            'hwaddr' => '00:16:3e:'.implode(':', str_split(substr(sha1($network.':'.$role), 0, 6), 2)),
            'ipv4.address' => TopologyTarget::ipv4For(2, $role),
        ];
    }

    return json_encode([[
        'name' => $name,
        'type' => 'virtual-machine',
        'status' => 'Stopped',
        'status_code' => 102,
        'config' => $metadata,
        'devices' => $devices,
    ]], JSON_THROW_ON_ERROR);
}

function preparedBaseImageJson(string $fingerprint): string
{
    return json_encode([[
        'type' => 'virtual-machine',
        'fingerprint' => $fingerprint,
        'aliases' => [['name' => 'orbit-base-ubuntu-26.04-runtime']],
    ]], JSON_THROW_ON_ERROR);
}

function preparedGenerationId(string $repositoryRoot, string $fingerprint): string
{
    return substr(new GitRepository($repositoryRoot)->commit(), 0, 12).'-'.substr($fingerprint, 0, 12);
}

function topologyPromotedLaravel(): LaravelRelease
{
    return new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0');
}

function topologyFinalPreparedFingerprint(
    string $repositoryRoot,
    string $commit = 'HEAD',
): \App\E2E\Value\PreparedFingerprint {
    $release = topologyPromotedLaravel();

    return new PreparedStateFingerprint(new GitRepository($repositoryRoot))->forCommit($commit, $release);
}

function featureTopologyFixture(string $repositoryRoot, StatePaths $paths): void
{
    $store = new AtomicJsonStore($paths);
    $fingerprint = topologyFinalPreparedFingerprint($repositoryRoot);
    $structural = new PreparedStateFingerprint(new GitRepository($repositoryRoot))->forCommit();
    $target = featureTarget('NCK-123');
    $generation = new StandbyGeneration(
        str_repeat('a', 12).'-'.substr($fingerprint->value, 0, 12),
        new GitRepository($repositoryRoot)->commit(),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $fingerprint->value,
        str_repeat('b', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
        $structural->value,
        $structural->manifest['schema'],
        $structural->manifest['cold_epoch'],
        $structural->manifest['base_image_alias'],
        $structural->manifest['topology']['profile'],
        $structural->manifest['topology']['roles'],
        $structural->manifest['topology']['checkout_roles'],
    );
    new TopologyManifestStore($store, $paths)->writeActive(new FeatureTopology(
        $target,
        AttemptPurpose::Discovery,
        $generation,
        $target->network(),
        [
            'gateway' => $target->instance('gateway'),
            'app-dev' => $target->instance('app-dev'),
            'app-prod' => $target->instance('app-prod'),
        ],
        new SourceState(new GitRepository($repositoryRoot)->commit(), new GitRepository($repositoryRoot)->commit()),
        new VerificationReport(true, ['fixture' => verificationProbeFixture()]),
    ));
    $store->write('leases/NCK-123.json', [
        'schema' => 2,
        'issue' => 'NCK-123',
        'attempt' => attemptId()->value,
        'state' => 'ready',
        'operation_id' => str_repeat('a', 32),
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() + 604800),
    ]);
}

/** @param list<string> $mutations */
function identityRefreshRollback(
    TopologyTarget $target,
    ?string &$operationId,
    array &$mutations,
): AcquisitionRollback {
    return serialAcquisitionRollback(
        function (string $resource) use ($target, &$operationId): IncusInstance|IncusNetwork {
            return (
                $resource === $target->network()
                    ? new IncusNetwork('local', 'default', $resource, [
                        'user.orbit.e2e.owner' => 'orbit-e2e',
                        'user.orbit.e2e.issue' => 'NCK-123',
                        'user.orbit.e2e.attempt' => attemptId()->value,
                        'user.orbit.e2e.operation' => $operationId,
                    ])
                    : new IncusInstance('local', 'default', $resource, 'default', [
                        'user.orbit.e2e.owner' => 'orbit-e2e',
                        'user.orbit.e2e.issue' => 'NCK-123',
                        'user.orbit.e2e.attempt' => attemptId()->value,
                        'user.orbit.e2e.operation' => $operationId,
                    ])
            );
        },
        function (string $resource) use (&$mutations): void {
            $mutations[] = 'stop:'.$resource;
        },
        function (string $resource) use (&$mutations): void {
            $mutations[] = 'delete:'.$resource;
            throw new RuntimeException('cleanup failed');
        },
        function (string $resource) use (&$mutations): void {
            $mutations[] = 'network:'.$resource;
        },
    );
}

/** @param list<string> $command */
function identityRefreshInventoryResult(
    array $command,
    TopologyTarget $target,
    ?string &$operationId,
): ?\Illuminate\Contracts\Process\ProcessResult {
    if (($firewall = topologyFirewallResult($command)) !== null) {
        return $firewall;
    }
    if (in_array('image', $command, true) && in_array('list', $command, true)) {
        return Process::result(preparedBaseImageJson(str_repeat('a', 64)));
    }
    if (in_array('network', $command, true) && in_array('list', $command, true)) {
        return Process::result(json_encode([[
            'name' => $target->network(),
            'config' => [
                'user.orbit.e2e.owner' => 'orbit-e2e',
                'user.orbit.e2e.issue' => 'NCK-123',
                'user.orbit.e2e.attempt' => attemptId()->value,
                'user.orbit.e2e.operation' => $operationId,
                'ipv4.address' => '10.232.2.1/24',
            ],
        ]], JSON_THROW_ON_ERROR));
    }
    if (in_array('list', $command, true) && ! in_array('snapshot', $command, true)) {
        $identity = (string) ($command[4] ?? '');
        if ($identity === 'local:') {
            $standby = array_values(array_filter(
                json_decode(standbyVmInventoryJson(), true, 16, JSON_THROW_ON_ERROR),
                static fn (array $instance): bool => str_starts_with(
                    (string) ($instance['name'] ?? ''),
                    'orbit-e2e-standby-',
                ),
            ));
            $feature = array_map(
                static fn (string $role): array => json_decode(
                    topologyVmJson($target->instance($role), [
                        'user.orbit.e2e.owner' => 'orbit-e2e',
                        'user.orbit.e2e.issue' => 'NCK-123',
                        'user.orbit.e2e.attempt' => attemptId()->value,
                        'user.orbit.e2e.operation' => $operationId,
                    ]),
                    true,
                    16,
                    JSON_THROW_ON_ERROR,
                )[0],
                TopologyProfile::ROLES,
            );

            return Process::result(json_encode([...$standby, ...$feature], JSON_THROW_ON_ERROR));
        }
        $featurePrefix = 'local:orbit-e2e-nck-123-aaaaaaaa-';
        if (str_starts_with($identity, $featurePrefix)) {
            $name = preg_replace('/\A[^:]+:/', '', $identity);

            return Process::result(topologyVmJson($name, [
                'user.orbit.e2e.owner' => 'orbit-e2e',
                'user.orbit.e2e.issue' => 'NCK-123',
                'user.orbit.e2e.attempt' => attemptId()->value,
                'user.orbit.e2e.operation' => $operationId,
            ]));
        }

        return Process::result(standbyVmInventoryJson());
    }
    if (in_array('snapshot', $command, true) && in_array('list', $command, true)) {
        $instance = preg_replace('/\A[^:]+:/', '', (string) ($command[5] ?? ''));

        return Process::result(standbySnapshotInventoryJson($instance));
    }

    return null;
}

/** @param list<string> $command @param list<string> $events */
/** @mago-expect lint:cyclomatic-complexity The fake models each exact identity-refresh mutation command. */
function identityRefreshMutationResult(array $command, array &$events): \Illuminate\Contracts\Process\ProcessResult
{
    if (($command[3] ?? null) === 'copy') {
        $hasNetwork = array_any(
            $command,
            fn (mixed $argument): bool => is_string($argument) && str_contains($argument, 'network='),
        );
        $hasMac = array_any(
            $command,
            fn (mixed $argument): bool => is_string($argument) && str_contains($argument, 'hwaddr='),
        );
        $events[] = $hasNetwork && $hasMac ? 'clone-network-mac' : 'clone';

        return Process::result();
    }
    if (
        ($command[3] ?? null) === 'config'
        && in_array('network=oe-b32d6c83af72', $command, true)
    ) {
        $events[] = array_any(
            $command,
            fn (mixed $argument): bool => is_string($argument) && str_starts_with($argument, 'hwaddr='),
        )
            ? 'network-mac'
            : 'network';

        return Process::result();
    }
    if (array_any(
        $command,
        fn (mixed $argument): bool => is_string($argument) && str_starts_with($argument, 'hwaddr='),
    )) {
        $events[] = 'mac';

        return Process::result();
    }
    if (in_array('start', $command, true)) {
        $events[] = 'start';

        return Process::result();
    }
    if (in_array('exec', $command, true) && in_array('/bin/true', $command, true)) {
        $events[] = 'readiness';

        return Process::result();
    }
    if (
        in_array('exec', $command, true)
        && str_contains(implode(' ', $command), 'ip -4 -o addr show dev "$interface" scope global')
    ) {
        $events[] = 'ipv4';

        return Process::result("2: enp5s0    inet 10.44.0.10/24 scope global enp5s0\n");
    }
    if (in_array('exec', $command, true) && in_array('sh', $command, true)) {
        $events[] = 'identity';

        return Process::result('', 'identity refresh failed', 1);
    }

    return Process::result();
}

/**
 * @param  list<list<string>>  $events
 * @param  callable(list<string>): bool  $matches
 * @return list<int>
 */
function topology_command_indices(array $events, string $instance, callable $matches): array
{
    $indices = [];
    foreach ($events as $index => $command) {
        $targetsInstance = array_any(
            $command,
            static fn (mixed $argument): bool => (
                is_string($argument)
                && ($argument === $instance || str_ends_with($argument, ':'.$instance))
            ),
        );
        if (! $targetsInstance || ! $matches($command)) {
            continue;
        }

        $indices[] = $index;
    }

    return $indices;
}

/** @param list<string> $events */
function fakeIdentityRefreshFailure(
    string $repositoryRoot,
    TopologyTarget $target,
    array &$events,
    ?string &$operationId,
): void {
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        $target,
        $repositoryRoot,
        $realProcess,
        &$events,
        &$operationId,
    ) {
        $command = $process->command;
        if (($command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->input($process->input)->run($command);
        }

        foreach ($command as $argument) {
            if (preg_match('/\Auser\.orbit\.e2e\.operation=([0-9a-f]{32})\z/', (string) $argument, $matches)) {
                $operationId = $matches[1];
            }
        }

        if (
            ($batch = pinnedWorktreeBatchResult(
                $process,
                $events,
                static function (array $guest) use (&$events): \Illuminate\Contracts\Process\ProcessResult {
                    return identityRefreshMutationResult(
                        topologyIncus('exec', 'local:orbit-e2e-nck-123-aaaaaaaa-gateway', '--', ...$guest),
                        $events,
                    );
                },
            )) !== null
        ) {
            return $batch;
        }

        return (
            identityRefreshInventoryResult($command, $target, $operationId) ?? identityRefreshMutationResult(
                $command,
                $events,
            )
        );
    });
}

function pinnedFeatureWorktree(string $repositoryRoot, string $suffix): string
{
    $worktree = temporaryPath('orbit-worktree-'.$suffix.'-');
    $sourcePath = $worktree.'/feature-source-'.$suffix.'.txt';
    foreach ([
        ['git', '-C', $repositoryRoot, 'worktree', 'add', '-q', '-b', 'feature/NCK-123-'.$suffix, $worktree, 'HEAD'],
        ['git', '-C', $worktree, 'config', 'user.email', 'developer@example.com'],
        ['git', '-C', $worktree, 'config', 'user.name', 'Orbit Developer'],
    ] as $index => $command) {
        if (! Process::run($command)->successful()) {
            throw new RuntimeException('Unable to prepare a feature worktree.');
        }
    }
    file_put_contents($sourcePath, "feature source {$suffix}\n");
    if (! Process::run(['git', '-C', $worktree, 'add', $sourcePath])->successful()) {
        throw new RuntimeException('Unable to stage the feature fixture.');
    }
    if (! Process::run(['git', '-C', $worktree, 'commit', '-q', '-m', 'Pin Laravel'])->successful()) {
        throw new RuntimeException('Unable to commit the feature fixture.');
    }
    hydrateFixtureVendor($worktree);

    return $worktree;
}

/**
 * @param list<string> $command
 * @mago-expect lint:cyclomatic-complexity The fake inventories each exact Incus resource kind.
 */
function pinnedWorktreeInventoryResult(
    array $command,
    TopologyTarget $target,
): ?\Illuminate\Contracts\Process\ProcessResult {
    if (($firewall = topologyFirewallResult($command)) !== null) {
        return $firewall;
    }
    if (in_array('image', $command, true) && in_array('list', $command, true)) {
        return Process::result(preparedBaseImageJson(str_repeat('b', 64)));
    }
    if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'list') {
        return Process::result(json_encode([[
            'name' => $target->network(),
            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e', 'ipv4.address' => '10.232.2.1/24'],
        ]], JSON_THROW_ON_ERROR));
    }
    if (($command[3] ?? null) === 'list') {
        if (($command[4] ?? null) === 'local:') {
            $featureInstances = array_map(
                static fn (string $role): array => json_decode(
                    topologyVmJson(
                        $target->instance($role),
                        ['user.orbit.e2e.owner' => 'orbit-e2e'],
                        $target->network(),
                    ),
                    true,
                    16,
                    JSON_THROW_ON_ERROR,
                )[0],
                \App\E2E\Value\TopologyProfile::ROLES,
            );

            return Process::result(json_encode(array_merge(
                array_values(array_filter(
                    json_decode(standbyVmInventoryJson(), true, 16, JSON_THROW_ON_ERROR),
                    static fn (array $vm): bool => ! str_contains((string) $vm['name'], 'nck-123'),
                )),
                $featureInstances,
            ), JSON_THROW_ON_ERROR));
        }
        $name = preg_replace('/\A[^:]+:/', '', (string) ($command[4] ?? ''));

        return Process::result(
            $name === $target->network()
                ? '[]'
                : topologyVmJson($name, ['user.orbit.e2e.owner' => 'orbit-e2e']),
        );
    }
    if (($command[3] ?? null) === 'snapshot' && ($command[4] ?? null) === 'list') {
        $instance = preg_replace('/\A[^:]+:/', '', (string) ($command[5] ?? ''));

        return Process::result(standbySnapshotInventoryJson($instance));
    }

    return null;
}

/** @param list<string> $guest */
function pinnedWorktreeGuestCommandResult(array $guest): \Illuminate\Contracts\Process\ProcessResult
{
    if (array_slice($guest, 0, 6) === ['runuser', '-u', 'orbit', '--', 'env', 'HOME=/home/orbit']) {
        $guest = array_slice($guest, 6);
    }
    if (
        $guest === [
            'sh',
            '-c',
            'interface=$(ip -4 route show default | awk \'$1 == "default" { for (i = 2; i < NF; i++) if ($i == "dev") { print $(i + 1); exit } }\') && [ -n "$interface" ] && ip -4 -o addr show dev "$interface" scope global',
        ]
    ) {
        return Process::result("2: enp5s0    inet 10.44.0.10/24 scope global enp5s0\n");
    }
    if (($guest[0] ?? null) === '/usr/local/bin/receive-source.sh') {
        $sha = collect($guest)->first(
            static fn (mixed $value): bool => is_string($value) && preg_match('/\A[0-9a-f]{40}\z/', $value) === 1,
        );
        $treeHash = collect($guest)->first(
            static fn (mixed $value): bool => is_string($value) && preg_match('/\A[0-9a-f]{64}\z/', $value) === 1,
        );

        return Process::result(json_encode([
            'sha' => $sha,
            'tree_hash' => $treeHash,
        ], JSON_THROW_ON_ERROR));
    }
    if (($guest[0] ?? null) === '/usr/local/bin/verify-topology.sh') {
        return Process::result(json_encode([
            'probe' => $guest[1],
            'passed' => true,
            'identity' => $guest[3],
            'checked_at' => '2026-08-29T12:34:56+00:00',
            'expected' => 'healthy',
            'observed' => 'healthy',
            'evidence_ref' => 'incus://'.$guest[4].'/'.$guest[1],
        ], JSON_THROW_ON_ERROR));
    }
    if (in_array('ssh-keygen', $guest, true)) {
        return Process::result('ssh-ed25519 '.str_repeat('A', 43)."=\n");
    }
    if ($guest === ['uname', '-m']) {
        return Process::result("x86_64\n");
    }

    return Process::result();
}

/** @param list<string> $command */
function pinnedWorktreeGuestResult(array $command): \Illuminate\Contracts\Process\ProcessResult
{
    return pinnedWorktreeGuestCommandResult(array_slice($command, 6));
}

/**
 * @param null|list<array<array-key, mixed>> $events
 * @param null|Closure(list<string>): (?\Illuminate\Contracts\Process\ProcessResult) $guestOverride
 */
function pinnedWorktreeBatchResult(
    \Illuminate\Process\PendingProcess $process,
    ?array &$events = null,
    ?Closure $guestOverride = null,
): ?\Illuminate\Contracts\Process\ProcessResult {
    $command = $process->command;
    if (
        ($command[0] ?? null) !== 'python3'
        || ! str_ends_with((string) ($command[1] ?? ''), '/resources/host/exec-all.py')
    ) {
        return null;
    }

    $payload = json_decode((string) $process->input, true, 512, JSON_THROW_ON_ERROR);
    $results = [];
    foreach ($payload['requests'] as $request) {
        $guest = $request['argv'];
        if ($events !== null) {
            $events[] = [
                'incus',
                '--project',
                'default',
                'exec',
                $request['instance'],
                '--',
                ...$guest,
            ];
        }
        $normalizedGuest = array_slice($guest, 0, 6) === ['runuser', '-u', 'orbit', '--', 'env', 'HOME=/home/orbit']
            ? array_slice($guest, 6)
            : $guest;
        $result = $guestOverride?->__invoke($normalizedGuest) ?? pinnedWorktreeGuestCommandResult($guest);
        $results[] = [
            'label' => $request['label'],
            'stdout' => $result->output(),
            'stderr' => $result->errorOutput(),
            'exit_code' => $result->exitCode(),
        ];
    }

    return Process::result(json_encode($results, JSON_THROW_ON_ERROR));
}

/**
 * @param list<array<array-key, mixed>> $events
 * @param null|Closure(list<string>): void $observe
 */
function fakePinnedWorktreeProcesses(TopologyTarget $target, array &$events, ?Closure $observe = null): void
{
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$events,
        $realProcess,
        $target,
        $observe,
    ) {
        $command = $process->command;
        $observe?->__invoke($command);
        if (($command[0] ?? null) === 'git') {
            return $realProcess
                ->path((string) ($process->path ?: getcwd()))
                ->input($process->input)
                ->run($command);
        }
        if (($batch = pinnedWorktreeBatchResult($process, $events)) !== null) {
            return $batch;
        }
        $events[] = $command;

        return pinnedWorktreeInventoryResult($command, $target) ?? pinnedWorktreeGuestResult($command);
    });
}

/** @param list<array<array-key, mixed>> $events */
function fakeOrdinaryPreparedChangeProcesses(string $repositoryRoot, TopologyTarget $target, array &$events): void
{
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$events,
        $realProcess,
        $repositoryRoot,
        $target,
    ) {
        $command = $process->command;
        if (($command[0] ?? null) === 'git') {
            return $realProcess
                ->path((string) $process->path ?: $repositoryRoot)
                ->input($process->input)
                ->run($command);
        }
        $batch = pinnedWorktreeBatchResult($process, $events);
        if ($batch !== null) {
            return $batch;
        }
        $events[] = $command;
        if (($command[6] ?? null) === '/usr/local/bin/converge-gateway.sh') {
            return Process::result('', 'controlled convergence failure', 1);
        }

        return pinnedWorktreeInventoryResult($command, $target) ?? pinnedWorktreeGuestResult($command);
    });
}

it('holds the shared standby pin only through snapshot copy', function () {
    $repositoryRoot = preparedTopologyRepository();
    $worktree = pinnedFeatureWorktree($repositoryRoot, 'standby-pin');
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $store = new AtomicJsonStore($paths);
    $prepared = topologyFinalPreparedFingerprint($repositoryRoot);
    $mainSha = new GitRepository($repositoryRoot)->commit();
    $structural = new PreparedStateFingerprint(new GitRepository($repositoryRoot))->forCommit($mainSha);
    new StandbyManifestStore($store, $paths, new TopologyManifestStore($store, $paths))->promote(new StandbyGeneration(
        substr($mainSha, 0, 12).'-'.substr($prepared->value, 0, 12),
        $mainSha,
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $prepared->value,
        str_repeat('b', 64),
        topologyPromotedLaravel(),
        $structural->value,
        $structural->manifest['schema'],
        $structural->manifest['cold_epoch'],
        $structural->manifest['base_image_alias'],
        $structural->manifest['topology']['profile'],
        $structural->manifest['topology']['roles'],
        $structural->manifest['topology']['checkout_roles'],
    ));
    $events = [];
    $copyExclusiveLockResults = [];
    $copySharedLockResults = [];
    $postCopyLockResult = null;
    fakePinnedWorktreeProcesses(
        featureTarget('NCK-123'),
        $events,
        function (array $command) use (
            $paths,
            &$copyExclusiveLockResults,
            &$copySharedLockResults,
            &$postCopyLockResult,
        ): void {
            $isCopy = ($command[3] ?? null) === 'copy';
            $isFirstStart = ($command[3] ?? null) === 'start' && $postCopyLockResult === null;
            if (! $isCopy && ! $isFirstStart) {
                return;
            }

            $probe = new OperationLock($paths);
            $acquired = $probe->acquire(
                'standby-generation',
                new OperationId(str_repeat('d', 32)),
                timeoutSeconds: 0,
            );
            if ($isCopy) {
                $copyExclusiveLockResults[] = $acquired;
            } else {
                $postCopyLockResult = $acquired;
            }
            if ($acquired) {
                $probe->release();
            }

            if ($isCopy) {
                $sharedProbe = new OperationLock($paths);
                $sharedAcquired = $sharedProbe->acquire(
                    'standby-generation',
                    new OperationId(str_repeat('e', 32)),
                    exclusive: false,
                    timeoutSeconds: 0,
                );
                $copySharedLockResults[] = $sharedAcquired;
                if ($sharedAcquired) {
                    $sharedProbe->release();
                }
            }
        },
    );

    try {
        taskNineAcquirer($repositoryRoot, $paths)->acquireDiscovery(new TopologyRequest('NCK-123', $worktree));

        expect($copyExclusiveLockResults)
            ->toBe([false, false, false])
            ->and($copySharedLockResults)
            ->toBe([true, true, true])
            ->and($postCopyLockResult)
            ->toBeTrue();
    } finally {
        Process::run(['git', '-C', $repositoryRoot, 'worktree', 'remove', '--force', $worktree]);
    }
});

it('requires an exact issue and a real absolute worktree', function () {
    expect(fn () => new TopologyRequest('feature-12', __DIR__))
        ->toThrow(InvalidArgumentException::class, 'Linear issue ID');
    expect(fn () => new TopologyRequest('NCK-12', 'relative/path'))
        ->toThrow(InvalidArgumentException::class, 'absolute');

    $request = new TopologyRequest('NCK-12', dirname(__DIR__, 4));

    expect($request->issue)
        ->toBe('NCK-12')
        ->and($request->worktree)
        ->toBe(realpath(dirname(__DIR__, 4)));
});

it('binds proof to exact candidate and tree identities', function () {
    $proof = new ProofResult(
        str_repeat('a', 32),
        str_repeat('b', 32),
        str_repeat('c', 40),
        str_repeat('e', 40),
        str_repeat('d', 64),
        new VerificationReport(true, [
            'candidate.probes' => verificationProbeFixture(probe: 'candidate.probes'),
        ]),
    );

    expect($proof->toArray())->toMatchArray([
        'state' => 'proved',
        'candidate_sha' => str_repeat('c', 40),
        'candidate_tree' => str_repeat('e', 40),
        'tree_hash' => str_repeat('d', 64),
    ]);
});

it('keeps the command operation identity separate from proof evidence', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-proof-operation-', 8));
    $worktree = pinnedFeatureWorktree($repositoryRoot, 'proof-operation');
    featureTopologyFixture($repositoryRoot, $paths);
    $target = featureTarget('NCK-123');
    $candidateSha = new GitRepository($worktree)->commit();
    $realProcess = new ProcessFactory;

    Process::fake(function (\Illuminate\Process\PendingProcess $process) use ($candidateSha, $realProcess, $target) {
        $command = $process->command;
        if (($command[0] ?? null) === 'git') {
            return $realProcess
                ->path((string) ($process->path ?: getcwd()))
                ->input($process->input)
                ->run($command);
        }

        if (($batch = pinnedWorktreeBatchResult($process)) !== null) {
            return $batch;
        }

        $guest = array_slice($command, 6);
        if (array_slice($guest, 0, 6) === ['runuser', '-u', 'orbit', '--', 'env', 'HOME=/home/orbit']) {
            $guest = array_slice($guest, 6);
        }
        if (in_array('git', $guest, true) && in_array('rev-parse', $guest, true)) {
            return Process::result($candidateSha."\n");
        }
        if (in_array('git', $guest, true) && in_array('status', $guest, true)) {
            return Process::result();
        }

        return pinnedWorktreeInventoryResult($command, $target) ?? pinnedWorktreeGuestResult($command);
    });

    try {
        $result = taskNineAcquirer($repositoryRoot, $paths)->prove(
            new TopologyRequest('NCK-123', $worktree),
            $candidateSha,
        );
        $stored = new AtomicJsonStore($paths)->read('proof/NCK-123.json');

        expect($result->operationId)
            ->toBe(str_repeat('a', 32))
            ->and($result->evidenceId)
            ->not
            ->toBe($result->operationId)
            ->and($stored['operation_id'] ?? null)
            ->toBe($result->operationId)
            ->and($stored['evidence_id'] ?? null)
            ->toBe($result->evidenceId);
    } finally {
        Process::run(['git', '-C', $repositoryRoot, 'worktree', 'remove', '--force', $worktree]);
    }
});

it('checks copied ownership before applying issue metadata', function () {
    Process::fake(function (\Illuminate\Process\PendingProcess $process) {
        if (str_contains(implode(' ', $process->command ?? []), 'list')) {
            return Process::result(json_encode([[
                'name' => 'orbit-e2e-nck-123-aaaaaaaa-gateway',
                'type' => 'virtual-machine',
                'status' => 'Stopped',
                'status_code' => 102,
                'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                'devices' => ['root' => ['pool' => 'orbit-e2e']],
            ]], JSON_THROW_ON_ERROR));
        }

        return Process::result();
    });

    $host = new IncusHost(remote: 'lab', project: 'orbit', pool: 'orbit-e2e');
    $host->setMetadata('orbit-e2e-nck-123-aaaaaaaa-gateway', ['user.orbit.e2e.issue' => 'NCK-123']);

    Process::assertRanInOrder([
        ['incus', '--project', 'orbit', 'list', 'lab:orbit-e2e-nck-123-aaaaaaaa-gateway', '--format=json'],
        [
            'incus',
            '--project',
            'orbit',
            'config',
            'set',
            'lab:orbit-e2e-nck-123-aaaaaaaa-gateway',
            'user.orbit.e2e.issue=NCK-123',
        ],
    ]);
});

it('limits proof checkout identity checks to the configured checkout roles', function () {
    expect(\App\E2E\Value\TopologyProfile::CHECKOUT_ROLES)->toBe(['gateway', 'app-dev']);
});

it('recovers a manifest-backed acquiring lease without mutating Incus', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    featureTopologyFixture($repositoryRoot, $paths);
    $store = new AtomicJsonStore($paths);
    $store->write('leases/NCK-123.json', [
        'schema' => 2,
        'issue' => 'NCK-123',
        'attempt' => attemptId()->value,
        'state' => 'acquiring',
        'operation_id' => str_repeat('b', 32),
        'pid' => 999999,
        'process_start_identity' => 'dead-test-owner',
        'acquired_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() + 604800),
    ]);
    $topology = new TopologyManifestStore($store, $paths)->active('NCK-123');
    expect($topology)->not->toBeNull();
    $target = $topology->target;
    $operation = str_repeat('b', 32);
    $commands = [];
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$commands,
        $realProcess,
        $repositoryRoot,
        $topology,
        $target,
        $operation,
    ) {
        if (($process->command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->input($process->input)->run($process->command);
        }
        if (($batch = pinnedWorktreeBatchResult($process)) !== null) {
            return $batch;
        }
        $commands[] = $process->command;
        if (($process->command[3] ?? null) === 'list') {
            return Process::result(json_encode(array_map(
                static fn (string $role): array => [
                    'name' => $target->instance($role),
                    'type' => 'virtual-machine',
                    'status' => 'Running',
                    'status_code' => 103,
                    'config' => [
                        'user.orbit.e2e.owner' => 'orbit-e2e',
                        'user.orbit.e2e.issue' => $target->issue,
                        'user.orbit.e2e.attempt' => attemptId()->value,
                        'user.orbit.e2e.generation' => $topology->generation->id,
                        'user.orbit.e2e.operation' => $operation,
                    ],
                    'devices' => [
                        'root' => ['pool' => 'default'],
                        'eth0' => ['network' => $target->network(), 'hwaddr' => $target->mac($role)],
                    ],
                ],
                \App\E2E\Value\TopologyProfile::ROLES,
            ), JSON_THROW_ON_ERROR));
        }
        if (($process->command[3] ?? null) === 'network' && ($process->command[4] ?? null) === 'list') {
            return Process::result(json_encode([[
                'name' => $target->network(),
                'config' => [
                    'user.orbit.e2e.owner' => 'orbit-e2e',
                    'user.orbit.e2e.issue' => $target->issue,
                    'user.orbit.e2e.attempt' => attemptId()->value,
                    'user.orbit.e2e.operation' => $operation,
                ],
            ]], JSON_THROW_ON_ERROR));
        }

        return Process::result();
    });

    $topology = taskNineAcquirer($repositoryRoot, $paths)->acquireDiscovery(
        new TopologyRequest('NCK-123', $repositoryRoot),
    );

    expect($topology->target->issue)
        ->toBe('NCK-123')
        ->and(new AtomicJsonStore($paths)->read('leases/NCK-123.json')['state'])
        ->toBe('ready')
        ->and(collect($commands)->contains(
            static fn (array $command): bool => (
                array_intersect(
                    $command,
                    ['copy', 'create', 'start', 'stop', 'delete', 'exec'],
                ) !== []
            ),
        ))
        ->toBeFalse();
});

it('refuses manifest-backed acquisition recovery when exact live identity drifted', function () {
    $repositoryRoot = preparedTopologyRepository();
    $realProcess = new ProcessFactory;

    foreach ([
        'missing-instance',
        'stopped-instance',
        'instance-issue',
        'instance-generation',
        'instance-operation',
        'instance-network',
        'instance-mac',
        'instance-pool',
        'network-issue',
        'network-operation',
    ] as $mismatch) {
        $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
        featureTopologyFixture($repositoryRoot, $paths);
        $store = new AtomicJsonStore($paths);
        $operation = str_repeat('b', 32);
        $lease = [
            'schema' => 2,
            'issue' => 'NCK-123',
            'attempt' => attemptId()->value,
            'state' => 'acquiring',
            'operation_id' => $operation,
            'pid' => 999999,
            'process_start_identity' => 'dead-test-owner',
            'acquired_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() + 604800),
        ];
        $store->write('leases/NCK-123.json', $lease);
        $topology = new TopologyManifestStore($store, $paths)->active('NCK-123');
        expect($topology)->not->toBeNull();
        $target = $topology->target;
        $commands = [];

        Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
            &$commands,
            $realProcess,
            $repositoryRoot,
            $topology,
            $target,
            $operation,
            $mismatch,
        ) {
            if (($process->command[0] ?? null) === 'git') {
                return $realProcess->path($repositoryRoot)->input($process->input)->run($process->command);
            }
            $commands[] = $process->command;
            if (($process->command[3] ?? null) === 'list') {
                $roles = \App\E2E\Value\TopologyProfile::ROLES;
                if ($mismatch === 'missing-instance') {
                    array_pop($roles);
                }

                return Process::result(json_encode(array_map(
                    static function (string $role) use ($target, $topology, $operation, $mismatch): array {
                        $metadata = [
                            'user.orbit.e2e.owner' => 'orbit-e2e',
                            'user.orbit.e2e.issue' => $target->issue,
                            'user.orbit.e2e.attempt' => attemptId()->value,
                            'user.orbit.e2e.generation' => $topology->generation->id,
                            'user.orbit.e2e.operation' => $operation,
                        ];
                        $network = $target->network();
                        $mac = $target->mac($role);
                        $pool = 'default';
                        $status = 'Running';
                        $statusCode = 103;
                        if ($role === 'gateway') {
                            match ($mismatch) {
                                'stopped-instance' => [$status, $statusCode] = ['Stopped', 102],
                                'instance-issue' => $metadata['user.orbit.e2e.issue'] = 'NCK-999',
                                'instance-generation' => $metadata['user.orbit.e2e.generation'] = 'foreign',
                                'instance-operation' => $metadata['user.orbit.e2e.operation'] = str_repeat('c', 32),
                                'instance-network' => $network = 'oe-foreign',
                                'instance-mac' => $mac = '00:16:3e:00:00:00',
                                'instance-pool' => $pool = 'foreign',
                                default => null,
                            };
                        }

                        return [
                            'name' => $target->instance($role),
                            'type' => 'virtual-machine',
                            'status' => $status,
                            'status_code' => $statusCode,
                            'config' => $metadata,
                            'devices' => [
                                'root' => ['pool' => $pool],
                                'eth0' => ['network' => $network, 'hwaddr' => $mac],
                            ],
                        ];
                    },
                    $roles,
                ), JSON_THROW_ON_ERROR));
            }
            if (($process->command[3] ?? null) === 'network' && ($process->command[4] ?? null) === 'list') {
                return Process::result(json_encode([[
                    'name' => $target->network(),
                    'config' => [
                        'user.orbit.e2e.owner' => 'orbit-e2e',
                        'user.orbit.e2e.issue' => $mismatch === 'network-issue' ? 'NCK-999' : $target->issue,
                        'user.orbit.e2e.operation' => $mismatch === 'network-operation'
                            ? str_repeat('c', 32)
                            : $operation,
                    ],
                ]], JSON_THROW_ON_ERROR));
            }

            return Process::result();
        });

        expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->acquireDiscovery(
            new TopologyRequest('NCK-123', $repositoryRoot),
        ))
            ->toThrow(RuntimeException::class);
        expect($store->read('leases/NCK-123.json'))
            ->toBe($lease)
            ->and(collect($commands)->contains(
                static fn (array $command): bool => (
                    array_intersect(
                        $command,
                        ['copy', 'create', 'start', 'stop', 'delete', 'exec'],
                    ) !== []
                ),
            ))
            ->toBeFalse();
    }
});

/** @mago-expect lint:cyclomatic-complexity The scenario tracks every interrupted acquisition cleanup branch. */
it('cleans an interrupted no-manifest acquisition before starting a new operation', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $store = new AtomicJsonStore($paths);
    $prepared = topologyFinalPreparedFingerprint($repositoryRoot);
    $structural = new PreparedStateFingerprint(new GitRepository($repositoryRoot))->forCommit();
    new StandbyManifestStore($store, $paths, new TopologyManifestStore($store, $paths))->promote(new StandbyGeneration(
        preparedGenerationId($repositoryRoot, $prepared->value),
        new GitRepository($repositoryRoot)->commit(),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $prepared->value,
        str_repeat('a', 64),
        topologyPromotedLaravel(),
        $structural->value,
        $structural->manifest['schema'],
        $structural->manifest['cold_epoch'],
        $structural->manifest['base_image_alias'],
        $structural->manifest['topology']['profile'],
        $structural->manifest['topology']['roles'],
        $structural->manifest['topology']['checkout_roles'],
    ));
    $oldOperation = str_repeat('b', 32);
    $store->write('leases/NCK-123.json', [
        'schema' => 2,
        'issue' => 'NCK-123',
        'attempt' => attemptId()->value,
        'state' => 'acquiring',
        'operation_id' => $oldOperation,
        'pid' => 999999,
        'process_start_identity' => 'dead-test-owner',
        'acquired_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() + 604800),
    ]);
    $target = featureTarget('NCK-123');
    $operationId = $oldOperation;
    $commands = [];
    $realProcess = new ProcessFactory;
    /** @mago-expect lint:cyclomatic-complexity The process fake models exact interruption commands. */
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$commands,
        &$operationId,
        $realProcess,
        $repositoryRoot,
        $target,
        $oldOperation,
    ) {
        if (($process->command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->input($process->input)->run($process->command);
        }
        foreach ($process->command as $argument) {
            if (preg_match('/\Auser\.orbit\.e2e\.operation=([0-9a-f]{32})\z/', (string) $argument, $matches)) {
                $operationId = $matches[1];
            }
        }
        $commands[] = $process->command;
        if (in_array('image', $process->command, true) && in_array('list', $process->command, true)) {
            return Process::result(preparedBaseImageJson(str_repeat('a', 64)));
        }
        if (in_array('snapshot', $process->command, true) && in_array('list', $process->command, true)) {
            $instance = preg_replace('/\\A[^:]+:/', '', (string) ($process->command[5] ?? ''));

            return Process::result(standbySnapshotInventoryJson($instance));
        }
        if (in_array('network', $process->command, true) && in_array('list', $process->command, true)) {
            return Process::result(json_encode([[
                'name' => $target->network(),
                'config' => [
                    'user.orbit.e2e.owner' => 'orbit-e2e',
                    'user.orbit.e2e.issue' => 'NCK-123',
                    'user.orbit.e2e.attempt' => attemptId()->value,
                    'user.orbit.e2e.operation' => $oldOperation,
                    'ipv4.address' => '10.232.2.1/24',
                ],
            ]], JSON_THROW_ON_ERROR));
        }
        if (in_array('list', $process->command, true)) {
            $instances = array_values(array_filter(
                json_decode(standbyVmInventoryJson(), true, 512, JSON_THROW_ON_ERROR),
                static fn (array $instance): bool => str_starts_with(
                    (string) ($instance['name'] ?? ''),
                    'orbit-e2e-standby-',
                ),
            ));
            foreach (TopologyProfile::ROLES as $role) {
                $resource = json_decode(
                    topologyVmJson(
                        $target->instance($role),
                        [
                            'user.orbit.e2e.owner' => 'orbit-e2e',
                            'user.orbit.e2e.issue' => 'NCK-123',
                            'user.orbit.e2e.attempt' => attemptId()->value,
                            'user.orbit.e2e.operation' => $oldOperation,
                        ],
                        $target->network(),
                    ),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                )[0];
                $instances[] = $resource;
            }

            return Process::result(json_encode($instances, JSON_THROW_ON_ERROR));
        }
        if (in_array('network', $process->command, true) && in_array('create', $process->command, true)) {
            return Process::result('', 'deliberate retry stop', 1);
        }

        return Process::result();
    });

    $rollbackMutations = [];
    $rollback = serialAcquisitionRollback(
        static function (string $resource) use ($target, &$operationId): IncusInstance|IncusNetwork {
            return (
                $resource === $target->network()
                    ? new IncusNetwork('local', 'default', $resource, [
                        'user.orbit.e2e.owner' => 'orbit-e2e',
                        'user.orbit.e2e.issue' => 'NCK-123',
                        'user.orbit.e2e.attempt' => attemptId()->value,
                        'user.orbit.e2e.operation' => $operationId,
                    ])
                    : new IncusInstance(
                        'local',
                        'default',
                        $resource,
                        'default',
                        [
                            'user.orbit.e2e.owner' => 'orbit-e2e',
                            'user.orbit.e2e.issue' => 'NCK-123',
                            'user.orbit.e2e.attempt' => attemptId()->value,
                            'user.orbit.e2e.operation' => $operationId,
                        ],
                        network: $target->network(),
                        mac: $target->mac(str_replace(
                            'orbit-e2e-nck-123-'.attemptId()->short().'-',
                            '',
                            $resource,
                        )),
                    )
            );
        },
        static function (string $resource) use (&$rollbackMutations): void {
            $rollbackMutations[] = 'stop:'.$resource;
        },
        static function (string $resource) use (&$rollbackMutations): void {
            $rollbackMutations[] = 'delete:'.$resource;
        },
        static function (string $resource) use (&$rollbackMutations): void {
            $rollbackMutations[] = 'network:'.$resource;
        },
    );
    expect(fn () => taskNineAcquirer($repositoryRoot, $paths, $rollback)->acquireDiscovery(new TopologyRequest(
        'NCK-123',
        $repositoryRoot,
    )))
        ->toThrow(RuntimeException::class, 'deliberate retry stop');
    $lease = $store->read('leases/NCK-123.json');
    expect($rollbackMutations)
        ->toBe([
            'delete:'.$target->instance('app-prod'),
            'delete:'.$target->instance('app-dev'),
            'delete:'.$target->instance('gateway'),
            'network:'.$target->network(),
        ])
        ->and($operationId)
        ->not
        ->toBe($oldOperation)
        ->and($lease)
        ->toBeNull()
        ->and(collect($commands)->contains(static fn (array $command): bool => in_array('*', $command, true)))
        ->toBeFalse();
});

it('refuses an unobservable interrupted acquisition without mutation', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $store = new AtomicJsonStore($paths);
    $operation = str_repeat('c', 32);
    $lease = [
        'schema' => 2,
        'issue' => 'NCK-123',
        'attempt' => attemptId()->value,
        'state' => 'acquiring',
        'operation_id' => $operation,
        'pid' => 999999,
        'process_start_identity' => 'dead-test-owner',
        'acquired_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() + 604800),
    ];
    $store->write('leases/NCK-123.json', $lease);
    $commands = [];
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$commands,
        $realProcess,
        $repositoryRoot,
    ) {
        $commands[] = $process->command;
        if (($process->command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->input($process->input)->run($process->command);
        }
        if (in_array('network', $process->command, true) && in_array('list', $process->command, true)) {
            return Process::result(json_encode([[
                'name' => 'orbit-e2e-nck-123',
                'config' => ['user.orbit.e2e.owner' => 'foreign', 'ipv4.address' => '10.232.2.1/24'],
            ]], JSON_THROW_ON_ERROR));
        }

        return Process::result();
    });
    $rollbackMutations = [];
    $acquirer = taskNineAcquirer($repositoryRoot, $paths, serialAcquisitionRollback(
        static fn (string $resource): IncusInstance|IncusNetwork => throw new RuntimeException('observation failed'),
        static function (string $resource) use (&$rollbackMutations): void {
            $rollbackMutations[] = 'stop:'.$resource;
        },
        static function (string $resource) use (&$rollbackMutations): void {
            $rollbackMutations[] = 'delete:'.$resource;
        },
        static function (string $resource) use (&$rollbackMutations): void {
            $rollbackMutations[] = 'network:'.$resource;
        },
    ));
    foreach (range(1, 2) as $_) {
        expect(fn () => $acquirer->acquireDiscovery(new TopologyRequest('NCK-123', $repositoryRoot)))
            ->toThrow(RuntimeException::class, 'cleanup was refused');
    }
    expect($store->read('leases/NCK-123.json'))
        ->toBe($lease)
        ->and($rollbackMutations)
        ->toBeEmpty()
        ->and(collect($commands)->contains(
            static fn (array $command): bool => (
                in_array('create', $command, true)
                || in_array('delete', $command, true)
                || in_array('stop', $command, true)
            ),
        ))
        ->toBeFalse();
});

it('refuses a drifted interrupted acquisition without mutation on repeated retries', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $store = new AtomicJsonStore($paths);
    $lease = [
        'schema' => 2,
        'issue' => 'NCK-123',
        'attempt' => attemptId()->value,
        'state' => 'acquiring',
        'operation_id' => str_repeat('d', 32),
        'pid' => 999999,
        'process_start_identity' => 'dead-test-owner',
        'acquired_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() + 604800),
    ];
    $store->write('leases/NCK-123.json', $lease);
    $mutations = [];
    $rollback = serialAcquisitionRollback(
        static fn (string $resource): IncusInstance|IncusNetwork => (
            $resource === 'orbit-e2e-nck-123'
                ? new IncusNetwork('local', 'default', $resource, [
                    'user.orbit.e2e.owner' => 'orbit-e2e',
                    'user.orbit.e2e.issue' => 'NCK-999',
                    'user.orbit.e2e.operation' => str_repeat('d', 32),
                ])
                : new IncusInstance('local', 'default', $resource, 'default', [
                    'user.orbit.e2e.owner' => 'orbit-e2e',
                    'user.orbit.e2e.issue' => 'NCK-999',
                    'user.orbit.e2e.operation' => str_repeat('d', 32),
                ])
        ),
        static function (string $resource) use (&$mutations): void {
            $mutations[] = 'stop:'.$resource;
        },
        static function (string $resource) use (&$mutations): void {
            $mutations[] = 'delete:'.$resource;
        },
        static function (string $resource) use (&$mutations): void {
            $mutations[] = 'network:'.$resource;
        },
    );
    $processMutations = [];
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$processMutations,
        $realProcess,
        $repositoryRoot,
    ) {
        $processMutations[] = $process->command;

        return ($process->command[0] ?? null) === 'git'
            ? $realProcess->path($repositoryRoot)->input($process->input)->run($process->command)
            : Process::result();
    });
    $acquirer = taskNineAcquirer($repositoryRoot, $paths, $rollback);
    foreach (range(1, 2) as $_) {
        expect(fn () => $acquirer->acquireDiscovery(new TopologyRequest('NCK-123', $repositoryRoot)))
            ->toThrow(RuntimeException::class, 'cleanup was refused');
    }
    expect($store->read('leases/NCK-123.json'))
        ->toBe($lease)
        ->and($mutations)
        ->toBeEmpty()
        ->and(collect($processMutations)->contains(
            static fn (array $command): bool => (
                in_array('create', $command, true)
                || in_array('delete', $command, true)
                || in_array('stop', $command, true)
            ),
        ))
        ->toBeFalse();
});

it('fails closed for a malformed acquiring lease', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $store = new AtomicJsonStore($paths);
    $store->write('leases/NCK-123.json', [
        'schema' => 2,
        'issue' => 'NCK-123',
        'attempt' => attemptId()->value,
        'state' => 'acquiring',
        'operation_id' => 'not-an-operation-id',
    ]);

    expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->acquireDiscovery(
        new TopologyRequest('NCK-123', $repositoryRoot),
    ))
        ->toThrow(RuntimeException::class, 'acquiring lease is invalid');
});

it('fails closed when an acquiring lease has invalid expiry or extra fields', function (array $lease) {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    new AtomicJsonStore($paths)->write('leases/NCK-123.json', $lease);

    expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->acquireDiscovery(
        new TopologyRequest('NCK-123', $repositoryRoot),
    ))
        ->toThrow(RuntimeException::class, 'acquiring lease is invalid');
})->with([
    [[
        'schema' => 2,
        'issue' => 'NCK-123',
        'attempt' => attemptId()->value,
        'state' => 'acquiring',
        'operation_id' => str_repeat('a', 32),
        'expires_at' => 'invalid',
    ]],
    [[
        'schema' => 2,
        'issue' => 'NCK-123',
        'attempt' => attemptId()->value,
        'state' => 'acquiring',
        'operation_id' => str_repeat('a', 32),
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
        'unexpected' => true,
    ]],
    [[
        'schema' => 2,
        'issue' => 'NCK-123',
        'attempt' => attemptId()->value,
        'state' => 'acquiring',
        'operation_id' => str_repeat('a', 32),
        'expires_at' => '2026-99-99T99:99:99Z',
    ]],
]);

it('keeps ready topology acquisition rejection unchanged', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    featureTopologyFixture($repositoryRoot, $paths);

    expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->acquireDiscovery(
        new TopologyRequest('NCK-123', $repositoryRoot),
    ))
        ->toThrow(RuntimeException::class, 'already has a topology manifest');
});

it('recovers a manifest when acquiring lease keys use a different JSON order', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    featureTopologyFixture($repositoryRoot, $paths);
    $leasePath = $paths->path('leases/NCK-123.json');
    file_put_contents($leasePath, json_encode([
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() + 604800),
        'operation_id' => str_repeat('b', 32),
        'pid' => 999999,
        'process_start_identity' => 'dead-test-owner',
        'acquired_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
        'state' => 'acquiring',
        'issue' => 'NCK-123',
        'attempt' => attemptId()->value,
        'schema' => 2,
    ], JSON_THROW_ON_ERROR));

    $target = featureTarget('NCK-123');
    $topology = new TopologyManifestStore(new AtomicJsonStore($paths), $paths)->active('NCK-123');
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        $target,
        $topology,
        $realProcess,
        $repositoryRoot,
    ): \Illuminate\Contracts\Process\ProcessResult {
        if (($process->command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->input($process->input)->run($process->command);
        }
        if (($process->command[3] ?? null) === 'list') {
            $names = array_map($target->instance(...), TopologyProfile::ROLES);

            return Process::result(json_encode(array_map(static fn (string $name): array => [
                'name' => $name,
                'type' => 'virtual-machine',
                'status' => 'Running',
                'status_code' => 103,
                'config' => [
                    'user.orbit.e2e.owner' => 'orbit-e2e',
                    'user.orbit.e2e.issue' => 'NCK-123',
                    'user.orbit.e2e.attempt' => attemptId()->value,
                    'user.orbit.e2e.generation' => $topology->generation->id,
                    'user.orbit.e2e.operation' => str_repeat('b', 32),
                ],
                'devices' => [
                    'root' => ['pool' => 'default'],
                    'eth0' => [
                        'network' => $target->network(),
                        'hwaddr' => $target->mac(
                            str_ends_with($name, 'gateway')
                                ? 'gateway'
                                : (str_ends_with($name, 'app-dev') ? 'app-dev' : 'app-prod'),
                        ),
                    ],
                ],
            ], $names), JSON_THROW_ON_ERROR));
        }
        if (($process->command[3] ?? null) === 'network') {
            return Process::result(json_encode([[
                'name' => $target->network(),
                'config' => [
                    'user.orbit.e2e.owner' => 'orbit-e2e',
                    'user.orbit.e2e.issue' => 'NCK-123',
                    'user.orbit.e2e.attempt' => attemptId()->value,
                    'user.orbit.e2e.operation' => str_repeat('b', 32),
                ],
            ]], JSON_THROW_ON_ERROR));
        }

        return Process::result();
    });

    expect(
        taskNineAcquirer($repositoryRoot, $paths)
            ->acquireDiscovery(
                new TopologyRequest('NCK-123', $repositoryRoot),
            )
            ->target
            ->issue,
    )
        ->toBe('NCK-123');
});

it('preflights every rollback target before any deletion', function () {
    $read = [];
    $mutations = [];
    $rollback = serialAcquisitionRollback(
        function (string $resource) use (&$read): IncusInstance|IncusNetwork|null {
            $read[] = $resource;

            return new IncusInstance('lab', 'orbit', $resource, 'orbit-e2e', [
                'user.orbit.e2e.owner' => 'orbit-e2e',
                'user.orbit.e2e.issue' => 'NCK-123',
                'user.orbit.e2e.attempt' => attemptId()->value,
                'user.orbit.e2e.operation' => str_repeat('a', 32),
            ]);
        },
        function (string $resource) use (&$mutations): void {
            $mutations[] = 'stop:'.$resource;
        },
        function (string $resource) use (&$mutations): void {
            $mutations[] = 'delete:'.$resource;
        },
        function (string $resource) use (&$mutations): void {
            $mutations[] = 'network:'.$resource;
        },
    );
    $target = featureTarget('NCK-123');
    $identity = static fn (string $name): array => [
        'remote' => 'lab',
        'project' => 'orbit',
        'name' => $name,
        'pool' => 'orbit-e2e',
        'metadata' => [
            'user.orbit.e2e.owner' => 'orbit-e2e',
            'user.orbit.e2e.issue' => 'NCK-123',
            'user.orbit.e2e.attempt' => attemptId()->value,
            'user.orbit.e2e.operation' => str_repeat('a', 32),
        ],
    ];

    $result = $rollback->cleanup(
        $target,
        ['orbit-e2e-nck-123-aaaaaaaa-gateway', 'orbit-e2e-nck-123-aaaaaaaa-app-dev'],
        [
            'orbit-e2e-nck-123-aaaaaaaa-gateway' => $identity('orbit-e2e-nck-123-aaaaaaaa-gateway'),
            'orbit-e2e-nck-123-aaaaaaaa-app-dev' => ['remote' => 'lab'],
        ],
        new OperationId(str_repeat('a', 32)),
    );

    expect($result['orbit-e2e-nck-123-aaaaaaaa-gateway'])
        ->toBe('retained_due_to_preflight_failure')
        ->and($mutations)
        ->toBeEmpty()
        ->and($read)
        ->toBe(['orbit-e2e-nck-123-aaaaaaaa-gateway', 'orbit-e2e-nck-123-aaaaaaaa-app-dev']);
});

it('uses the preflight snapshot once before rollback mutation', function () {
    $reads = 0;
    $mutations = [];
    $rollback = serialAcquisitionRollback(
        function (string $resource) use (&$reads): IncusInstance {
            $reads++;
            $metadata = $reads === 2
                ? ['user.orbit.e2e.owner' => 'replacement']
                : [
                    'user.orbit.e2e.owner' => 'orbit-e2e',
                    'user.orbit.e2e.issue' => 'NCK-123',
                    'user.orbit.e2e.attempt' => attemptId()->value,
                    'user.orbit.e2e.operation' => str_repeat('a', 32),
                ];

            return new IncusInstance('lab', 'orbit', $resource, 'orbit-e2e', $metadata);
        },
        function (string $resource) use (&$mutations): void {
            $mutations[] = 'stop:'.$resource;
        },
        function (string $resource) use (&$mutations): void {
            $mutations[] = 'delete:'.$resource;
        },
        function (string $resource) use (&$mutations): void {
            $mutations[] = 'network:'.$resource;
        },
    );
    $target = featureTarget('NCK-123');
    $identity = [
        'remote' => 'lab',
        'project' => 'orbit',
        'name' => 'orbit-e2e-nck-123-aaaaaaaa-gateway',
        'pool' => 'orbit-e2e',
        'metadata' => [
            'user.orbit.e2e.owner' => 'orbit-e2e',
            'user.orbit.e2e.issue' => 'NCK-123',
            'user.orbit.e2e.attempt' => attemptId()->value,
            'user.orbit.e2e.operation' => str_repeat('a', 32),
        ],
    ];

    $result = $rollback->cleanup(
        $target,
        ['orbit-e2e-nck-123-aaaaaaaa-gateway'],
        ['orbit-e2e-nck-123-aaaaaaaa-gateway' => $identity],
        new OperationId(str_repeat('a', 32)),
    );

    expect($result['orbit-e2e-nck-123-aaaaaaaa-gateway'])
        ->toBe('removed')
        ->and($reads)
        ->toBe(1)
        ->and($mutations)
        ->toBe([
            'delete:orbit-e2e-nck-123-aaaaaaaa-gateway',
        ]);
});

it('uses the acquisition rollback after a topology creation failure', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $store = new AtomicJsonStore($paths);
    $fingerprints = new PreparedStateFingerprint(new GitRepository($repositoryRoot));
    $prepared = topologyFinalPreparedFingerprint($repositoryRoot);
    $structural = $fingerprints->forCommit();
    $generation = new StandbyGeneration(
        preparedGenerationId($repositoryRoot, $prepared->value),
        new GitRepository($repositoryRoot)->commit(),
        [
            'gateway' => 'main-gateway',
            'app-dev' => 'main-app-dev',
            'app-prod' => 'main-app-prod',
        ],
        $prepared->value,
        str_repeat('a', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
        $structural->value,
        $structural->manifest['schema'],
        $structural->manifest['cold_epoch'],
        $structural->manifest['base_image_alias'],
        $structural->manifest['topology']['profile'],
        $structural->manifest['topology']['roles'],
        $structural->manifest['topology']['checkout_roles'],
    );
    new StandbyManifestStore($store, $paths, new TopologyManifestStore($store, $paths))->promote($generation);
    $reads = [];
    $rollback = serialAcquisitionRollback(
        function (string $resource) use (&$reads): never {
            $reads[] = $resource;

            throw new RuntimeException('rollback boundary used');
        },
        static function (): void {},
        static function (): void {},
        static function (): void {},
    );
    $target = featureTarget('NCK-123');
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use ($target, $repositoryRoot, $realProcess) {
        return topology_creation_failure_process_result($process, $target, $repositoryRoot, $realProcess);
    });
    $acquirer = taskNineAcquirer($repositoryRoot, $paths, $rollback);
    $request = new TopologyRequest('NCK-123', $repositoryRoot);

    expect(fn () => $acquirer->acquireDiscovery($request))
        ->toThrow(RuntimeException::class, 'copy failed')
        ->and($reads)
        ->toBe([$target->network()])
        ->and(new AtomicJsonStore($paths)->read('leases/NCK-123.json')['state'] ?? null)
        ->toBe('acquiring');
});

it('rolls back without parsing a manifest when the ready lease write fails', function () {
    $repositoryRoot = preparedTopologyRepository();
    $worktree = pinnedFeatureWorktree($repositoryRoot, 'ready-lease-failure');
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $leaseWrites = 0;
    $store = new AtomicJsonStore(
        $paths,
        failure: function (string $phase, string $temporary, string $file) use (&$leaseWrites, $paths): void {
            if ($phase !== 'before_rename' || ! str_ends_with($file, '/leases/NCK-123.json')) {
                return;
            }

            $leaseWrites++;
            if ($leaseWrites !== 2) {
                return;
            }

            file_put_contents($paths->path('topologies/NCK-123/'.attemptId()->value.'.json'), '{malformed');

            throw new RuntimeException('injected ready lease failure');
        },
    );
    $prepared = topologyFinalPreparedFingerprint($repositoryRoot);
    $structural = new PreparedStateFingerprint(new GitRepository($repositoryRoot))->forCommit();
    $mainSha = new GitRepository($repositoryRoot)->commit();
    new StandbyManifestStore($store, $paths, new TopologyManifestStore($store, $paths))->promote(new StandbyGeneration(
        preparedGenerationId($repositoryRoot, $prepared->value),
        $mainSha,
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $prepared->value,
        str_repeat('b', 64),
        topologyPromotedLaravel(),
        $structural->value,
        $structural->manifest['schema'],
        $structural->manifest['cold_epoch'],
        $structural->manifest['base_image_alias'],
        $structural->manifest['topology']['profile'],
        $structural->manifest['topology']['roles'],
        $structural->manifest['topology']['checkout_roles'],
    ));
    $rollbackReads = [];
    $rollback = serialAcquisitionRollback(
        function (string $resource) use (&$rollbackReads): null {
            $rollbackReads[] = $resource;

            return null;
        },
        static function (): void {},
        static function (): void {},
        static function (): void {},
    );
    $events = [];
    fakePinnedWorktreeProcesses(featureTarget('NCK-123'), $events);

    try {
        expect(fn () => taskNineAcquirer(
            $repositoryRoot,
            $paths,
            $rollback,
            store: $store,
        )->acquireDiscovery(new TopologyRequest('NCK-123', $worktree)))
            ->toThrow(RuntimeException::class, 'injected ready lease failure')
            ->and($rollbackReads)
            ->toBe([
                featureTarget('NCK-123')->network(),
                featureTarget('NCK-123')->instance('gateway'),
                featureTarget('NCK-123')->instance('app-dev'),
                featureTarget('NCK-123')->instance('app-prod'),
            ])
            ->and(file_exists($paths->path('topologies/NCK-123/'.attemptId()->value.'.json')))
            ->toBeFalse()
            ->and($store->read('failures/NCK-123.json')['error'] ?? null)
            ->toBe('injected ready lease failure');
    } finally {
        Process::run(['git', '-C', $repositoryRoot, 'worktree', 'remove', '--force', $worktree]);
    }
});

it('surfaces a manifest deletion failure and retains the recovery state', function () {
    [$repositoryRoot, $worktree, $paths, $store] = topologyAcquisitionBoundaryFixture(
        static function (string $phase, string $temporary, string $file, StatePaths $paths, stdClass $fault): void {
            if (! $fault->ready_lease || ! str_ends_with($file, '/leases/NCK-123.json')) {
                return;
            }

            $manifest = $paths->path('topologies/NCK-123/'.attemptId()->value.'.json');
            unlink($manifest);
            mkdir($manifest, 0700);
        },
    );
    $reads = [];
    $events = [];
    fakePinnedWorktreeProcesses(featureTarget('NCK-123'), $events);

    try {
        $failure = capturedTopologyAcquisitionFailure(fn () => taskNineAcquirer(
            $repositoryRoot,
            $paths,
            absentAcquisitionRollback($reads),
            store: $store,
        )->acquireDiscovery(new TopologyRequest('NCK-123', $worktree)));
        $evidence = $store->read('failures/NCK-123.json');

        expect($failure)
            ->toBeInstanceOf(RuntimeException::class)
            ->and($failure->getMessage())
            ->toContain('manifest deletion: The JSON state target is unsafe.')
            ->not
            ->toContain('primary-secret')
            ->and($failure->getPrevious()?->getMessage())
            ->toBe('primary acquisition Bearer primary-secret')
            ->and(is_dir($paths->path('topologies/NCK-123/'.attemptId()->value.'.json')))
            ->toBeTrue()
            ->and($store->read('leases/NCK-123.json')['state'] ?? null)
            ->toBe('acquiring')
            ->and($evidence['secondary_failures'] ?? null)
            ->toBe(['manifest deletion: The JSON state target is unsafe.']);
    } finally {
        Process::run(['git', '-C', $repositoryRoot, 'worktree', 'remove', '--force', $worktree]);
    }
});

/** @mago-expect lint:cyclomatic-complexity The scenario tracks recursive rollback and evidence branches. */
it('redacts recursive rollback evidence and deduplicates cleanup failures under exact authority', function () {
    [$repositoryRoot, $worktree, $paths, $store, $fault] = topologyAcquisitionBoundaryFixture(
        static function (): void {},
    );
    $target = featureTarget('NCK-123');
    $operationId = str_repeat('a', 32);
    $secret = bin2hex(random_bytes(16));
    $metadata = [
        'user.orbit.e2e.owner' => 'orbit-e2e',
        'user.orbit.e2e.issue' => 'NCK-123',
        'user.orbit.e2e.attempt' => attemptId()->value,
        'user.orbit.e2e.operation' => $operationId,
        'user.orbit.e2e.api_token' => $secret,
    ];
    $read = static function (string $resource) use ($target, &$operationId, $metadata): IncusInstance|IncusNetwork {
        $metadata['user.orbit.e2e.operation'] = $operationId;

        return $resource === $target->network()
            ? new IncusNetwork('local', 'default', $resource, $metadata)
            : new IncusInstance('local', 'default', $resource, 'default', $metadata);
    };
    $mutations = [];
    $rollback = serialAcquisitionRollback(
        $read,
        static function (string $resource) use (&$mutations): void {
            $mutations[] = 'stop:'.$resource;
        },
        static function (string $resource) use (&$mutations): void {
            $mutations[] = 'delete:'.$resource;
            throw new RuntimeException('Bearer cleanup-secret');
        },
        static function (string $resource) use (&$mutations): void {
            $mutations[] = 'network:'.$resource;
        },
    );
    $realProcess = new ProcessFactory;
    $events = [];
    /** @mago-expect lint:cyclomatic-complexity The process fake models exact rollback evidence commands. */
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        $realProcess,
        $target,
        $fault,
        $metadata,
        &$events,
        &$operationId,
    ) {
        $command = $process->command;
        if (($command[0] ?? null) === 'git') {
            return $realProcess
                ->path((string) ($process->path ?: getcwd()))
                ->input($process->input)
                ->run($command);
        }

        foreach ($command as $argument) {
            if (preg_match('/\\Auser\\.orbit\\.e2e\\.operation=([0-9a-f]{32})\\z/', (string) $argument, $matches)) {
                $operationId = $matches[1];
            }
        }
        $currentMetadata = $metadata;
        $currentMetadata['user.orbit.e2e.operation'] = $operationId;

        if (($batch = pinnedWorktreeBatchResult($process)) !== null) {
            return $batch;
        }
        $events[] = $command;
        if ($fault->ready_lease && ($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'list') {
            return Process::result(json_encode([[
                'name' => $target->network(),
                'config' => $currentMetadata,
            ]], JSON_THROW_ON_ERROR));
        }
        if ($fault->ready_lease && ($command[3] ?? null) === 'list') {
            if (($command[4] ?? null) === 'local:') {
                return Process::result(json_encode(array_map(
                    static fn (string $role): array => json_decode(
                        topologyVmJson($target->instance($role), $currentMetadata),
                        true,
                        16,
                        JSON_THROW_ON_ERROR,
                    )[0],
                    TopologyProfile::ROLES,
                ), JSON_THROW_ON_ERROR));
            }
            $name = preg_replace('/\A[^:]+:/', '', (string) ($command[4] ?? ''));

            return Process::result(topologyVmJson($name, $currentMetadata));
        }

        return pinnedWorktreeInventoryResult($command, $target) ?? pinnedWorktreeGuestResult($command);
    });
    $redactor = new SecretRedactor([$secret]);

    try {
        $failure = capturedTopologyAcquisitionFailure(fn () => taskNineAcquirer(
            $repositoryRoot,
            $paths,
            $rollback,
            store: $store,
            redactor: $redactor,
        )->acquireDiscovery(new TopologyRequest('NCK-123', $worktree)));
        $evidence = $store->read('failures/NCK-123.json');
        $resources = [
            $target->network(),
            $target->instance('gateway'),
            $target->instance('app-dev'),
            $target->instance('app-prod'),
        ];

        expect($failure->getPrevious()?->getMessage())
            ->toBe('primary acquisition Bearer primary-secret')
            ->and($failure->getMessage())
            ->toContain('resource cleanup result: failed:Bearer [REDACTED]')
            ->not
            ->toContain('cleanup-secret')
            ->and($mutations)
            ->toBe([
                'delete:'.$target->instance('app-prod'),
            ])
            ->and(array_keys($evidence['observed'] ?? []))
            ->toBe($resources)
            ->and($evidence['observed'][$target->instance('gateway')]['metadata']['user.orbit.e2e.api_token'] ?? null)
            ->toBe('[REDACTED]')
            ->and($evidence['secondary_failures'] ?? null)
            ->toBe([
                'resource cleanup result: failed:Bearer [REDACTED]',
                'resource cleanup result: retained_due_to_vm_delete_failure',
            ])
            ->and($store->read('leases/NCK-123.json')['state'] ?? null)
            ->toBe('acquiring')
            ->and(file_exists($paths->path('topologies/NCK-123/'.attemptId()->value.'.json')))
            ->toBeFalse();
    } finally {
        Process::run(['git', '-C', $repositoryRoot, 'worktree', 'remove', '--force', $worktree]);
    }
});

it('surfaces a failure evidence write failure with the acquisition as previous', function () {
    [$repositoryRoot, $worktree, $paths, $store] = topologyAcquisitionBoundaryFixture(
        static function (string $phase, string $temporary, string $file): void {
            if ($phase === 'before_rename' && str_ends_with($file, '/failures/NCK-123.json')) {
                throw new RuntimeException('Bearer evidence-secret');
            }
        },
    );
    $reads = [];
    $events = [];
    fakePinnedWorktreeProcesses(featureTarget('NCK-123'), $events);

    try {
        $failure = capturedTopologyAcquisitionFailure(fn () => taskNineAcquirer(
            $repositoryRoot,
            $paths,
            absentAcquisitionRollback($reads),
            store: $store,
        )->acquireDiscovery(new TopologyRequest('NCK-123', $worktree)));

        expect($failure->getMessage())
            ->toContain('failure evidence write: Bearer [REDACTED]')
            ->not
            ->toContain('evidence-secret')
            ->and($failure->getPrevious()?->getMessage())
            ->toBe('primary acquisition Bearer primary-secret')
            ->and($store->read('failures/NCK-123.json'))
            ->toBeNull()
            ->and($store->read('leases/NCK-123.json'))
            ->toBeNull()
            ->and(file_exists($paths->path('topologies/NCK-123/'.attemptId()->value.'.json')))
            ->toBeFalse();
    } finally {
        Process::run(['git', '-C', $repositoryRoot, 'worktree', 'remove', '--force', $worktree]);
    }
});

it('records and redacts a lease deletion failure in retained evidence', function () {
    [$repositoryRoot, $worktree, $paths, $store] = topologyAcquisitionBoundaryFixture(
        static function (string $phase, string $temporary, string $file, StatePaths $paths, stdClass $fault): void {
            if (! $fault->ready_lease || ! str_ends_with($file, '/leases/NCK-123.json')) {
                return;
            }

            unlink($paths->path('leases/NCK-123.json'));
            mkdir($paths->path('leases/NCK-123.json'), 0700);
        },
    );
    $reads = [];
    $events = [];
    fakePinnedWorktreeProcesses(featureTarget('NCK-123'), $events);

    try {
        $failure = capturedTopologyAcquisitionFailure(fn () => taskNineAcquirer(
            $repositoryRoot,
            $paths,
            absentAcquisitionRollback($reads),
            store: $store,
        )->acquireDiscovery(new TopologyRequest('NCK-123', $worktree)));
        $evidence = $store->read('failures/NCK-123.json');

        expect($failure->getMessage())
            ->toContain('lease deletion: The JSON state target is unsafe.')
            ->not
            ->toContain('primary-secret')
            ->and($failure->getPrevious()?->getMessage())
            ->toBe('primary acquisition Bearer primary-secret')
            ->and($evidence['secondary_failures'] ?? null)
            ->toBe(['lease deletion: The JSON state target is unsafe.'])
            ->and(is_dir($paths->path('leases/NCK-123.json')))
            ->toBeTrue()
            ->and(file_exists($paths->path('topologies/NCK-123/'.attemptId()->value.'.json')))
            ->toBeFalse();
    } finally {
        Process::run(['git', '-C', $repositoryRoot, 'worktree', 'remove', '--force', $worktree]);
    }
});

it('reports multiple distinct secondary failures once and keeps cleanup recovery state', function () {
    [$repositoryRoot, $worktree, $paths, $store] = topologyAcquisitionBoundaryFixture(
        static function (string $phase, string $temporary, string $file, StatePaths $paths, stdClass $fault): void {
            if (! $fault->ready_lease || ! str_ends_with($file, '/leases/NCK-123.json')) {
                return;
            }

            $manifest = $paths->path('topologies/NCK-123/'.attemptId()->value.'.json');
            unlink($manifest);
            mkdir($manifest, 0700);
        },
    );
    $target = featureTarget('NCK-123');
    $reads = [];
    $rollback = serialAcquisitionRollback(
        function (string $resource) use (&$reads): never {
            $reads[] = $resource;

            throw new RuntimeException('Bearer repeated-cleanup-secret');
        },
        static function (): void {},
        static function (): void {},
        static function (): void {},
    );
    $events = [];
    fakePinnedWorktreeProcesses($target, $events);

    try {
        $failure = capturedTopologyAcquisitionFailure(fn () => taskNineAcquirer(
            $repositoryRoot,
            $paths,
            $rollback,
            store: $store,
        )->acquireDiscovery(new TopologyRequest('NCK-123', $worktree)));
        $evidence = $store->read('failures/NCK-123.json');
        $secondary = $evidence['secondary_failures'] ?? [];

        expect($failure->getPrevious()?->getMessage())
            ->toBe('primary acquisition Bearer primary-secret')
            ->and($failure->getMessage())
            ->toContain('manifest deletion: The JSON state target is unsafe.')
            ->toContain('resource cleanup result: failed:Bearer [REDACTED]')
            ->not
            ->toContain('repeated-cleanup-secret')
            ->and($secondary)
            ->toBe([
                'manifest deletion: The JSON state target is unsafe.',
                'resource cleanup result: failed:Bearer [REDACTED]',
            ])
            ->and(substr_count($failure->getMessage(), 'resource cleanup result:'))
            ->toBe(1)
            ->and($reads)
            ->toBe([
                $target->network(),
            ])
            ->and(is_dir($paths->path('topologies/NCK-123/'.attemptId()->value.'.json')))
            ->toBeTrue()
            ->and($store->read('leases/NCK-123.json')['state'] ?? null)
            ->toBe('acquiring');
    } finally {
        Process::run(['git', '-C', $repositoryRoot, 'worktree', 'remove', '--force', $worktree]);
    }
});

it('preflights all standby snapshots before any network or copy mutation', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $store = new AtomicJsonStore($paths);
    $fingerprints = new PreparedStateFingerprint(new GitRepository($repositoryRoot));
    $prepared = topologyFinalPreparedFingerprint($repositoryRoot);
    $structural = $fingerprints->forCommit();
    $generation = new StandbyGeneration(
        preparedGenerationId($repositoryRoot, $prepared->value),
        new GitRepository($repositoryRoot)->commit(),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $prepared->value,
        str_repeat('a', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
        $structural->value,
        $structural->manifest['schema'],
        $structural->manifest['cold_epoch'],
        $structural->manifest['base_image_alias'],
        $structural->manifest['topology']['profile'],
        $structural->manifest['topology']['roles'],
        $structural->manifest['topology']['checkout_roles'],
    );
    new StandbyManifestStore($store, $paths, new TopologyManifestStore($store, $paths))->promote($generation);
    $commands = [];
    $acquirer = taskNineAcquirer($repositoryRoot, $paths);
    $request = new TopologyRequest('NCK-123', $repositoryRoot);
    $realProcess = new ProcessFactory;

    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$commands,
        $repositoryRoot,
        $realProcess,
    ) {
        $commands[] = $process->command;

        if (($process->command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->input($process->input)->run($process->command);
        }
        if (in_array('image', $process->command, true) && in_array('list', $process->command, true)) {
            return Process::result(preparedBaseImageJson(str_repeat('a', 64)));
        }

        if (in_array('snapshot', $process->command, true) && in_array('list', $process->command, true)) {
            $instance = preg_replace('/\A[^:]+:/', '', (string) ($process->command[5] ?? ''));
            $include = $instance !== TopologyTarget::standby()->instance('app-prod');

            return Process::result(standbySnapshotInventoryJson($instance, include: $include));
        }

        if (in_array('list', $process->command, true)) {
            return Process::result(standbyVmInventoryJson());
        }

        return Process::result();
    });

    expect(fn () => $acquirer->acquireDiscovery($request))
        ->toThrow(RuntimeException::class, 'snapshots do not exist');

    expect(collect($commands)->contains(
        fn (array $command): bool => in_array('network', $command, true) && in_array('create', $command, true),
    ))
        ->toBeFalse()
        ->and(collect($commands)->contains(fn (array $command): bool => in_array('copy', $command, true)))
        ->toBeFalse();
});

it('blocks acquisition when the standby is marked corrupt before Incus mutation', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $store = new AtomicJsonStore($paths);
    $prepared = topologyFinalPreparedFingerprint($repositoryRoot);
    $structural = new PreparedStateFingerprint(new GitRepository($repositoryRoot))->forCommit();
    new StandbyManifestStore($store, $paths, new TopologyManifestStore($store, $paths))->promote(new StandbyGeneration(
        preparedGenerationId($repositoryRoot, $prepared->value),
        new GitRepository($repositoryRoot)->commit(),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $prepared->value,
        str_repeat('a', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
        $structural->value,
        $structural->manifest['schema'],
        $structural->manifest['cold_epoch'],
        $structural->manifest['base_image_alias'],
        $structural->manifest['topology']['profile'],
        $structural->manifest['topology']['roles'],
        $structural->manifest['topology']['checkout_roles'],
    ));
    $store->write('standby/corrupt.json', [
        'schema' => 1,
        'evidence_id' => str_repeat('b', 32),
        'message' => 'rollback failed',
    ]);
    $commands = [];
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$commands,
        $repositoryRoot,
        $realProcess,
    ) {
        if (($process->command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->input($process->input)->run($process->command);
        }

        $commands[] = $process->command;

        return Process::result();
    });

    expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->acquireDiscovery(
        new TopologyRequest('NCK-123', $repositoryRoot),
    ))
        ->toThrow(RuntimeException::class, 'marked corrupt')
        ->and($commands)
        ->toBeEmpty();
});

it('refuses acquisition while a schema 1 topology manifest exists', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $store = new AtomicJsonStore($paths);
    $store->write('topologies/NCK-123.json', ['schema' => 1, 'issue' => 'NCK-123']);
    $commands = [];
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$commands,
        $repositoryRoot,
        $realProcess,
    ) {
        if (($process->command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->input($process->input)->run($process->command);
        }

        $commands[] = $process->command;

        return Process::result();
    });

    expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->acquireDiscovery(
        new TopologyRequest('NCK-123', $repositoryRoot),
    ))
        ->toThrow(RuntimeException::class, 'schema 1 topology manifest')
        ->and($commands)
        ->toBeEmpty()
        ->and($store->read('topologies/NCK-123.json'))
        ->not->toBeNull();
});

it('blocks acquisition while an exact release still needs local finalization', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $store = new AtomicJsonStore($paths);
    $store->write('release-pending/NCK-123/'.attemptId('b')->value.'.json', ['schema' => 1]);
    $commands = [];
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$commands,
        $repositoryRoot,
        $realProcess,
    ) {
        if (($process->command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->input($process->input)->run($process->command);
        }

        $commands[] = $process->command;

        return Process::result();
    });

    expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->acquireDiscovery(
        new TopologyRequest('NCK-123', $repositoryRoot),
    ))
        ->toThrow(RuntimeException::class, 'pending release finalization')
        ->and($commands)
        ->toBeEmpty()
        ->and($store->read('release-pending/NCK-123/'.attemptId('b')->value.'.json'))
        ->not->toBeNull();
});

it('requires the promoted generation fingerprint to match its exact main commit', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $store = new AtomicJsonStore($paths);
    $prepared = topologyFinalPreparedFingerprint($repositoryRoot);
    $structural = new PreparedStateFingerprint(new GitRepository($repositoryRoot))->forCommit();
    new StandbyManifestStore($store, $paths, new TopologyManifestStore($store, $paths))->promote(new StandbyGeneration(
        preparedGenerationId($repositoryRoot, $prepared->value),
        new GitRepository($repositoryRoot)->commit(),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        str_repeat('c', 64),
        str_repeat('a', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
        $structural->value,
        $structural->manifest['schema'],
        $structural->manifest['cold_epoch'],
        $structural->manifest['base_image_alias'],
        $structural->manifest['topology']['profile'],
        $structural->manifest['topology']['roles'],
        $structural->manifest['topology']['checkout_roles'],
    ));
    $commands = [];
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$commands,
        $repositoryRoot,
        $realProcess,
    ) {
        if (($process->command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->input($process->input)->run($process->command);
        }

        $commands[] = $process->command;

        return Process::result();
    });

    $acquirer = taskNineAcquirer($repositoryRoot, $paths);
    expect(fn () => $acquirer->acquireDiscovery(new TopologyRequest('NCK-123', $repositoryRoot)))
        ->toThrow(RuntimeException::class, 'fingerprint is stale or corrupt')
        ->and($commands)
        ->toBeEmpty();
});

it('blocks acquisition when current main requires a newer prepared generation', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $store = new AtomicJsonStore($paths);
    $fingerprints = new PreparedStateFingerprint(new GitRepository($repositoryRoot));
    $prepared = topologyFinalPreparedFingerprint($repositoryRoot);
    $generationSha = new GitRepository($repositoryRoot)->commit();
    $structural = $fingerprints->forCommit($generationSha);
    new StandbyManifestStore($store, $paths, new TopologyManifestStore($store, $paths))->promote(new StandbyGeneration(
        preparedGenerationId($repositoryRoot, $prepared->value),
        $generationSha,
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $prepared->value,
        str_repeat('a', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
        $structural->value,
        $structural->manifest['schema'],
        $structural->manifest['cold_epoch'],
        $structural->manifest['base_image_alias'],
        $structural->manifest['topology']['profile'],
        $structural->manifest['topology']['roles'],
        $structural->manifest['topology']['checkout_roles'],
    ));
    file_put_contents($repositoryRoot.'/apps/e2e/resources/guest/converge-gateway.sh', "changed\n");
    foreach ([
        ['git', '-C', $repositoryRoot, 'add',    '.'],
        ['git', '-C', $repositoryRoot, 'commit', '-q', '-m',   'Prepared state changed'],
        ['git', '-C', $repositoryRoot, 'branch', '-f', 'main', 'HEAD'],
    ] as $command) {
        expect(Process::run($command)->successful())->toBeTrue();
    }
    $commands = [];
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$commands,
        $repositoryRoot,
        $realProcess,
    ) {
        if (($process->command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->input($process->input)->run($process->command);
        }

        $commands[] = $process->command;

        return Process::result();
    });

    $acquirer = taskNineAcquirer($repositoryRoot, $paths);
    expect(fn () => $acquirer->acquireDiscovery(new TopologyRequest('NCK-123', $repositoryRoot)))
        ->toThrow(RuntimeException::class, 'The promoted standby structural fingerprint is stale.')
        ->and($commands)
        ->toBeEmpty();
});

it('reuses a prepared generation when main advances with a source-only change', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $store = new AtomicJsonStore($paths);
    $fingerprints = new PreparedStateFingerprint(new GitRepository($repositoryRoot));
    $prepared = topologyFinalPreparedFingerprint($repositoryRoot);
    $generationSha = new GitRepository($repositoryRoot)->commit();
    $structural = $fingerprints->forCommit($generationSha);
    new StandbyManifestStore($store, $paths, new TopologyManifestStore($store, $paths))->promote(new StandbyGeneration(
        preparedGenerationId($repositoryRoot, $prepared->value),
        $generationSha,
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $prepared->value,
        str_repeat('a', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
        $structural->value,
        $structural->manifest['schema'],
        $structural->manifest['cold_epoch'],
        $structural->manifest['base_image_alias'],
        $structural->manifest['topology']['profile'],
        $structural->manifest['topology']['roles'],
        $structural->manifest['topology']['checkout_roles'],
    ));
    file_put_contents($repositoryRoot.'/README.md', "source-only\n");
    expect(Process::run(['git', '-C', $repositoryRoot, 'add', 'README.md'])->successful())->toBeTrue();
    expect(
        Process::run(['git', '-C', $repositoryRoot, 'commit', '-q', '-m', 'Source only'])->successful(),
    )->toBeTrue();
    expect(Process::run(['git', '-C', $repositoryRoot, 'branch', '-f', 'main', 'HEAD'])->successful())->toBeTrue();
    expect($prepared->value)->toBe(topologyFinalPreparedFingerprint($repositoryRoot, 'main')->value);
    $commands = [];
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$commands,
        $repositoryRoot,
        $realProcess,
    ) {
        if (($process->command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->input($process->input)->run($process->command);
        }
        $commands[] = $process->command;
        if (in_array('image', $process->command, true) && in_array('list', $process->command, true)) {
            return Process::result(preparedBaseImageJson(str_repeat('a', 64)));
        }
        if (in_array('snapshot', $process->command, true) && in_array('list', $process->command, true)) {
            $instance = preg_replace('/\A[^:]+:/', '', (string) ($process->command[5] ?? ''));

            return Process::result(standbySnapshotInventoryJson($instance));
        }
        if (in_array('list', $process->command, true)) {
            return Process::result(standbyVmInventoryJson());
        }
        if (in_array('network', $process->command, true) && in_array('create', $process->command, true)) {
            return Process::result('', 'controlled network failure', 1);
        }

        return Process::result();
    });
    expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->acquireDiscovery(
        new TopologyRequest('NCK-123', $repositoryRoot),
    ))
        ->toThrow(RuntimeException::class, 'controlled network failure');
    expect(collect($commands)->contains(
        fn (array $command): bool => in_array('network', $command, true) && in_array('create', $command, true),
    ))->toBeTrue();
});

/** @mago-expect lint:cyclomatic-complexity The integration case tracks the complete rollback state. */
it('rolls back an exactly owned network when host forwarding setup fails', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $store = new AtomicJsonStore($paths);
    $prepared = topologyFinalPreparedFingerprint($repositoryRoot);
    $structural = new PreparedStateFingerprint(new GitRepository($repositoryRoot))->forCommit();
    new StandbyManifestStore($store, $paths, new TopologyManifestStore($store, $paths))->promote(new StandbyGeneration(
        preparedGenerationId($repositoryRoot, $prepared->value),
        new GitRepository($repositoryRoot)->commit(),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $prepared->value,
        str_repeat('a', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
        $structural->value,
        $structural->manifest['schema'],
        $structural->manifest['cold_epoch'],
        $structural->manifest['base_image_alias'],
        $structural->manifest['topology']['profile'],
        $structural->manifest['topology']['roles'],
        $structural->manifest['topology']['checkout_roles'],
    ));
    $target = featureTarget('NCK-123');
    $networkExists = false;
    $failedFirewallEnsure = false;
    $operationId = null;
    $leaseAtFirstCreate = null;
    $commands = [];
    $realProcess = new ProcessFactory;

    /** @mago-expect lint:cyclomatic-complexity The fake models one exact external failure transaction. */
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$commands,
        &$failedFirewallEnsure,
        &$networkExists,
        &$operationId,
        &$leaseAtFirstCreate,
        $realProcess,
        $repositoryRoot,
        $paths,
        $target,
    ) {
        $command = $process->command;
        $commands[] = $command;
        if (($command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->input($process->input)->run($command);
        }
        foreach ($command as $argument) {
            if (preg_match('/\Auser\.orbit\.e2e\.operation=([0-9a-f]{32})\z/D', (string) $argument, $matches)) {
                $operationId = $matches[1];
            }
        }
        if (in_array('image', $command, true) && in_array('list', $command, true)) {
            return Process::result(preparedBaseImageJson(str_repeat('a', 64)));
        }
        if (in_array('snapshot', $command, true) && in_array('list', $command, true)) {
            $instance = preg_replace('/\A[^:]+:/', '', (string) ($command[5] ?? ''));

            return Process::result(standbySnapshotInventoryJson($instance));
        }
        if (($command[3] ?? null) === 'list') {
            return Process::result(standbyVmInventoryJson());
        }
        if (
            $command === topologyIncus(
                'network',
                'create',
                'local:'.$target->network(),
                'ipv4.address=10.232.2.1/24',
                'ipv4.nat=true',
                'ipv4.dhcp.ranges=10.232.2.10-10.232.2.12',
                'ipv6.address=none',
                'raw.dnsmasq=port=0',
                'user.orbit.e2e.issue=NCK-123',
                'user.orbit.e2e.attempt='.attemptId()->value,
                'user.orbit.e2e.operation='.$operationId,
                'user.orbit.e2e.owner=orbit-e2e',
            )
        ) {
            $leaseAtFirstCreate = new AtomicJsonStore($paths)->read('leases/NCK-123.json');
            $networkExists = true;

            return Process::result();
        }
        if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'list') {
            return Process::result(json_encode(
                $networkExists
                    ? [[
                        'name' => $target->network(),
                        'config' => [
                            'user.orbit.e2e.owner' => 'orbit-e2e',
                            'user.orbit.e2e.issue' => 'NCK-123',
                            'user.orbit.e2e.attempt' => attemptId()->value,
                            'user.orbit.e2e.operation' => $operationId,
                        ],
                    ]] : [],
                JSON_THROW_ON_ERROR,
            ));
        }
        if ($command === topologyIncus('network', 'delete', 'local:'.$target->network())) {
            $networkExists = false;

            return Process::result();
        }
        if (($command[3] ?? null) === 'copy') {
            return Process::result();
        }
        if (
            ($command[0] ?? null) === 'python3'
            && str_ends_with((string) ($command[1] ?? ''), '/resources/host/reconcile-firewall.py')
        ) {
            $payload = json_decode((string) $process->input, true, 16, JSON_THROW_ON_ERROR);
            if (($payload['operation'] ?? null) === 'ensure' && ! $failedFirewallEnsure) {
                $failedFirewallEnsure = true;

                return Process::result('', 'controlled forwarding failure', 2);
            }

            return Process::result(json_encode(['changed' => false], JSON_THROW_ON_ERROR));
        }

        return Process::result('', 'Unexpected command.', 2);
    });

    expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->acquireDiscovery(
        new TopologyRequest('NCK-123', $repositoryRoot),
    ))
        ->toThrow(RuntimeException::class, 'Host firewall command failed: controlled forwarding failure');

    expect($operationId)
        ->toMatch('/\A[0-9a-f]{32}\z/D')
        ->and($leaseAtFirstCreate['state'] ?? null)
        ->toBe('acquiring')
        ->and($leaseAtFirstCreate['issue'] ?? null)
        ->toBe('NCK-123')
        ->and($leaseAtFirstCreate['operation_id'] ?? null)
        ->toBe($operationId)
        ->and($networkExists)
        ->toBeFalse()
        ->and(new AtomicJsonStore($paths)->read('leases/NCK-123.json'))
        ->toBeNull()
        ->and(collect($commands)->contains(
            fn (array $command): bool => $command === topologyIncus(
                'network',
                'delete',
                'local:'.$target->network(),
            ),
        ))
        ->toBeTrue()
        ->and(collect($commands)->contains(
            fn (array $command): bool => (
                in_array(
                    'user.orbit.e2e.issue=NCK-123',
                    $command,
                    true,
                )
                && in_array('network', $command, true)
                && in_array('create', $command, true)
            ),
        ))
        ->toBeTrue()
        ->and(collect($commands)->contains(
            fn (array $command): bool => in_array('network', $command, true) && in_array('set', $command, true),
        ))
        ->toBeFalse();
});

it('uses the promoted base fingerprint when the base image alias moves', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $store = new AtomicJsonStore($paths);
    $prepared = topologyFinalPreparedFingerprint($repositoryRoot);
    $structural = new PreparedStateFingerprint(new GitRepository($repositoryRoot))->forCommit();
    new StandbyManifestStore($store, $paths, new TopologyManifestStore($store, $paths))->promote(new StandbyGeneration(
        preparedGenerationId($repositoryRoot, $prepared->value),
        new GitRepository($repositoryRoot)->commit(),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $prepared->value,
        str_repeat('a', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
        $structural->value,
        $structural->manifest['schema'],
        $structural->manifest['cold_epoch'],
        $structural->manifest['base_image_alias'],
        $structural->manifest['topology']['profile'],
        $structural->manifest['topology']['roles'],
        $structural->manifest['topology']['checkout_roles'],
    ));
    $commands = [];
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$commands,
        $repositoryRoot,
        $realProcess,
    ) {
        if (($process->command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->input($process->input)->run($process->command);
        }

        $commands[] = $process->command;
        if (in_array('image', $process->command, true) && in_array('list', $process->command, true)) {
            return Process::result(preparedBaseImageJson(str_repeat('b', 64)));
        }
        if (in_array('snapshot', $process->command, true) && in_array('list', $process->command, true)) {
            $instance = preg_replace('/\A[^:]+:/', '', (string) ($process->command[5] ?? ''));

            return Process::result(standbySnapshotInventoryJson($instance));
        }
        if (in_array('list', $process->command, true)) {
            return Process::result(standbyVmInventoryJson());
        }
        if (in_array('network', $process->command, true) && in_array('create', $process->command, true)) {
            return Process::result('', 'controlled network failure', 1);
        }

        return Process::result();
    });

    $acquirer = taskNineAcquirer($repositoryRoot, $paths);
    expect(fn () => $acquirer->acquireDiscovery(new TopologyRequest('NCK-123', $repositoryRoot)))
        ->toThrow(RuntimeException::class, 'controlled network failure')
        ->and(collect($commands)->contains(
            fn (array $command): bool => in_array('image', $command, true) && in_array('list', $command, true),
        ))
        ->toBeFalse()
        ->and(collect($commands)->contains(
            fn (array $command): bool => in_array('network', $command, true) && in_array('create', $command, true),
        ))
        ->toBeTrue();
});

it('batches clone and boot work before cloned host-state reset and preserves failure through rollback', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $store = new AtomicJsonStore($paths);
    $fingerprints = new PreparedStateFingerprint(new GitRepository($repositoryRoot));
    $prepared = topologyFinalPreparedFingerprint($repositoryRoot);
    $structural = $fingerprints->forCommit();
    $generation = new StandbyGeneration(
        preparedGenerationId($repositoryRoot, $prepared->value),
        new GitRepository($repositoryRoot)->commit(),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $prepared->value,
        str_repeat('a', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
        $structural->value,
        $structural->manifest['schema'],
        $structural->manifest['cold_epoch'],
        $structural->manifest['base_image_alias'],
        $structural->manifest['topology']['profile'],
        $structural->manifest['topology']['roles'],
        $structural->manifest['topology']['checkout_roles'],
    );
    new StandbyManifestStore($store, $paths, new TopologyManifestStore($store, $paths))->promote($generation);
    $target = featureTarget('NCK-123');
    $operationId = null;
    $events = [];
    $mutations = [];
    $rollback = identityRefreshRollback($target, $operationId, $mutations);
    fakeIdentityRefreshFailure($repositoryRoot, $target, $events, $operationId);
    $acquirer = taskNineAcquirer(
        $repositoryRoot,
        $paths,
        $rollback,
        new IncusHost(guestReadinessTimeoutSeconds: 1),
    );

    expect(fn () => $acquirer->acquireDiscovery(new TopologyRequest('NCK-123', $repositoryRoot)))
        ->toThrow(RuntimeException::class, 'Failed to reset cloned host state')
        ->and($events)
        ->toBe([
            'clone-network-mac',
            'clone-network-mac',
            'clone-network-mac',
            'start',
            'start',
            'start',
            'readiness',
            'readiness',
            'readiness',
            'ipv4',
            'ipv4',
            'ipv4',
            'identity',
            'identity',
            'identity',
        ])
        ->and($operationId)
        ->toMatch('/\A[0-9a-f]{32}\z/')
        ->and($mutations)
        ->not->toBeEmpty();
});

it('rejects unrelated clean repositories before lock state or Incus access', function () {
    $repositoryRoot = dirname(__DIR__, 5);
    $unrelated = temporaryPath('orbit-unrelated-', 8);
    mkdir($unrelated, 0o700);
    foreach ([
        ['git', 'init', '-q', '-b', 'feature/NCK-12', $unrelated],
        ['git', '-C', $unrelated, 'config', 'user.email', 'developer@example.com'],
        ['git', '-C', $unrelated, 'config', 'user.name', 'Orbit Developer'],
        ['git', '-C', $unrelated, 'commit', '--allow-empty', '-q', '-m', 'Initial'],
    ] as $command) {
        expect(Process::run($command)->successful())->toBeTrue();
    }
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $acquirer = taskNineAcquirer($repositoryRoot, $paths);

    expect(fn () => $acquirer->sync('NCK-12', attemptId(), $unrelated))
        ->toThrow(InvalidArgumentException::class, 'repository identity')
        ->and(is_dir($paths->root().'/locks'))
        ->toBeFalse();
    expect(fn () => $acquirer->prove(new TopologyRequest('NCK-12', $unrelated), str_repeat('a', 40)))
        ->toThrow(InvalidArgumentException::class, 'repository identity')
        ->and(is_dir($paths->root().'/locks'))
        ->toBeFalse();
});

it('rejects a wrong issue branch before creating lifecycle state', function () {
    $repositoryRoot = dirname(__DIR__, 5);
    $suffix = 'wrong-issue-'.bin2hex(random_bytes(4));
    $branchWorktree = pinnedFeatureWorktree($repositoryRoot, $suffix);
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $acquirer = taskNineAcquirer($branchWorktree, $paths);

    try {
        expect(fn () => $acquirer->sync('NCK-999999', attemptId(), $branchWorktree))
            ->toThrow(InvalidArgumentException::class, 'branch does not match')
            ->and(is_dir($paths->root().'/locks'))
            ->toBeFalse();
    } finally {
        Process::run(['git', '-C', $repositoryRoot, 'worktree', 'remove', '--force', $branchWorktree]);
        Process::run(['git', '-C', $repositoryRoot, 'branch', '-D', 'feature/NCK-123-'.$suffix]);
    }
});

it('sync rejects a feature HEAD cold epoch change before source sync or Incus', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    featureTopologyFixture($repositoryRoot, $paths);
    $manifest = json_decode(
        (string) file_get_contents($repositoryRoot.'/apps/e2e/resources/prepared-state.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $manifest['cold_epoch'] = 'ubuntu-26.04-amd64-v2';
    file_put_contents($repositoryRoot.'/apps/e2e/resources/prepared-state.json', json_encode(
        $manifest,
        JSON_THROW_ON_ERROR,
    ));
    Process::run(['git', '-C', $repositoryRoot, 'add', '.']);
    Process::run(['git', '-C', $repositoryRoot, 'commit', '-q', '-m', 'Change cold epoch']);
    $commands = [];
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$commands,
        $repositoryRoot,
        $realProcess,
    ) {
        if (($process->command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->input($process->input)->run($process->command);
        }
        $commands[] = $process->command;

        return Process::result();
    });

    expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->sync('NCK-123', attemptId(), $repositoryRoot))
        ->toThrow(RuntimeException::class, 'cold base contract')
        ->and($commands)
        ->toBeEmpty();
});

it('sync rejects a feature HEAD base image alias change before source sync or Incus', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    featureTopologyFixture($repositoryRoot, $paths);
    $manifest = json_decode(
        (string) file_get_contents($repositoryRoot.'/apps/e2e/resources/prepared-state.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $manifest['base_image_alias'] = 'orbit-base-ubuntu-26.04-other';
    file_put_contents($repositoryRoot.'/apps/e2e/resources/prepared-state.json', json_encode(
        $manifest,
        JSON_THROW_ON_ERROR,
    ));
    Process::run(['git', '-C', $repositoryRoot, 'add', '.']);
    Process::run(['git', '-C', $repositoryRoot, 'commit', '-q', '-m', 'Change base alias']);
    $commands = [];
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$commands,
        $repositoryRoot,
        $realProcess,
    ) {
        if (($process->command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->input($process->input)->run($process->command);
        }
        $commands[] = $process->command;

        return Process::result();
    });

    expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->sync('NCK-123', attemptId(), $repositoryRoot))
        ->toThrow(RuntimeException::class, 'cold base contract')
        ->and($commands)
        ->toBeEmpty();
});

it('allows an ordinary prepared-state change through the cold-base gate', function () {
    $repositoryRoot = preparedTopologyRepository();
    $featureWorktree = $repositoryRoot.'-worktree';
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    featureTopologyFixture($repositoryRoot, $paths);
    file_put_contents($repositoryRoot.'/apps/e2e/resources/guest/converge-gateway.sh', "ordinary change\n");
    Process::run(['git', '-C', $repositoryRoot, 'add', '.']);
    Process::run(['git', '-C', $repositoryRoot, 'commit', '-q', '-m', 'Change prepared script']);
    Process::run([
        'git',
        '-C',
        $repositoryRoot,
        'worktree',
        'add',
        '-q',
        '-b',
        'NCK-123-prepared',
        $featureWorktree,
        'HEAD',
    ]);
    $events = [];
    fakeOrdinaryPreparedChangeProcesses($repositoryRoot, featureTarget('NCK-123'), $events);

    try {
        expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->sync('NCK-123', attemptId(), $featureWorktree))
            ->toThrow(RuntimeException::class)
            ->and(collect($events)->contains(fn (array $command): bool => str_contains(
                implode(' ', $command),
                'receive-source.sh',
            )))
            ->toBeTrue()
            ->and(collect($events)->contains(fn (array $command): bool => str_contains(
                implode(' ', $command),
                'converge-gateway.sh',
            )))
            ->toBeTrue();
    } finally {
        Process::run(['git', '-C', $repositoryRoot, 'worktree', 'remove', '--force', $featureWorktree]);
    }
});

it('preserves prior release evidence when acquisition preflight fails', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $store = new AtomicJsonStore($paths);
    $priorRelease = [
        'state' => 'released',
        'operation_id' => str_repeat('c', 32),
        'evidence_id' => str_repeat('d', 32),
        'released' => ['orbit-e2e-nck-123-aaaaaaaa-gateway'],
        'already_absent' => [],
    ];
    $priorReceiptPath = 'evidence/releases/NCK-123/'.attemptId('e')->value.'.json';
    $store->write($priorReceiptPath, $priorRelease);
    $fingerprint = topologyFinalPreparedFingerprint($repositoryRoot);
    $structural = new PreparedStateFingerprint(new GitRepository($repositoryRoot))->forCommit();
    new StandbyManifestStore($store, $paths, new TopologyManifestStore($store, $paths))->promote(new StandbyGeneration(
        'wrong-generation-id',
        new GitRepository($repositoryRoot)->commit(),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $fingerprint->value,
        str_repeat('a', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
        $structural->value,
        $structural->manifest['schema'],
        $structural->manifest['cold_epoch'],
        $structural->manifest['base_image_alias'],
        $structural->manifest['topology']['profile'],
        $structural->manifest['topology']['roles'],
        $structural->manifest['topology']['checkout_roles'],
    ));
    $commands = [];
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$commands,
        $repositoryRoot,
        $realProcess,
    ) {
        if (($process->command[0] ?? null) === 'git') {
            return $realProcess->path($repositoryRoot)->input($process->input)->run($process->command);
        }
        $commands[] = $process->command;

        return Process::result();
    });
    expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->acquireDiscovery(
        new TopologyRequest('NCK-123', $repositoryRoot),
    ))
        ->toThrow(RuntimeException::class, 'fingerprint is stale or corrupt')
        ->and($store->read($priorReceiptPath))
        ->toBe($priorRelease)
        ->and($commands)
        ->toBeEmpty();
});

it('keeps the release receipts of earlier attempts when reacquiring an issue', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $worktree = pinnedFeatureWorktree($repositoryRoot, 'release-evidence');
    $store = new AtomicJsonStore($paths);
    $prepared = topologyFinalPreparedFingerprint($repositoryRoot);
    $mainSha = new GitRepository($repositoryRoot)->commit();
    $structural = new PreparedStateFingerprint(new GitRepository($repositoryRoot))->forCommit($mainSha);
    $promotedLaravel = new LaravelRelease(
        'v13.10.1',
        '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0',
    );
    new StandbyManifestStore($store, $paths, new TopologyManifestStore($store, $paths))->promote(new StandbyGeneration(
        substr($mainSha, 0, 12).'-'.substr($prepared->value, 0, 12),
        $mainSha,
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $prepared->value,
        str_repeat('b', 64),
        $promotedLaravel,
        $structural->value,
        $structural->manifest['schema'],
        $structural->manifest['cold_epoch'],
        $structural->manifest['base_image_alias'],
        $structural->manifest['topology']['profile'],
        $structural->manifest['topology']['roles'],
        $structural->manifest['topology']['checkout_roles'],
    ));
    $receipt = new \App\E2E\Value\ReleaseResult(
        str_repeat('c', 32),
        str_repeat('d', 32),
        'NCK-123',
        attemptId('b'),
        AttemptPurpose::Discovery,
        ['deleted:orbit-e2e-nck-123-bbbbbbbb-gateway'],
        [],
        ['orbit-e2e-nck-123-bbbbbbbb-gateway'],
        '2026-08-29T10:00:00Z',
    );
    new \App\E2E\ReleaseReceiptStore($store, $paths)->write($receipt);
    $target = featureTarget('NCK-123');
    $events = [];
    fakePinnedWorktreeProcesses($target, $events);

    try {
        $acquired = taskNineAcquirer($repositoryRoot, $paths)->acquireDiscovery(new TopologyRequest(
            'NCK-123',
            $worktree,
        ));
        expect($acquired->attempt->value)
            ->toBe(attemptId()->value)
            ->and(
                new \App\E2E\ReleaseReceiptStore($store, $paths)
                    ->read('NCK-123', attemptId('b'))
                    ?->toArray(),
            )
            ->toBe($receipt->toArray());
    } finally {
        Process::run(['git', '-C', $repositoryRoot, 'worktree', 'remove', '--force', $worktree]);
    }
});

it('fails closed before the first Incus mutation when the acquiring lease cannot be written', function () {
    $repositoryRoot = preparedTopologyRepository();
    $worktree = pinnedFeatureWorktree($repositoryRoot, 'release-evidence-write-failure');
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $store = new AtomicJsonStore($paths, failure: static function (
        string $phase,
        string $temporary,
        string $file,
    ): void {
        if ($phase === 'before_rename' && str_ends_with($file, '/leases/NCK-123.json')) {
            throw new RuntimeException('injected acquisition lease failure');
        }
    });
    $prepared = topologyFinalPreparedFingerprint($repositoryRoot);
    $mainSha = new GitRepository($repositoryRoot)->commit();
    $structural = new PreparedStateFingerprint(new GitRepository($repositoryRoot))->forCommit($mainSha);
    new StandbyManifestStore($store, $paths, new TopologyManifestStore($store, $paths))->promote(new StandbyGeneration(
        substr($mainSha, 0, 12).'-'.substr($prepared->value, 0, 12),
        $mainSha,
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $prepared->value,
        str_repeat('b', 64),
        topologyPromotedLaravel(),
        $structural->value,
        $structural->manifest['schema'],
        $structural->manifest['cold_epoch'],
        $structural->manifest['base_image_alias'],
        $structural->manifest['topology']['profile'],
        $structural->manifest['topology']['roles'],
        $structural->manifest['topology']['checkout_roles'],
    ));
    $events = [];
    fakePinnedWorktreeProcesses(featureTarget('NCK-123'), $events);

    try {
        expect(fn () => taskNineAcquirer($repositoryRoot, $paths, store: $store)->acquireDiscovery(
            new TopologyRequest('NCK-123', $worktree),
        ))
            ->toThrow(RuntimeException::class, 'injected acquisition lease failure')
            ->and(collect($events)->contains(
                static fn (array $command): bool => (
                    in_array('create', $command, true) || in_array('copy', $command, true)
                ),
            ))
            ->toBeFalse();
    } finally {
        Process::run(['git', '-C', $repositoryRoot, 'worktree', 'remove', '--force', $worktree]);
    }
});

it('mounts the worktree, repairs clone identity, and records the host source without convergence', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    $worktreeA = pinnedFeatureWorktree($repositoryRoot, 'a');
    $worktreeB = pinnedFeatureWorktree($repositoryRoot, 'b');
    $store = new AtomicJsonStore($paths);
    $prepared = topologyFinalPreparedFingerprint($repositoryRoot);
    $mainSha = new GitRepository($repositoryRoot)->commit();
    $structural = new PreparedStateFingerprint(new GitRepository($repositoryRoot))->forCommit($mainSha);
    $promotedLaravel = new LaravelRelease(
        'v13.10.1',
        '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0',
    );
    new StandbyManifestStore($store, $paths, new TopologyManifestStore($store, $paths))->promote(new StandbyGeneration(
        substr($mainSha, 0, 12).'-'.substr($prepared->value, 0, 12),
        $mainSha,
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $prepared->value,
        str_repeat('b', 64),
        $promotedLaravel,
        $structural->value,
        $structural->manifest['schema'],
        $structural->manifest['cold_epoch'],
        $structural->manifest['base_image_alias'],
        $structural->manifest['topology']['profile'],
        $structural->manifest['topology']['roles'],
        $structural->manifest['topology']['checkout_roles'],
    ));
    $target = featureTarget('NCK-123');
    $events = [];
    fakePinnedWorktreeProcesses($target, $events);

    $acquirer = taskNineAcquirer($repositoryRoot, $paths);
    $acquired = $acquirer->acquireDiscovery(new TopologyRequest('NCK-123', $worktreeA));
    $acquireEvents = $events;
    $acquireJournal = new OperationJournal($paths)->entries(new OperationId(str_repeat('a', 32)));
    $joined = static fn (array $command): string => implode(' ', array_map(strval(...), $command));
    $instanceOf = static fn (array $command): string => preg_replace('/\A[^:]+:/', '', (string) $command[4]) ?? '';
    $eventsMatching = static fn (array $events, string $needle): array => array_values(array_filter(
        $events,
        static fn (array $command): bool => str_contains($joined($command), $needle),
    ));

    $mountDevice = 'orbit-source,source='.$worktreeA;
    $copies = $eventsMatching($acquireEvents, ' copy ');
    $mountedCopies = array_values(array_filter(
        $copies,
        static fn (array $command): bool => in_array($mountDevice, $command, true),
    ));
    expect($copies)
        ->toHaveCount(3)
        ->and($mountedCopies)
        ->toHaveCount(2)
        ->and(array_map(static fn (array $command): string => (string) $command[5], $mountedCopies))
        ->toBe(['local:'.$target->instance('gateway'), 'local:'.$target->instance('app-dev')])
        ->and($mountedCopies)
        ->each(fn ($copy) => $copy->toContain('orbit-source,type=disk', 'orbit-source,path=/home/orbit/orbit'));

    $mountChecks = $eventsMatching($acquireEvents, 'mountpoint -q -- /home/orbit/orbit');
    $environment = $eventsMatching($acquireEvents, '/var/lib/orbit-e2e/gateway.env');
    $retargets = $eventsMatching($acquireEvents, '/usr/local/bin/retarget-vpn.sh 10.44.0.10');
    $restarts = $eventsMatching($acquireEvents, 'systemctl restart php8.5-fpm');
    $markers = $eventsMatching($acquireEvents, '/var/lib/orbit-e2e/source-state');
    $hostSha = new GitRepository($worktreeA)->commit();
    $treeHash = new GitRepository($worktreeA)->effectiveTreeHash();
    expect(array_map($instanceOf, $mountChecks))
        ->toBe([$target->instance('gateway'), $target->instance('app-dev')])
        ->and(array_map($instanceOf, $environment))
        ->toBe([$target->instance('gateway')])
        ->and($joined($environment[0]))
        ->toContain('[ -e "$1" ] || install -o 1000 -g 1000 -m 0600 -- "$2" "$1"')
        ->toContain('/home/orbit/orbit/apps/gateway/.env')
        ->and(array_map($instanceOf, $retargets))
        ->toBe([$target->instance('app-dev'), $target->instance('app-prod')])
        ->and(array_map($instanceOf, $restarts))
        ->toBe([$target->instance('gateway'), $target->instance('app-dev')])
        ->and(array_map($instanceOf, $markers))
        ->toBe([$target->instance('gateway'), $target->instance('app-dev')])
        ->and($markers[0])
        ->toContain(json_encode([
            'sha' => $hostSha,
            'tree' => $treeHash,
            'mounted' => true,
            'git_pointer_sha256' => hash('sha256', (string) file_get_contents($worktreeA.'/.git')),
        ], JSON_THROW_ON_ERROR))
        ->and($eventsMatching($acquireEvents, 'converge-'))
        ->toBe([])
        ->and($eventsMatching($acquireEvents, 'hydrate-orbit.sh'))
        ->toBe([])
        ->and($eventsMatching($acquireEvents, 'receive-source.sh'))
        ->toBe([])
        ->and($eventsMatching($acquireEvents, 'file push'))
        ->toBe([])
        ->and($eventsMatching($acquireEvents, $promotedLaravel->commit))
        ->toBe([]);

    $indexOf = static function (array $haystack, array $needle): int {
        foreach ($haystack as $index => $command) {
            if ($command === $needle) {
                return $index;
            }
        }

        return -1;
    };
    $sequence = [
        $indexOf($acquireEvents, $eventsMatching($acquireEvents, ' start ')[2]),
        $indexOf($acquireEvents, $mountChecks[0]),
        $indexOf($acquireEvents, $retargets[0]),
        $indexOf($acquireEvents, $restarts[0]),
        $indexOf($acquireEvents, $markers[0]),
        $indexOf($acquireEvents, $eventsMatching($acquireEvents, 'verify-topology.sh')[0]),
    ];
    $ordered = $sequence;
    sort($ordered);
    expect($sequence)->not->toContain(-1)->and($sequence)->toBe($ordered);

    $mounts = [
        'gateway' => ['device' => 'orbit-source', 'source' => $worktreeA, 'path' => '/home/orbit/orbit'],
        'app-dev' => ['device' => 'orbit-source', 'source' => $worktreeA, 'path' => '/home/orbit/orbit'],
    ];
    expect($acquired->purpose)
        ->toBe(AttemptPurpose::Discovery)
        ->and($acquired->mounts)
        ->toBe($mounts)
        ->and($acquired->source->toArray())
        ->toMatchArray(['host_sha' => $hostSha, 'guest_sha' => $hostSha, 'dirty' => false, 'mounted' => true])
        ->and(
            new TopologyManifestStore($store, $paths)
                ->read('NCK-123', attemptId())
                ?->toArray(),
        )
        ->toBe($acquired->toArray())
        ->and($acquireJournal)
        ->toHaveCount(1)
        ->and(array_keys($acquireJournal[0]['duration_ms'] ?? []))
        ->toBe([
            'create.network',
            'clone',
            'start',
            'prepare.cloned-host-state',
            'mount.source',
            'repair.identity',
            'sync.source',
            'verify',
        ])
        ->and(collect($acquireJournal[0]['duration_ms'] ?? [])
            ->every(
                static fn (mixed $milliseconds): bool => is_float($milliseconds) && $milliseconds >= 0,
            ))
        ->toBeTrue();

    $events = [];
    expect(fn () => $acquirer->sync('NCK-123', attemptId(), $worktreeB))
        ->toThrow(RuntimeException::class, 'not the source mounted')
        ->and($eventsMatching($events, 'source-state'))
        ->toBe([])
        ->and($store->read('leases/NCK-123.json')['state'] ?? null)
        ->toBe('failed');

    file_put_contents($worktreeA.'/discovery-overlay.txt', "dirty\n");
    $events = [];
    $synced = $acquirer->sync('NCK-123', attemptId(), $worktreeA);
    $dirtyTree = new GitRepository($worktreeA)->effectiveTreeHash();
    $syncMarkers = $eventsMatching($events, '/var/lib/orbit-e2e/source-state');

    expect($synced->source->toArray())
        ->toMatchArray([
            'host_sha' => $hostSha,
            'dirty' => true,
            'tree_hash' => $dirtyTree,
            'overlay_paths' => ['discovery-overlay.txt'],
            'mounted' => true,
        ])
        ->and($synced->mounts)
        ->toBe($mounts)
        ->and(array_map($instanceOf, $syncMarkers))
        ->toBe([$target->instance('gateway'), $target->instance('app-dev')])
        ->and($syncMarkers[0])
        ->toContain(json_encode([
            'sha' => $hostSha,
            'tree' => $dirtyTree,
            'mounted' => true,
            'git_pointer_sha256' => hash('sha256', (string) file_get_contents($worktreeA.'/.git')),
        ], JSON_THROW_ON_ERROR))
        ->and($eventsMatching($events, 'file push'))
        ->toBe([])
        ->and($eventsMatching($events, 'converge-'))
        ->toBe([])
        ->and($store->read('leases/NCK-123.json')['state'] ?? null)
        ->toBe('ready');

    // Verify and sync re-prove the mount before they touch a mounted topology.
    $unmountedEvents = [];
    fakeDiscoveryMountFailureProcesses($target, 'mountpoint', $unmountedEvents);
    expect(fn () => $acquirer->verify('NCK-123', attemptId()))
        ->toThrow(RuntimeException::class, 'The worktree is not mounted on mountpoint.gateway, mountpoint.app-dev.')
        ->and(fn () => $acquirer->sync('NCK-123', attemptId(), $worktreeA))
        ->toThrow(RuntimeException::class, 'The worktree is not mounted on mountpoint.gateway, mountpoint.app-dev.')
        ->and($eventsMatching($unmountedEvents, 'verify-topology.sh'))
        ->toBe([])
        ->and($eventsMatching($unmountedEvents, '/var/lib/orbit-e2e/source-state'))
        ->toBe([]);
});

it('retries sync from a valid interrupted lease and writes the refreshed manifest', function (
    string $state,
    string $expiresAt,
) {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    featureTopologyFixture($repositoryRoot, $paths);
    new AtomicJsonStore($paths)->write('leases/NCK-123.json', [
        'schema' => 2,
        'issue' => 'NCK-123',
        'attempt' => attemptId()->value,
        'state' => $state,
        'operation_id' => str_repeat('b', 32),
        'expires_at' => $expiresAt,
    ]);
    $events = [];
    fakePinnedWorktreeProcesses(featureTarget('NCK-123'), $events);

    $topology = taskNineAcquirer($repositoryRoot, $paths)->sync(
        'NCK-123',
        attemptId(),
        pinnedFeatureWorktree($repositoryRoot, 'retry'),
    );
    $persisted = new TopologyManifestStore(new AtomicJsonStore($paths), $paths)->active('NCK-123');

    expect($topology->source->hostSha)
        ->toMatch('/\\A[0-9a-f]{40}\\z/')
        ->and($persisted?->source->hostSha)
        ->toBe($topology->source->hostSha)
        ->and($persisted?->verification->passed)
        ->toBeTrue()
        ->and(new AtomicJsonStore($paths)->read('leases/NCK-123.json')['state'])
        ->toBe('ready')
        ->and($events)
        ->not->toBeEmpty();
})->with([
    'syncing' => ['syncing', gmdate('Y-m-d\\TH:i:s\\Z', time() + 604800)],
    'failed' => ['failed', gmdate('Y-m-d\\TH:i:s\\Z', time() + 604800)],
    'expired failed' => ['failed', gmdate('Y-m-d\\TH:i:s\\Z', time() - 60)],
]);

it('fails closed for an unknown or malformed interrupted lease', function (mixed $lease) {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    featureTopologyFixture($repositoryRoot, $paths);
    $leasePath = $paths->path('leases/NCK-123.json');
    if (is_string($lease)) {
        file_put_contents($leasePath, $lease);
    } else {
        new AtomicJsonStore($paths)->write('leases/NCK-123.json', $lease);
    }
    $events = [];
    fakePinnedWorktreeProcesses(featureTarget('NCK-123'), $events);

    expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->sync('NCK-123', attemptId(), $repositoryRoot))
        ->toThrow(RuntimeException::class)
        ->and($events)
        ->toBeEmpty();
})->with([
    [[
        'schema' => 2,
        'issue' => 'NCK-123',
        'attempt' => attemptId()->value,
        'state' => 'unknown',
        'operation_id' => str_repeat('a', 32),
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() + 604800),
    ]],
    ['{malformed'],
]);

it('fails closed for a syncing or failed lease with invalid manifest state', function (
    string $state,
    string $manifestMode,
    string $exception,
) {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    featureTopologyFixture($repositoryRoot, $paths);
    $manifestPath = $paths->path('topologies/NCK-123/'.attemptId()->value.'.json');
    if ($manifestMode === 'missing') {
        unlink($manifestPath);
    } elseif ($manifestMode === 'malformed') {
        file_put_contents($manifestPath, '{malformed');
    } elseif ($manifestMode === 'schema-invalid') {
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['schema'] = 999;
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
    }
    new AtomicJsonStore($paths)->write('leases/NCK-123.json', [
        'schema' => 2,
        'issue' => 'NCK-123',
        'attempt' => attemptId()->value,
        'state' => $state,
        'operation_id' => str_repeat('b', 32),
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() + 604800),
    ]);
    $events = [];
    fakePinnedWorktreeProcesses(featureTarget('NCK-123'), $events);

    expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->sync('NCK-123', attemptId(), $repositoryRoot))
        ->toThrow($exception)
        ->and($events)
        ->toBeEmpty();
})->with([
    ['syncing', 'missing',        RuntimeException::class],
    ['syncing', 'malformed',      RuntimeException::class],
    ['syncing', 'schema-invalid', InvalidArgumentException::class],
    ['failed',  'missing',        RuntimeException::class],
    ['failed',  'malformed',      RuntimeException::class],
    ['failed',  'schema-invalid', InvalidArgumentException::class],
]);

it('fails closed for a malformed interrupted sync lease', function (array $lease) {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    featureTopologyFixture($repositoryRoot, $paths);
    new AtomicJsonStore($paths)->write('leases/NCK-123.json', $lease);
    $events = [];
    fakePinnedWorktreeProcesses(featureTarget('NCK-123'), $events);

    expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->sync('NCK-123', attemptId(), $repositoryRoot))
        ->toThrow(RuntimeException::class, 'sync lease is invalid')
        ->and($events)
        ->toBeEmpty();
})->with([
    [[
        'schema' => 2,
        'issue' => 'NCK-123',
        'attempt' => attemptId()->value,
        'state' => 'syncing',
        'operation_id' => 'invalid',
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() + 604800),
    ]],
    [[
        'schema' => 2,
        'issue' => 'NCK-123',
        'attempt' => attemptId()->value,
        'state' => 'failed',
        'operation_id' => str_repeat('a', 32),
        'expires_at' => '2026-99-99T99:99:99Z',
    ]],
    [[
        'schema' => 2,
        'issue' => 'NCK-123',
        'attempt' => attemptId()->value,
        'state' => 'syncing',
        'operation_id' => str_repeat('a', 32),
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() + 604800),
        'unexpected' => true,
    ]],
]);

it('fails closed for a malformed ready lease on operational entry points', function (string $entryPoint) {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    featureTopologyFixture($repositoryRoot, $paths);
    new AtomicJsonStore($paths)->write('leases/NCK-123.json', [
        'schema' => 2,
        'issue' => 'NCK-123',
        'attempt' => attemptId()->value,
        'state' => 'ready',
        'operation_id' => 'invalid',
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() + 604800),
    ]);
    $events = [];
    fakePinnedWorktreeProcesses(featureTarget('NCK-123'), $events);
    $acquirer = taskNineAcquirer($repositoryRoot, $paths);
    $request = new TopologyRequest('NCK-123', $repositoryRoot);
    $candidate = new GitRepository($repositoryRoot)->commit();

    expect(fn () => match ($entryPoint) {
        'sync' => $acquirer->sync($request->issue, attemptId(), $request->worktree),
        'verify' => $acquirer->verify('NCK-123', attemptId()),
        'execute' => $acquirer->execute('NCK-123', attemptId(), 'gateway', ['true']),
        'prove' => $acquirer->prove($request, $candidate),
    })
        ->toThrow(RuntimeException::class, 'topology lease is invalid')
        ->and($events)
        ->toBeEmpty();
})->with(['sync', 'verify', 'execute', 'prove']);

it('runs interrupted sync retry under the issue lock', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    featureTopologyFixture($repositoryRoot, $paths);
    new AtomicJsonStore($paths)->write('leases/NCK-123.json', [
        'schema' => 2,
        'issue' => 'NCK-123',
        'attempt' => attemptId()->value,
        'state' => 'syncing',
        'operation_id' => str_repeat('a', 32),
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() + 604800),
    ]);
    $lock = new OperationLock($paths);
    expect($lock->acquire('topology-NCK-123', new OperationId(str_repeat('b', 32))))->toBeTrue();
    $events = [];
    fakePinnedWorktreeProcesses(featureTarget('NCK-123'), $events);

    try {
        expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->sync('NCK-123', attemptId(), $repositoryRoot))
            ->toThrow(RuntimeException::class, 'locked')
            ->and($events)
            ->toBeEmpty();
    } finally {
        $lock->release();
    }
});

it('blocks verification while the exact issue topology lock is held', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    featureTopologyFixture($repositoryRoot, $paths);
    $lock = new OperationLock($paths);
    expect($lock->acquire('topology-NCK-123', new OperationId(str_repeat('b', 32))))->toBeTrue();
    $events = [];
    fakePinnedWorktreeProcesses(featureTarget('NCK-123'), $events);

    try {
        expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->verify('NCK-123', attemptId()))
            ->toThrow(RuntimeException::class, 'The issue topology is locked.')
            ->and($events)
            ->toBeEmpty();
    } finally {
        $lock->release();
    }
});

it('blocks guest execution while the exact issue topology lock is held', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    featureTopologyFixture($repositoryRoot, $paths);
    $lock = new OperationLock($paths);
    expect($lock->acquire('topology-NCK-123', new OperationId(str_repeat('b', 32))))->toBeTrue();
    $events = [];
    fakePinnedWorktreeProcesses(featureTarget('NCK-123'), $events);

    try {
        expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->execute('NCK-123', attemptId(), 'gateway', ['true']))
            ->toThrow(RuntimeException::class, 'The issue topology is locked.')
            ->and($events)
            ->toBeEmpty();
    } finally {
        $lock->release();
    }
});

it('reports the lock before malformed topology validation', function (string $entryPoint) {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    featureTopologyFixture($repositoryRoot, $paths);
    new AtomicJsonStore($paths)->write('leases/NCK-123.json', ['invalid' => true]);
    $lock = new OperationLock($paths);
    expect($lock->acquire('topology-NCK-123', new OperationId(str_repeat('b', 32))))->toBeTrue();
    $events = [];
    fakePinnedWorktreeProcesses(featureTarget('NCK-123'), $events);

    try {
        expect(fn () => match ($entryPoint) {
            'verify' => taskNineAcquirer($repositoryRoot, $paths)->verify('NCK-123', attemptId()),
            'execute' => taskNineAcquirer($repositoryRoot, $paths)->execute(
                'NCK-123',
                attemptId(),
                'gateway',
                ['true'],
            ),
        })
            ->toThrow(RuntimeException::class, 'The issue topology is locked.')
            ->and($events)
            ->toBeEmpty();
    } finally {
        $lock->release();
    }
})->with(['verify', 'execute']);

it('releases the issue lock after interrupted sync retry fails', function () {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    featureTopologyFixture($repositoryRoot, $paths);
    new AtomicJsonStore($paths)->write('leases/NCK-123.json', [
        'schema' => 2,
        'issue' => 'NCK-123',
        'attempt' => attemptId()->value,
        'state' => 'failed',
        'operation_id' => str_repeat('a', 32),
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() + 604800),
    ]);
    $events = [];
    fakeOrdinaryPreparedChangeProcesses($repositoryRoot, featureTarget('NCK-123'), $events);
    $worktree = pinnedFeatureWorktree($repositoryRoot, 'lock-release');

    expect(fn () => taskNineAcquirer($repositoryRoot, $paths)->sync('NCK-123', attemptId(), $worktree))
        ->toThrow(RuntimeException::class, 'converge-gateway.sh failed');

    $lock = new OperationLock($paths);
    expect($lock->acquire('topology-NCK-123', new OperationId(str_repeat('b', 32))))->toBeTrue();
    $lock->release();
});

it('rejects operational entry points while a topology lease is syncing or failed', function (
    string $state,
    string $entryPoint,
) {
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-acquirer-state-', 8));
    featureTopologyFixture($repositoryRoot, $paths);
    new AtomicJsonStore($paths)->write('leases/NCK-123.json', [
        'schema' => 2,
        'issue' => 'NCK-123',
        'attempt' => attemptId()->value,
        'state' => $state,
        'operation_id' => str_repeat('b', 32),
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() + 604800),
    ]);
    $events = [];
    fakePinnedWorktreeProcesses(featureTarget('NCK-123'), $events);
    $acquirer = taskNineAcquirer($repositoryRoot, $paths);
    $request = new TopologyRequest('NCK-123', $repositoryRoot);
    $candidate = new GitRepository($repositoryRoot)->commit();

    expect(fn () => match ($entryPoint) {
        'verify' => $acquirer->verify('NCK-123', attemptId()),
        'execute' => $acquirer->execute('NCK-123', attemptId(), 'gateway', ['true']),
        'prove' => $acquirer->prove($request, $candidate),
    })
        ->toThrow(RuntimeException::class)
        ->and($events)
        ->toBeEmpty();
})->with([
    ['syncing', 'verify'],
    ['syncing', 'execute'],
    ['syncing', 'prove'],
    ['failed',  'verify'],
    ['failed',  'execute'],
    ['failed',  'prove'],
]);
