<?php

declare(strict_types=1);

use App\E2E\AcquisitionRollback;
use App\E2E\DiscoveryGuestPreparer;
use App\E2E\Git\GitRepository;
use App\E2E\HostCapacity;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\PreparedStateFingerprint;
use App\E2E\ProofRecordReader;
use App\E2E\ProofStore;
use App\E2E\ReleaseReceiptStore;
use App\E2E\StandbyManifestStore;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationJournal;
use App\E2E\State\SecretRedactor;
use App\E2E\State\StatePaths;
use App\E2E\TopologyAcquirer;
use App\E2E\TopologyConverger;
use App\E2E\TopologyManifestStore;
use App\E2E\TopologyProofRunner;
use App\E2E\TopologyReleaser;
use App\E2E\TopologyVerifier;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\IncusNetwork;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofPlan;
use App\E2E\Value\ProofStatus;
use App\E2E\Value\ReleaseResult;
use App\E2E\Value\SourceState;
use App\E2E\Value\StandbyGeneration;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationReport;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

require_once __DIR__.'/Support/TopologyFixtures.php';

uses(Tests\TestCase::class);

const PROOF_ACCEPTANCE_ARGV = ['/home/orbit/orbit/apps/cli/orbit', 'workspace:list', '--json'];

/** @return array{repositoryRoot:string,worktree:string,paths:StatePaths,store:AtomicJsonStore,candidate:string,tree:string,generationId:string} */
function proofFixture(string $suffix): array
{
    $repositoryRoot = preparedTopologyRepository();
    $paths = new StatePaths(temporaryPath('orbit-proof-state-', 8));
    $store = new AtomicJsonStore($paths);
    promoteDiscoveryGeneration($repositoryRoot, $paths);
    $worktree = pinnedFeatureWorktree($repositoryRoot, $suffix);
    $repository = new GitRepository($worktree);
    $candidate = $repository->commit();
    $tree = $repository->tree($candidate);
    proofDiscoveryReceipt($store, $paths);
    $generationId = (string) ($store->read('standby/promoted.json')['id'] ?? '');

    return compact('repositoryRoot', 'worktree', 'paths', 'store', 'candidate', 'tree', 'generationId');
}

/** The released and verified discovery attempt `a` every proof of the issue requires. */
function proofDiscoveryReceipt(AtomicJsonStore $store, StatePaths $paths, string $character = 'a'): void
{
    $target = featureTarget('NCK-123', $character);
    new ReleaseReceiptStore($store, $paths)->write(new ReleaseResult(
        str_repeat('9', 32),
        str_repeat('8', 32),
        'NCK-123',
        attemptId($character),
        AttemptPurpose::Discovery,
        ['deleted:'.$target->instance('gateway')],
        [],
        [...array_map($target->instance(...), TopologyProfile::ROLES), $target->network()],
        ReleaseResult::now(),
    ));
}

/** The discovery topology of attempt `a`, still active. */
function proofDiscoveryTopology(AtomicJsonStore $store): FeatureTopology
{
    $target = featureTarget('NCK-123');
    $generation = StandbyGeneration::fromArray($store->read('standby/promoted.json') ?? []);

    return new FeatureTopology(
        $target,
        AttemptPurpose::Discovery,
        $generation,
        $target->network(),
        array_combine(TopologyProfile::ROLES, array_map($target->instance(...), TopologyProfile::ROLES)),
        new SourceState($generation->mainSha, $generation->mainSha, mounted: true, pointerHash: str_repeat('f', 64)),
        new VerificationReport(true, ['fixture' => verificationProbeFixture()]),
        [
            'gateway' => ['device' => 'orbit-source', 'source' => '/srv/worktree', 'path' => '/home/orbit/orbit'],
            'app-dev' => ['device' => 'orbit-source', 'source' => '/srv/worktree', 'path' => '/home/orbit/orbit'],
        ],
    );
}

function proofPlan(): ProofPlan
{
    return ProofPlan::fromArray([
        'setup' => [
            ['id' => 'seed', 'node' => 'app-dev', 'argv' => ['touch', '/tmp/seeded'], 'timeout_seconds' => 30],
        ],
        'acceptance' => [
            ['id' => 'workspaces', 'node' => 'app-dev', 'argv' => PROOF_ACCEPTANCE_ARGV, 'timeout_seconds' => 60],
            [
                'id' => 'gateway-health',
                'node' => 'gateway',
                'argv' => ['systemctl', 'is-active', 'orbit-gateway'],
                'timeout_seconds' => 30,
            ],
        ],
        'post_deployment_actions' => [],
    ]);
}

/**
 * @param list<string> $attempts
 * @mago-expect lint:excessive-parameter-list The helper exposes each injectable test dependency explicitly.
 */
