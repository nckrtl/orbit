<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\IssueState;
use App\E2E\OrphanNetworkSweep;
use App\E2E\PreparedStateFingerprint;
use App\E2E\StandbyManifestStore;
use App\E2E\StandbyPromoter;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\TopologyReleaser;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofPlan;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationReport;
use Illuminate\Container\Container;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

require_once __DIR__.'/Support/TopologyFixtures.php';

beforeEach(function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

/**
 * A fixture repository whose `main` holds the proved candidate, a worktree
 * with a proved proof attempt under `.e2e/`, and a promoted old generation.
 *
 * @return array{root: string, worktree: string, paths: StatePaths, manifests: StandbyManifestStore, request: TopologyRequest, target: TopologyTarget, candidate: string, plan: ProofPlan}
 */
function promotableFixture(bool $mainHoldsCandidate = true, AttemptPurpose $purpose = AttemptPurpose::Proof): array
{
    $root = preparedTopologyRepository();
    $worktree = pinnedFeatureWorktree($root, 'promote');
    $candidate = new GitRepository($worktree)->commit();
    if ($mainHoldsCandidate) {
        Process::run(['git', '-C', $root, 'branch', '-f', 'main', $candidate])->throw();
    }
    $paths = new StatePaths(temporaryPath('orbit-promote-state-', 4));
    promoteDiscoveryGeneration($root, $paths);
    $manifests = new StandbyManifestStore(new AtomicJsonStore($paths), $paths, new IncusHost);
    $promoted = $manifests->promoted();
    assert($promoted !== null);

    $target = TopologyTarget::feature('NCK-123', new AttemptId(str_repeat('a', 32)));
    $state = IssueState::forWorktree('NCK-123', $worktree);
    $operation = new OperationId(str_repeat('b', 32));
    $state->writeAttempt($target->requireAttempt(), $purpose, $operation);
    $state->writeTopology(new FeatureTopology(
        $target,
        $purpose,
        $promoted,
        $target->network(),
        array_combine(TopologyProfile::ROLES, array_map($target->instance(...), TopologyProfile::ROLES)),
        new SourceState($candidate, $candidate, operationId: $operation->value),
        new VerificationReport(true, [
            'proof.verify' => [
                'passed' => true,
                'checked_at' => '2026-08-30T00:00:00Z',
                'expected' => 'healthy',
                'observed' => 'healthy',
                'evidence_ref' => 'incus://'.$target->instance('gateway').'/proof.verify',
            ],
        ]),
    ));
    if ($purpose === AttemptPurpose::Proof) {
        $state->writeProof([
            'status' => 'proved',
            'issue' => 'NCK-123',
            'attempt_id' => $target->requireAttempt()->value,
            'candidate_sha' => $candidate,
            'actions' => [],
            'recorded_at' => '2026-08-30T00:00:00Z',
        ]);
    }
    $planPath = $worktree.'/proofs/NCK-123.json';
    mkdir(dirname($planPath), 0700, true);
    file_put_contents($planPath, json_encode([
        'setup' => [],
        'acceptance' => [[
            'id' => 'doctor',
            'node' => 'app-dev',
            'argv' => ['orbit', 'doctor'],
            'timeout_seconds' => 60,
        ]],
    ], JSON_THROW_ON_ERROR));

    return [
        'root' => $root,
        'worktree' => $worktree,
        'paths' => $paths,
        'manifests' => $manifests,
        'request' => new TopologyRequest('NCK-123', $worktree),
        'target' => $target,
        'candidate' => $candidate,
        'plan' => ProofPlan::fromFile($planPath),
    ];
}

function promoterFor(string $root, StatePaths $paths, StandbyManifestStore $manifests): StandbyPromoter
{
    $host = new IncusHost(pool: 'default');
    $operation = new OperationId(str_repeat('c', 32));

    return new StandbyPromoter(
        $host,
        new PreparedStateFingerprint(new GitRepository($root)),
        $manifests,
        new TopologyReleaser(
            $host,
            new IncusNetworkLifecycle($host),
            $paths,
            $operation,
            new OrphanNetworkSweep($host, new IncusNetworkLifecycle($host), $paths, $operation),
        ),
        new OperationLock($paths),
        new OperationLock($paths),
        $paths,
        new GitRepository($root),
        $operation,
    );
}

/**
 * A stateful Incus fake: the standby instances, the proved attempt's instances,
 * and both networks, mutated by every command promote and release issue.
 *
 * @param list<string> $events
 * @mago-expect lint:cyclomatic-complexity,halstead,kan-defect The fake maps one complete promotion process boundary.
 */
function fakePromotionHost(
    TopologyTarget $target,
    array &$events,
    ?string $failAt = null,
    ?array &$guestEvents = null,
): void {
    $standby = TopologyTarget::standby();
    $instances = [];
    $snapshots = [];
    foreach (TopologyProfile::ROLES as $role) {
        $instances[$standby->instance($role)] = [
            'status' => 'Stopped',
            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e', 'user.orbit.e2e.operation' => 'old-op'],
            'network' => $standby->network(),
        ];
        $snapshots[$standby->instance($role)] = ['main-'.$role];
        $instances[$target->instance($role)] = [
            'status' => 'Running',
            'config' => [
                'user.orbit.e2e.owner' => 'orbit-e2e',
                'user.orbit.e2e.issue' => $target->issue,
                'user.orbit.e2e.attempt' => $target->requireAttempt()->value,
                'user.orbit.e2e.operation' => str_repeat('b', 32),
                'user.orbit.e2e.generation' => 'old',
            ],
            'network' => $target->network(),
        ];
        $snapshots[$target->instance($role)] = [];
    }
    $networks = [
        $standby->network() => ['config' => ['user.orbit.e2e.owner' => 'orbit-e2e', 'ipv4.address' => '10.232.1.1/24']],
        $target->network() => ['config' => [
            'user.orbit.e2e.owner' => 'orbit-e2e',
            'user.orbit.e2e.issue' => $target->issue,
            'user.orbit.e2e.attempt' => $target->requireAttempt()->value,
            'ipv4.address' => '10.232.2.1/24',
        ]],
    ];
    $realProcess = new ProcessFactory;
    $vm = static fn (string $name, array $instance): array => [
        'name' => $name,
        'type' => 'virtual-machine',
        'status' => $instance['status'],
        'status_code' => $instance['status'] === 'Running' ? 103 : 102,
        'config' => $instance['config'],
        'devices' => ['root' => ['pool' => 'default'], 'eth0' => ['network' => $instance['network']]],
    ];

    Process::fake(function (PendingProcess $process) use (
        &$events,
        &$guestEvents,
        &$instances,
        &$snapshots,
        &$networks,
        $realProcess,
        $vm,
        $failAt,
    ): ProcessResult {
        $command = $process->command;
        assert(is_array($command));
        if (($firewall = topologyFirewallResult($command)) !== null) {
            return $firewall;
        }
        if (($batch = pinnedWorktreeBatchResult($process, $guestEvents)) !== null) {
            return $batch;
        }
        if (($command[0] ?? null) === 'git') {
            return $realProcess
                ->path((string) ($process->path ?: getcwd()))
                ->input($process->input)
                ->run($command);
        }
        $name = static fn (?string $value): string => (string) preg_replace('/\A[^:]+:/', '', (string) $value);
        $action = $command[3] ?? null;
        if ($action === 'list') {
            $wanted = $name($command[4] ?? '');
            $listed = [];
            foreach ($instances as $instanceName => $instance) {
                if ($wanted === '' || $wanted === $instanceName) {
                    $listed[] = $vm($instanceName, $instance);
                }
            }

            return Process::result(json_encode($listed, JSON_THROW_ON_ERROR));
        }
        if ($action === 'network' && ($command[4] ?? null) === 'list') {
            $listed = [];
            foreach ($networks as $networkName => $network) {
                $usedBy = [];
                foreach ($instances as $instanceName => $instance) {
                    if ($instance['network'] === $networkName) {
                        $usedBy[] = '/1.0/instances/'.$instanceName;
                    }
                }
                $listed[] = ['name' => $networkName, 'config' => $network['config'], 'used_by' => $usedBy];
            }

            return Process::result(json_encode($listed, JSON_THROW_ON_ERROR));
        }
        if ($action === 'network' && ($command[4] ?? null) === 'delete') {
            $events[] = 'network-delete:'.$name($command[5] ?? '');
            unset($networks[$name($command[5] ?? '')]);

            return Process::result();
        }
        if ($action === 'snapshot' && ($command[4] ?? null) === 'list') {
            $instance = $name($command[5] ?? '');

            return Process::result(json_encode(array_map(
                static fn (string $snapshot): array => [
                    'name' => $snapshot,
                    'created_at' => '2026-01-01T00:00:00Z',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ],
                $snapshots[$instance] ?? [],
            ), JSON_THROW_ON_ERROR));
        }
        if ($action === 'snapshot' && ($command[4] ?? null) === 'create') {
            $instance = $name($command[5] ?? '');
            $events[] = "snapshot:{$instance}/{$command[6]}";
            if ($failAt === 'snapshot' && str_ends_with($instance, 'app-prod-next')) {
                return Process::result('', 'controlled snapshot failure', 1);
            }
            $snapshots[$instance][] = $command[6];

            return Process::result();
        }
        if ($action === 'stop') {
            $instance = $name($command[4] ?? '');
            $events[] = 'stop:'.$instance;
            $instances[$instance]['status'] = 'Stopped';

            return Process::result();
        }
        if ($action === 'copy') {
            $source = $name($command[4] ?? '');
            $copy = $name($command[5] ?? '');
            $events[] = "copy:{$source}>{$copy}".(in_array('--instance-only', $command, true) ? ' instance-only' : '');
            $config = $instances[$source]['config'];
            $network = $instances[$source]['network'];
            foreach ($command as $index => $argument) {
                if ($argument === '--config' && str_starts_with((string) $command[$index + 1], 'user.')) {
                    [$key, $value] = explode('=', (string) $command[$index + 1], 2);
                    $config[$key] = $value;
                }
                if ($argument === '--device' && str_starts_with((string) $command[$index + 1], 'eth0,network=')) {
                    $network = substr((string) $command[$index + 1], strlen('eth0,network='));
                }
            }
            $instances[$copy] = ['status' => 'Stopped', 'config' => $config, 'network' => $network];
            $snapshots[$copy] = [];

            return Process::result();
        }
        if ($action === 'config' && ($command[4] ?? null) === 'unset') {
            $instance = $name($command[5] ?? '');
            $events[] = "unset:{$instance}/{$command[6]}";
            unset($instances[$instance]['config'][$command[6]]);

            return Process::result();
        }
        if ($action === 'delete') {
            $instance = $name($command[4] ?? '');
            $events[] = 'delete:'.$instance;
            unset($instances[$instance], $snapshots[$instance]);

            return Process::result();
        }
        if ($action === 'rename') {
            $from = $name($command[4] ?? '');
            $to = (string) $command[5];
            $events[] = "rename:{$from}>{$to}";
            $instances[$to] = $instances[$from];
            $snapshots[$to] = $snapshots[$from];
            unset($instances[$from], $snapshots[$from]);

            return Process::result();
        }

        throw new RuntimeException('Unexpected Incus command: '.implode(' ', $command));
    });
}

/** @mago-expect lint:kan-defect The promotion test asserts the complete ordered command chain. */
describe('StandbyPromoter', function (): void {
    /** @mago-expect lint:kan-defect The promotion test asserts the complete ordered command chain. */
    it('replaces the standby with the proved topology, promotes the manifest, and releases the attempt', function (): void {
        $fixture = promotableFixture();
        $target = $fixture['target'];
        $standby = TopologyTarget::standby();
        $events = [];
        $guestEvents = [];
        fakePromotionHost($target, $events, null, $guestEvents);
        $old = $fixture['manifests']->promoted();

        $result = promoterFor($fixture['root'], $fixture['paths'], $fixture['manifests'])
            ->promote($fixture['request'], $fixture['plan']);

        $fingerprint = topologyFinalPreparedFingerprint($fixture['root'], $fixture['candidate']);
        $expectedId = substr($fixture['candidate'], 0, 12).'-'.substr($fingerprint->value, 0, 12);
        $promoted = $fixture['manifests']->promoted();
        expect($result['state'])
            ->toBe('promoted')
            ->and($result['generation_id'])
            ->toBe($expectedId)
            ->and($result['main_sha'])
            ->toBe($fixture['candidate'])
            ->and($result['previous_generation_id'])
            ->toBe($old?->id)
            ->and($promoted?->id)
            ->toBe($expectedId)
            ->and($promoted?->snapshots)
            ->toBe(array_fill_keys(TopologyProfile::ROLES, 'main-'.$expectedId))
            ->and($promoted?->laravel->tag)
            ->toBe($old?->laravel->tag)
            ->and($promoted?->structuralFingerprint)
            ->toBe(new PreparedStateFingerprint(new GitRepository($fixture['root']))->forCommit(
                $fixture['candidate'],
            )->value)
            ->and($fixture['manifests']->recorded())
            ->toHaveCount(1)
            ->and(IssueState::forWorktree('NCK-123', $fixture['worktree'])->hasAttempt())
            ->toBeFalse();

        $expected = [];
        foreach (TopologyProfile::ROLES as $role) {
            $expected[] = 'stop:'.$target->instance($role);
        }
        foreach (TopologyProfile::ROLES as $role) {
            $expected[] = 'copy:'.$target->instance($role).'>'.$standby->instance($role).'-next instance-only';
        }
        foreach (TopologyProfile::ROLES as $role) {
            foreach (['issue', 'attempt', 'generation'] as $key) {
                $expected[] = 'unset:'.$standby->instance($role)."-next/user.orbit.e2e.{$key}";
            }
        }
        foreach (TopologyProfile::ROLES as $role) {
            $expected[] = 'snapshot:'.$standby->instance($role)."-next/main-{$expectedId}";
        }
        foreach (TopologyProfile::ROLES as $role) {
            $expected[] = 'delete:'.$standby->instance($role);
            $expected[] = 'rename:'.$standby->instance($role).'-next>'.$standby->instance($role);
        }
        foreach (array_reverse(TopologyProfile::ROLES) as $role) {
            $expected[] = 'delete:'.$target->instance($role);
        }
        $expected[] = 'network-delete:'.$target->network();
        $removals = array_values(array_filter(
            $guestEvents,
            static fn (array $event): bool => in_array('rm', $event, true),
        ));
        expect($removals)->toHaveCount(3)->and($removals[0])->toContain('/var/lib/orbit-e2e/proof');

        expect($events)->toBe($expected);
    });

    it('discards the copies and keeps the standby when the snapshot fails before the swap', function (): void {
        $fixture = promotableFixture();
        $events = [];
        fakePromotionHost($fixture['target'], $events, failAt: 'snapshot');
        $old = $fixture['manifests']->promoted();

        expect(fn () => promoterFor($fixture['root'], $fixture['paths'], $fixture['manifests'])
            ->promote($fixture['request'], $fixture['plan']))
            ->toThrow(RuntimeException::class, 'Standby promotion failed before the swap');

        $deleted = array_values(array_filter($events, static fn (string $event): bool => str_starts_with(
            $event,
            'delete:',
        )));
        expect($deleted)
            ->toBe(array_map(
                static fn (string $role): string => 'delete:'.TopologyTarget::standby()->instance($role).'-next',
                TopologyProfile::ROLES,
            ))
            ->and($events)
            ->not
            ->toContain('delete:'.TopologyTarget::standby()->instance('gateway'))
            ->and($fixture['manifests']->promoted()?->toArray())
            ->toBe($old?->toArray())
            ->and(IssueState::forWorktree('NCK-123', $fixture['worktree'])->hasAttempt())
            ->toBeTrue();
    });

    it('refuses a topology that is not proved without touching Incus', function (): void {
        $fixture = promotableFixture(purpose: AttemptPurpose::Discovery);
        $events = [];
        fakePromotionHost($fixture['target'], $events);

        expect(fn () => promoterFor($fixture['root'], $fixture['paths'], $fixture['manifests'])
            ->promote($fixture['request'], $fixture['plan']))
            ->toThrow(RuntimeException::class, 'is not proved');
        expect($events)->toBe([]);
        Process::assertDidntRun(fn (PendingProcess $process): bool => ($process->command[0] ?? null) === 'incus');
    });

    it('refuses a plan that mutates the topology without touching Incus', function (): void {
        $fixture = promotableFixture();
        $events = [];
        fakePromotionHost($fixture['target'], $events);
        $plan = ProofPlan::fromArray($fixture['plan']->toArray() + ['mutates' => true]);

        expect(fn () => promoterFor($fixture['root'], $fixture['paths'], $fixture['manifests'])
            ->promote($fixture['request'], $plan))
            ->toThrow(RuntimeException::class, 'mutates: true');
        Process::assertDidntRun(fn (PendingProcess $process): bool => ($process->command[0] ?? null) === 'incus');
    });

    it('refuses a candidate that main does not hold without touching Incus', function (): void {
        $fixture = promotableFixture(mainHoldsCandidate: false);
        $events = [];
        fakePromotionHost($fixture['target'], $events);

        expect(fn () => promoterFor($fixture['root'], $fixture['paths'], $fixture['manifests'])
            ->promote($fixture['request'], $fixture['plan']))
            ->toThrow(RuntimeException::class, 'does not hold the proved candidate');
        Process::assertDidntRun(fn (PendingProcess $process): bool => ($process->command[0] ?? null) === 'incus');
    });
});