function proofRunner(
    string $repositoryRoot,
    StatePaths $paths,
    array $attempts = ['b', 'c'],
    ?AcquisitionRollback $rollback = null,
    ?SecretRedactor $redactor = null,
): TopologyProofRunner {
    $store = new AtomicJsonStore($paths);
    $host = new IncusHost;
    $operation = new OperationId(str_repeat('a', 32));
    $redactor ??= new SecretRedactor;
    $queue = $attempts;

    return new TopologyProofRunner(
        $host,
        new IncusNetworkLifecycle($host),
        new StandbyManifestStore($store, $paths, new TopologyManifestStore($store, $paths)),
        new TopologyManifestStore($store, $paths),
        new WorktreeSynchronizer($host, $repositoryRoot, $operation),
        new TopologyConverger($host),
        new TopologyVerifier($host, readinessTimeoutSeconds: 1, readinessPollIntervalMicroseconds: 0),
        new ReleaseReceiptStore($store, $paths),
        new ProofStore($store),
        new HostCapacity($store, $paths, $operation, 12),
        $store,
        $paths,
        $operation,
        new OperationJournal($paths, $redactor),
        $redactor,
        $repositoryRoot,
        $rollback,
        attempts: static function () use (&$queue): AttemptId {
            $character = array_shift($queue) ?? throw new RuntimeException('No attempt identity is left.');

            return attemptId($character);
        },
    );
}

function proofAcquirer(string $repositoryRoot, StatePaths $paths): TopologyAcquirer
{
    $store = new AtomicJsonStore($paths);
    $host = new IncusHost;
    $operation = new OperationId(str_repeat('a', 32));
    $redactor = new SecretRedactor;

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
        new OperationJournal($paths, $redactor),
        $redactor,
        new HostCapacity($store, $paths, $operation, 12),
        new ProofRecordReader($store),
        new DiscoveryGuestPreparer($host),
        $repositoryRoot,
    );
}

function proofReleaser(StatePaths $paths): TopologyReleaser
{
    $store = new AtomicJsonStore($paths);
    $host = new IncusHost;
    $operation = new OperationId(str_repeat('a', 32));

    return new TopologyReleaser(
        $host,
        new IncusNetworkLifecycle($host),
        new TopologyManifestStore($store, $paths),
        $store,
        $paths,
        $operation,
        new ReleaseReceiptStore($store, $paths),
        new HostCapacity($store, $paths, $operation, 12),
    );
}

/** @return array<string, string> */
function proofIdentity(TopologyTarget $target, string $generationId): array
{
    return [
        'user.orbit.e2e.owner' => 'orbit-e2e',
        'user.orbit.e2e.issue' => $target->issue,
        'user.orbit.e2e.attempt' => $target->requireAttempt()->value,
        'user.orbit.e2e.generation' => $generationId,
        'user.orbit.e2e.operation' => str_repeat('a', 32),
    ];
}

/**
 * A rollback fake that records every mutation and proves absence afterwards.
 *
 * @param list<TopologyTarget> $targets
 * @param list<string> $mutations
 */
function proofRollback(array $targets, string $generationId, array &$mutations): AcquisitionRollback
{
    $deleted = [];
    $read = static function (string $resource) use ($targets, $generationId): IncusInstance|IncusNetwork {
        foreach ($targets as $target) {
            if ($resource === $target->network()) {
                return new IncusNetwork('local', 'default', $resource, proofIdentity($target, $generationId));
            }
            foreach (TopologyProfile::ROLES as $role) {
                if ($resource === $target->instance($role)) {
                    return new IncusInstance(
                        'local',
                        'default',
                        $resource,
                        'default',
                        proofIdentity($target, $generationId),
                        network: $target->network(),
                        mac: $target->mac($role),
                    );
                }
            }
        }

        throw new RuntimeException("The rollback fake does not know {$resource}.");
    };

    return new AcquisitionRollback(
        static function (array $resources) use ($read, &$deleted): array {
            $inventory = [];
            foreach ($resources as $resource) {
                $inventory[$resource] = isset($deleted[$resource]) ? null : $read($resource);
            }

            return $inventory;
        },
        static function (array $resources) use (&$mutations): void {
            foreach ($resources as $resource) {
                $mutations[] = 'stop:'.$resource;
            }
        },
        static function (array $resources) use (&$mutations, &$deleted): void {
            foreach ($resources as $resource) {
                $mutations[] = 'delete:'.$resource;
                $deleted[$resource] = true;
            }
        },
        static function (string $resource) use (&$mutations, &$deleted): void {
            $mutations[] = 'network:'.$resource;
            $deleted[$resource] = true;
        },
    );
}

/**
 * Guest answers for one proof: every checkout role reports the candidate commit
 * and tree with a clean status; the acceptance command answers with JSON.
 *
 * @param list<string> $guest
 * @param null|Closure(list<string>): (?\Illuminate\Contracts\Process\ProcessResult) $override
 */
function proofGuestResult(
    array $guest,
    string $candidate,
    string $tree,
    ?Closure $override,
): \Illuminate\Contracts\Process\ProcessResult {
    if (array_slice($guest, 0, 6) === ['runuser', '-u', 'orbit', '--', 'env', 'HOME=/home/orbit']) {
        $guest = array_slice($guest, 6);
    }
    if ($override !== null && ($result = $override($guest)) !== null) {
        return $result;
    }
    if (in_array('rev-parse', $guest, true) && in_array('HEAD^{commit}', $guest, true)) {
        return Process::result($candidate."\n");
    }
    if (in_array('rev-parse', $guest, true) && in_array('HEAD^{tree}', $guest, true)) {
        return Process::result($tree."\n");
    }
    if ($guest === PROOF_ACCEPTANCE_ARGV) {
        return Process::result("[]\n");
    }

    return pinnedWorktreeGuestCommandResult($guest);
}

/**
 * An Incus fake for proof attempts: every intended resource of each target
 * exists with full acquisition identity until a delete command removes it.
 *
 * @param list<TopologyTarget> $targets
 * @param list<array<array-key, mixed>> $events
 * @param null|Closure(list<string>): (?\Illuminate\Contracts\Process\ProcessResult) $guestOverride
 * @param null|Closure(list<string>): (?\Illuminate\Contracts\Process\ProcessResult) $hostOverride
 * @mago-expect lint:cyclomatic-complexity,excessive-parameter-list,kan-defect,halstead The fake inventories each exact Incus resource kind.
 */
function fakeProofProcesses(
    array $targets,
    string $generationId,
    string $candidate,
    string $tree,
    array &$events,
    ?Closure $guestOverride = null,
    ?Closure $hostOverride = null,
): void {
    $realProcess = new ProcessFactory;
    $deleted = [];
    $vm = static function (TopologyTarget $target, string $role) use ($generationId): array {
        return json_decode(
            topologyVmJson($target->instance($role), proofIdentity($target, $generationId), $target->network()),
            true,
            16,
            JSON_THROW_ON_ERROR,
        )[0];
    };
    Process::fake(function (PendingProcess $process) use (
        &$events,
        &$deleted,
        $realProcess,
        $targets,
        $generationId,
        $candidate,
        $tree,
        $guestOverride,
        $hostOverride,
        $vm,
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
            static fn (array $guest) => proofGuestResult($guest, $candidate, $tree, $guestOverride),
        );
        if ($batch !== null) {
            return $batch;
        }
        $events[] = $command;
        if ($hostOverride !== null && ($result = $hostOverride($command)) !== null) {
            return $result;
        }
        if (($command[3] ?? null) === 'exec') {
            return proofGuestResult(array_slice($command, 6), $candidate, $tree, $guestOverride);
        }
        if (($command[3] ?? null) === 'delete') {
            $deleted[preg_replace('/\A[^:]+:/', '', (string) ($command[4] ?? ''))] = true;

            return Process::result();
        }
        if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'delete') {
            $deleted[preg_replace('/\A[^:]+:/', '', (string) ($command[5] ?? ''))] = true;

            return Process::result();
        }
        if (($firewall = topologyFirewallResult($command)) !== null) {
            return $firewall;
        }
        if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'list') {
            $networks = [];
            foreach ($targets as $target) {
                if (isset($deleted[$target->network()])) {
                    continue;
                }
                $networks[] = [
                    'name' => $target->network(),
                    'config' => [
                        'ipv4.address' => '10.232.2.1/24',
                        ...proofIdentity($target, $generationId),
                    ],
                ];
            }

            return Process::result(json_encode($networks, JSON_THROW_ON_ERROR));
        }
        if (($command[3] ?? null) === 'list') {
            $name = preg_replace('/\A[^:]+:/', '', (string) ($command[4] ?? ''));
            $live = [];
            foreach ($targets as $target) {
                foreach (TopologyProfile::ROLES as $role) {
                    if (! isset($deleted[$target->instance($role)])) {
                        $live[$target->instance($role)] = $vm($target, $role);
                    }
                }
            }
            if ($name === '') {
                $standby = array_values(array_filter(
                    json_decode(standbyVmInventoryJson(), true, 16, JSON_THROW_ON_ERROR),
                    static fn (array $resource): bool => ! str_contains((string) $resource['name'], 'nck-123'),
                ));

                return Process::result(json_encode([...$standby, ...array_values($live)], JSON_THROW_ON_ERROR));
            }
            foreach ($targets as $target) {
                if ($name === $target->network()) {
                    return Process::result('[]');
                }
                foreach (TopologyProfile::ROLES as $role) {
                    if ($name === $target->instance($role)) {
                        return Process::result(json_encode(
                            isset($live[$name]) ? [$live[$name]] : [],
                            JSON_THROW_ON_ERROR,
                        ));
                    }
                }
            }

            return Process::result(topologyVmJson($name));
        }

        return pinnedWorktreeInventoryResult($command, $targets[0]) ?? Process::result();
    });
}

/**
 * Indexes of guest executions whose argument vector, without the `runuser` prefix, starts with `$prefix`.
 *
 * @param list<array<array-key, mixed>> $events
 * @param list<string> $prefix
 * @return list<int>
 */
function proofGuestIndex(array $events, array $prefix): array
{
    $indexes = [];
    foreach ($events as $index => $command) {
        if (($command[3] ?? null) !== 'exec') {
            continue;
        }
        $guest = array_slice($command, 6);
        if (array_slice($guest, 0, 6) === ['runuser', '-u', 'orbit', '--', 'env', 'HOME=/home/orbit']) {
            $guest = array_slice($guest, 6);
        }
        if (array_slice($guest, 0, count($prefix)) === $prefix) {
            $indexes[] = $index;
        }
    }

    return $indexes;
}

/** @param list<array<array-key, mixed>> $events @return list<int> */
function proofIncusIndex(array $events, string $action): array
{
    $indexes = [];
    foreach ($events as $index => $command) {
        if (($command[3] ?? null) === $action) {
            $indexes[] = $index;
        }
    }

    return $indexes;
}

it('proves an exact candidate on a fresh proof topology in the locked sequence', function () {
    $fixture = proofFixture('proved');
    ['paths' => $paths, 'store' => $store, 'candidate' => $candidate, 'tree' => $tree] = $fixture;
    $target = featureTarget('NCK-123', 'b');
    $events = [];
    fakeProofProcesses([$target], $fixture['generationId'], $candidate, $tree, $events);
    $journal = new OperationJournal($paths);

    try {
        $result = proofRunner($fixture['repositoryRoot'], $paths)->prove(
            new TopologyRequest('NCK-123', $fixture['worktree']),
            $candidate,
            proofPlan(),
        );
    } finally {
        Process::run(['git', '-C', $fixture['repositoryRoot'], 'worktree', 'remove', '--force', $fixture['worktree']]);
    }

    $topology = new TopologyManifestStore($store, $paths)->active('NCK-123');
    $phases = array_values(array_filter(
        $journal->entries(new OperationId(str_repeat('a', 32))),
        static fn (array $entry): bool => $entry['event'] === 'topology.prove.phases',
    ));
    $copies = array_values(array_filter($events, static fn (array $command): bool => ($command[3] ?? null) === 'copy'));

    expect($result->status)
        ->toBe(ProofStatus::Proved)
        ->and($result->attempt->value)
        ->toBe(attemptId('b')->value)
        ->and($result->candidateSha)
        ->toBe($candidate)
        ->and($result->candidateTree)
        ->toBe($tree)
        ->and($result->guestScriptHash)
        ->toMatch('/\A[0-9a-f]{64}\z/')
        ->and(array_column($result->setupResults, 'id'))
        ->toBe(['seed'])
        ->and(array_column($result->acceptanceResults, 'id'))
        ->toBe(['workspaces', 'gateway-health'])
        ->and($result->acceptanceResults[0])
        ->toMatchArray(['node' => 'app-dev', 'argv' => PROOF_ACCEPTANCE_ARGV, 'exit_code' => 0, 'stdout' => "[]\n"])
        ->and($result->verification->passed)
        ->toBeTrue()
        ->and($store->read('evidence/proofs/NCK-123/'.attemptId('b')->value.'.json'))
        ->toBe($result->toArray())
        ->and(new ProofRecordReader($store)->isProved('NCK-123', attemptId('b')))
        ->toBeTrue()
        ->and($topology?->purpose)
        ->toBe(AttemptPurpose::Proof)
        ->and($topology?->attempt->value)
        ->toBe(attemptId('b')->value)
        ->and($topology?->mounts)
        ->toBe([])
        ->and($topology?->source->toArray())
        ->toMatchArray(['host_sha' => $candidate, 'guest_sha' => $candidate, 'dirty' => false, 'mounted' => false])
        ->and($topology?->verification->toArray())
        ->toBe($result->verification->toArray())
        ->and($store->read('leases/NCK-123.json'))
        ->toMatchArray(['attempt' => attemptId('b')->value, 'state' => 'ready'])
        ->and($target->instance('gateway'))
        ->not->toBe(featureTarget('NCK-123')->instance('gateway'))->and($target->network())
        ->not->toBe(featureTarget('NCK-123')->network());

    // Resources are clones without a mount device; the sequence is sync, identity, converge, setup, acceptance, verify.
    expect($copies)
        ->toHaveCount(3)
        ->and(implode(' ', array_map(static fn (array $copy): string => implode(' ', $copy), $copies)))
        ->not
        ->toContain('orbit-source')
        ->and(array_keys($phases[0]['duration_ms'] ?? []))
        ->toBe([
            'create.network',
            'clone',
            'start',
            'prepare.cloned-host-state',
            'sync.candidate',
            'identity',
            'converge',
            'setup',
            'acceptance',
            'verify',
        ])
        ->and($phases[0]['state'] ?? null)
        ->toBe('proved');

    $sequence = [
        proofIncusIndex($events, 'copy')[0],
        proofIncusIndex($events, 'start')[0],
        proofGuestIndex($events, ['/usr/local/bin/receive-source.sh'])[0],
        proofGuestIndex($events, ['/usr/local/bin/converge-gateway.sh'])[0],
        proofGuestIndex($events, ['touch', '/tmp/seeded'])[0],
        proofGuestIndex($events, PROOF_ACCEPTANCE_ARGV)[0],
        proofGuestIndex($events, ['systemctl', 'is-active', 'orbit-gateway'])[0],
        proofGuestIndex($events, ['/usr/local/bin/verify-topology.sh'])[0],
    ];
    $ordered = $sequence;
    sort($ordered);
    $verifyProbe = $events[proofGuestIndex($events, ['/usr/local/bin/verify-topology.sh'])[0]];
    expect($sequence)
        ->toBe($ordered)
        ->and($verifyProbe[8] ?? null)
        ->toBe('proof')
        ->and($verifyProbe[9] ?? null)
        ->toBe($candidate)
        ->and($events[proofGuestIndex($events, ['touch', '/tmp/seeded'])[0]][4] ?? null)
        ->toBe('local:'.$target->instance('app-dev'));
});

it('refuses proof before any Incus mutation when the preconditions fail', function (string $case) {
    $fixture = proofFixture('refused-'.$case);
    ['paths' => $paths, 'store' => $store, 'candidate' => $candidate, 'tree' => $tree] = $fixture;
    $worktree = $fixture['worktree'];
    $expected = match ($case) {
        'receipt' => 'released and verified discovery attempt',
        'active' => 'already has an active topology attempt',
        'lease' => 'still holds a topology lease',
        'unreachable' => 'not reachable',
        'sha' => 'exact full SHA',
        'branch' => 'branch does not match',
    };
    $issue = 'NCK-123';
    match ($case) {
        'receipt' => $store->delete('evidence/releases/NCK-123/'.attemptId()->value.'.json'),
        'active' => new TopologyManifestStore($store, $paths)->writeActive(proofDiscoveryTopology($store)),
        'lease' => $store->write('leases/NCK-123.json', ['schema' => 2, 'issue' => 'NCK-123']),
        'unreachable' => $candidate = str_repeat('0', 40),
        'sha' => $candidate = substr($candidate, 0, 12),
        'branch' => $issue = 'NCK-999',
    };
    $events = [];
    fakeProofProcesses([featureTarget('NCK-123', 'b')], $fixture['generationId'], $candidate, $tree, $events);

    try {
        expect(fn () => proofRunner($fixture['repositoryRoot'], $paths)->prove(
            new TopologyRequest($issue, $worktree),
            $candidate,
            proofPlan(),
        ))
            ->toThrow(Exception::class, $expected)
            ->and(collect($events)->contains(
                static fn (array $command): bool => (
                    array_intersect($command, ['create', 'copy', 'start', 'exec']) !== []
                ),
            ))
            ->toBeFalse()
            ->and($store->read('evidence/proofs/NCK-123/'.attemptId('b')->value.'.json'))
            ->toBeNull()
            ->and(new TopologyManifestStore($store, $paths)->read('NCK-123', attemptId('b')))
            ->toBeNull();
    } finally {
        Process::run(['git', '-C', $fixture['repositoryRoot'], 'worktree', 'remove', '--force', $worktree]);
    }
})->with(['receipt', 'active', 'lease', 'unreachable', 'sha', 'branch']);

it('retries once on a fresh attempt after a clone failure and rolls the first attempt back', function () {
    $fixture = proofFixture('retry');
    ['paths' => $paths, 'store' => $store, 'candidate' => $candidate, 'tree' => $tree] = $fixture;
    $first = featureTarget('NCK-123', 'b');
    $second = featureTarget('NCK-123', 'c');
    $events = [];
    $copyFailures = 0;
    fakeProofProcesses(
        [$first, $second],
        $fixture['generationId'],
        $candidate,
        $tree,
        $events,
        hostOverride: static function (array $command) use (&$copyFailures, $first) {
            if (
                ($command[3] ?? null) === 'copy'
                && str_contains((string) ($command[5] ?? ''), $first->instance('gateway'))
            ) {
                $copyFailures++;

                return Process::result('', 'copy failed', 1);
            }

            return null;
        },
    );
    $mutations = [];
    $rollback = proofRollback([$first, $second], $fixture['generationId'], $mutations);

    try {
        $result = proofRunner($fixture['repositoryRoot'], $paths, ['b', 'c'], $rollback)->prove(
            new TopologyRequest('NCK-123', $fixture['worktree']),
            $candidate,
            proofPlan(),
        );
    } finally {
        Process::run(['git', '-C', $fixture['repositoryRoot'], 'worktree', 'remove', '--force', $fixture['worktree']]);
    }

    expect($result->status)
        ->toBe(ProofStatus::Proved)
        ->and($result->attempt->value)
        ->toBe(attemptId('c')->value)
        ->and($copyFailures)
        ->toBe(1)
        ->and($mutations)
        ->toContain('delete:'.$first->instance('gateway'), 'network:'.$first->network())
        ->and(new TopologyManifestStore($store, $paths)->active('NCK-123')?->attempt->value)
        ->toBe(attemptId('c')->value)
        ->and($store->read('evidence/proofs/NCK-123/'.attemptId('b')->value.'.json'))
        ->toBeNull()
        ->and($store->read('capacity/incus.json')['reservations'] ?? [])
        ->toHaveKey('NCK-123:'.attemptId('c')->value)
        ->not->toHaveKey('NCK-123:'.attemptId('b')->value);
});

it('records a diagnosis without a topology when resource creation fails twice', function () {
    $fixture = proofFixture('creation');
    ['paths' => $paths, 'store' => $store, 'candidate' => $candidate, 'tree' => $tree] = $fixture;
    $first = featureTarget('NCK-123', 'b');
    $second = featureTarget('NCK-123', 'c');
    $events = [];
    fakeProofProcesses(
        [$first, $second],
        $fixture['generationId'],
        $candidate,
        $tree,
        $events,
        hostOverride: static fn (array $command) => ($command[3] ?? null) === 'copy'
            ? Process::result('', 'copy failed', 1)
            : null,
    );
    $mutations = [];
    $rollback = proofRollback([$first, $second], $fixture['generationId'], $mutations);

    try {
        $result = proofRunner($fixture['repositoryRoot'], $paths, ['b', 'c'], $rollback)->prove(
            new TopologyRequest('NCK-123', $fixture['worktree']),
            $candidate,
            proofPlan(),
        );
    } finally {
        Process::run(['git', '-C', $fixture['repositoryRoot'], 'worktree', 'remove', '--force', $fixture['worktree']]);
    }

    expect($result->status)
        ->toBe(ProofStatus::Diagnosis)
        ->and($result->attempt->value)
        ->toBe(attemptId('c')->value)
        ->and($result->guestScriptHash)
        ->toBeNull()
        ->and(array_keys($result->verification->probes))
        ->toBe(['proof.clone'])
        ->and($store->read('evidence/proofs/NCK-123/'.attemptId('c')->value.'.json')['status'] ?? null)
        ->toBe('diagnosis')
        ->and(new TopologyManifestStore($store, $paths)->active('NCK-123'))
        ->toBeNull()
        ->and($store->read('leases/NCK-123.json'))
        ->toBeNull()
        ->and($mutations)
        ->toContain('network:'.$second->network());
});

it('keeps the topology active with a diagnosis when the candidate identity is not clean', function () {
    $fixture = proofFixture('identity');
    ['paths' => $paths, 'store' => $store, 'candidate' => $candidate, 'tree' => $tree] = $fixture;
    $target = featureTarget('NCK-123', 'b');
    $events = [];
    $treeProbes = 0;
    fakeProofProcesses(
        [$target],
        $fixture['generationId'],
        $candidate,
        $tree,
        $events,
        static function (array $guest) use (&$treeProbes) {
            if (in_array('HEAD^{tree}', $guest, true)) {
                $treeProbes++;
                // The sync proves both roles first; the runner's own identity probe then sees drift.
                if ($treeProbes > 2) {
                    return Process::result(str_repeat('f', 40)."\n");
                }
            }

            return null;
        },
    );

    try {
        $result = proofRunner($fixture['repositoryRoot'], $paths)->prove(
            new TopologyRequest('NCK-123', $fixture['worktree']),
            $candidate,
            proofPlan(),
        );
    } finally {
        Process::run(['git', '-C', $fixture['repositoryRoot'], 'worktree', 'remove', '--force', $fixture['worktree']]);
    }

    expect($result->status)
        ->toBe(ProofStatus::Diagnosis)
        ->and(array_keys($result->verification->probes))
        ->toBe(['proof.identity'])
        ->and($result->verification->probes['proof.identity']['observed'])
        ->toContain('tree does not match')
        ->and(new TopologyManifestStore($store, $paths)->active('NCK-123')?->attempt->value)
        ->toBe(attemptId('b')->value)
        ->and(proofGuestIndex($events, ['/usr/local/bin/converge-gateway.sh']))
        ->toBe([])
        ->and(proofIncusIndex($events, 'delete'))
        ->toBe([]);
});

it('records a diagnosis and keeps the topology when convergence, an action, or verification fails', function (
    string $case,
) {
    $fixture = proofFixture('diagnosis-'.$case);
    ['paths' => $paths, 'store' => $store, 'candidate' => $candidate, 'tree' => $tree] = $fixture;
    $target = featureTarget('NCK-123', 'b');
    $events = [];
    $redactor = new SecretRedactor(['configured-secret']);
    fakeProofProcesses(
        [$target],
        $fixture['generationId'],
        $candidate,
        $tree,
        $events,
        static fn (array $guest) => match (true) {
            $case === 'converge' && ($guest[0] ?? null) === '/usr/local/bin/converge-app-dev.sh' => Process::result(
                '',
                "Node provisioning failed at step [php] with error [apt.failed].\n",
                1,
            ),
            $case === 'setup' && $guest === ['touch', '/tmp/seeded'] => Process::result(
                str_repeat('x', 20_000),
                "token configured-secret\n",
                3,
            ),
            $case === 'acceptance' && $guest === PROOF_ACCEPTANCE_ARGV => Process::result(
                '',
                "gateway unreachable\n",
                2,
            ),
            $case === 'verify'
                && ($guest[0] ?? null) === '/usr/local/bin/verify-topology.sh'
                && $guest[1] === 'laravel.dev'
                => Process::result('', 'probe failed', 1),
            default => null,
        },
    );

    try {
        $result = proofRunner($fixture['repositoryRoot'], $paths, redactor: $redactor)->prove(
            new TopologyRequest('NCK-123', $fixture['worktree']),
            $candidate,
            proofPlan(),
        );
    } finally {
        Process::run(['git', '-C', $fixture['repositoryRoot'], 'worktree', 'remove', '--force', $fixture['worktree']]);
    }

    $topology = new TopologyManifestStore($store, $paths)->active('NCK-123');
    $record = $store->read('evidence/proofs/NCK-123/'.attemptId('b')->value.'.json');
    $journalText = (string) file_get_contents($paths->path('journals/'.str_repeat('a', 32).'.jsonl'));

    expect($result->status)
        ->toBe(ProofStatus::Diagnosis)
        ->and($record['status'] ?? null)
        ->toBe('diagnosis')
        ->and($topology?->attempt->value)
        ->toBe(attemptId('b')->value)
        ->and($topology?->verification->toArray())
        ->toBe($result->verification->toArray())
        ->and($store->read('leases/NCK-123.json')['state'] ?? null)
        ->toBe('ready')
        ->and(new ProofRecordReader($store)->isProved('NCK-123', attemptId('b')))
        ->toBeFalse()
        ->and(proofIncusIndex($events, 'delete'))
        ->toBe([]);

    match ($case) {
        'converge' => expect(array_keys($result->verification->probes))
            ->toBe(['proof.converge'])
            ->and($result->verification->probes['proof.converge']['observed'])
            ->toContain('converge-app-dev.sh failed', 'at step php (apt.failed)')
            ->and($result->setupResults)
            ->toBe([]),
        'setup' => expect(array_keys($result->verification->probes))
            ->toBe(['proof.setup'])
            ->and($result->setupResults[0])
            ->toMatchArray(['id' => 'seed', 'exit_code' => 3, 'stderr' => "token [REDACTED]\n"])
            ->and(strlen($result->setupResults[0]['stdout']))
            ->toBe(\App\E2E\Value\ProofResult::OUTPUT_LIMIT)
            ->and($result->acceptanceResults)
            ->toBe([])
            ->and(proofGuestIndex($events, PROOF_ACCEPTANCE_ARGV))
            ->toBe([])
            ->and($journalText)
            ->not->toContain('configured-secret'),
        'acceptance' => expect(array_keys($result->verification->probes))
            ->toBe(['proof.acceptance'])
            ->and(array_column($result->setupResults, 'exit_code'))
            ->toBe([0])
            ->and(array_column($result->acceptanceResults, 'exit_code'))
            ->toBe([2])
            ->and(proofGuestIndex($events, ['systemctl', 'is-active', 'orbit-gateway']))
            ->toBe([]),
        'verify' => expect($result->verification->passed)
            ->toBeFalse()
            ->and($result->verification->probes['laravel.dev']['passed'])
            ->toBeFalse()
            ->and(array_column($result->acceptanceResults, 'exit_code'))
            ->toBe([0, 0]),
    };
})->with(['converge', 'setup', 'acceptance', 'verify']);

it('keeps a proved attempt immutable, read-only verifiable, and one-way diagnosable', function () {
    $fixture = proofFixture('immutable');
    ['paths' => $paths, 'store' => $store, 'candidate' => $candidate, 'tree' => $tree] = $fixture;
    $target = featureTarget('NCK-123', 'b');
    $events = [];
    fakeProofProcesses([$target], $fixture['generationId'], $candidate, $tree, $events);
    $runner = proofRunner($fixture['repositoryRoot'], $paths);
    $acquirer = proofAcquirer($fixture['repositoryRoot'], $paths);
    $manifests = new TopologyManifestStore($store, $paths);

    try {
        $proved = $runner->prove(new TopologyRequest('NCK-123', $fixture['worktree']), $candidate, proofPlan());
        $recordPath = $paths->path('topologies/NCK-123/'.attemptId('b')->value.'.json');
        $before = hash_file('sha256', $recordPath);
        $events = [];

        expect($proved->status)
            ->toBe(ProofStatus::Proved)
            ->and(fn () => $acquirer->sync('NCK-123', attemptId('b'), $fixture['worktree']))
            ->toThrow(RuntimeException::class, 'proved and cannot be changed')
            ->and(fn () => $acquirer->execute('NCK-123', attemptId('b'), 'gateway', ['true']))
            ->toThrow(RuntimeException::class, 'proved and cannot be changed')
            ->and(proofGuestIndex($events, ['/usr/local/bin/receive-source.sh']))
            ->toBe([])
            ->and(proofGuestIndex($events, ['true']))
            ->toBe([]);

        $verified = $acquirer->verify('NCK-123', attemptId('b'));

        expect($verified->verification->passed)
            ->toBeTrue()
            ->and(proofGuestIndex($events, ['/usr/local/bin/verify-topology.sh']))
            ->not
            ->toBe([])
            ->and(hash_file('sha256', $recordPath))
            ->toBe($before)
            ->and($manifests->read('NCK-123', attemptId('b'))?->purpose)
            ->toBe(AttemptPurpose::Proof)
            ->and(fn () => $runner->diagnose('NCK-123', attemptId('c')))
            ->toThrow(RuntimeException::class, 'not the active topology attempt');

        $diagnosis = $runner->diagnose('NCK-123', attemptId('b'));

        expect($diagnosis->status)
            ->toBe(ProofStatus::Diagnosis)
            ->and(new ProofStore($store)->read('NCK-123', attemptId('b'))?->status)
            ->toBe(ProofStatus::Diagnosis)
            ->and(fn () => $runner->diagnose('NCK-123', attemptId('b')))
            ->toThrow(RuntimeException::class, 'not proved')
            ->and($manifests->active('NCK-123')?->attempt->value)
            ->toBe(attemptId('b')->value)
            ->and(fn () => $acquirer->execute('NCK-123', attemptId('b'), 'gateway', ['true']))
            ->not->toThrow(RuntimeException::class);
    } finally {
        Process::run(['git', '-C', $fixture['repositoryRoot'], 'worktree', 'remove', '--force', $fixture['worktree']]);
    }
});

it('release before merge makes the proof inactive while its record and receipt are retained', function () {
    $fixture = proofFixture('release');
    ['paths' => $paths, 'store' => $store, 'candidate' => $candidate, 'tree' => $tree] = $fixture;
    $target = featureTarget('NCK-123', 'b');
    $events = [];
    fakeProofProcesses([$target], $fixture['generationId'], $candidate, $tree, $events);
    $runner = proofRunner($fixture['repositoryRoot'], $paths);

    try {
        $proved = $runner->prove(new TopologyRequest('NCK-123', $fixture['worktree']), $candidate, proofPlan());
        $released = proofReleaser($paths)->release('NCK-123', attemptId('b'));
    } finally {
        Process::run(['git', '-C', $fixture['repositoryRoot'], 'worktree', 'remove', '--force', $fixture['worktree']]);
    }

    expect($proved->status)
        ->toBe(ProofStatus::Proved)
        ->and($released->purpose)
        ->toBe(AttemptPurpose::Proof)
        ->and($released->verifiedAbsent)
        ->toContain($target->instance('gateway'), $target->network())
        ->and(
            new ReleaseReceiptStore($store, $paths)
                ->read('NCK-123', attemptId('b'))
                ?->toArray(),
        )
        ->toBe($released->toArray())
        ->and(new ReleaseReceiptStore($store, $paths)->latestDiscovery('NCK-123')?->attempt->value)
        ->toBe(attemptId('a')->value)
        ->and(new TopologyManifestStore($store, $paths)->active('NCK-123'))
        ->toBeNull()
        ->and($store->read('leases/NCK-123.json'))
        ->toBeNull()
        ->and(
            new ProofStore($store)
                ->read('NCK-123', attemptId('b'))
                ?->toArray(),
        )
        ->toBe($proved->toArray())
        ->and(fn () => $runner->diagnose('NCK-123', attemptId('b')))
        ->toThrow(RuntimeException::class, 'not the active topology attempt')
        ->and(new ProofStore($store)->read('NCK-123', attemptId('b'))?->status)
        ->toBe(ProofStatus::Proved);
});
