<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use App\E2E\IssueState;
use App\E2E\ProofCloseoutService;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\SecretRedactor;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\CapturedProof;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofCloseoutRecord;
use App\E2E\Value\ProofInputManifest;
use App\E2E\Value\ProofReviewAction;
use App\E2E\Value\ProofReviewEvaluation;
use App\E2E\Value\ProofReviewRecord;
use App\E2E\Value\RefreshResult;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyConstructionInputs;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationReport;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

/** @return array{primary:string,worktree:string,candidate:string,artifact:string,merge:string,main:string} */
function closeoutGitFixture(): array
{
    $primary = temporaryPath('orbit-closeout-primary-', 4);
    $worktree = temporaryPath('orbit-closeout-worktree-', 4);
    mkdir($primary, 0700);
    foreach ([
        ['git', 'init', '-q', '-b', 'main', $primary],
        ['git', '-C', $primary, 'config', 'user.email', 'developer@example.com'],
        ['git', '-C', $primary, 'config', 'user.name', 'Orbit Developer'],
    ] as $command) {
        expect(Process::run($command)->successful())->toBeTrue();
    }
    file_put_contents($primary.'/.gitignore', ".e2e/\n.loop/\n");
    file_put_contents($primary.'/base.txt', "base\n");
    expect(Process::run(['git', '-C', $primary, 'add', '.'])->successful())->toBeTrue();
    expect(Process::run(['git', '-C', $primary, 'commit', '-q', '-m', 'Base'])->successful())->toBeTrue();
    expect(Process::run([
        'git', '-C', $primary, 'worktree', 'add', '-q', '-b', 'orb-230', $worktree, 'HEAD',
    ])->successful())->toBeTrue();
    file_put_contents($worktree.'/feature.txt', "feature\n");
    expect(Process::run(['git', '-C', $worktree, 'add', 'feature.txt'])->successful())->toBeTrue();
    expect(Process::run(['git', '-C', $worktree, 'commit', '-q', '-m', 'Feature'])->successful())->toBeTrue();
    $candidate = (new GitRepository($worktree))->commit();

    expect(Process::run(['git', '-C', $primary, 'switch', '-q', '-c', 'artifact', $candidate])->successful())->toBeTrue();
    mkdir($primary.'/.loop', 0700);
    file_put_contents($primary.'/.loop/flow.json', "{\"schema\":1,\"flow\":\"proof\"}\n");
    expect(Process::run(['git', '-C', $primary, 'add', '-f', '.loop/flow.json'])->successful())->toBeTrue();
    expect(Process::run(['git', '-C', $primary, 'commit', '-q', '-m', 'Artifact'])->successful())->toBeTrue();
    $artifact = (new GitRepository($primary))->commit();
    expect(Process::run([
        'git', '-C', $primary, 'tag', 'loop/orb-230/'.$candidate, $artifact,
    ])->successful())->toBeTrue();
    expect(Process::run(['git', '-C', $primary, 'switch', '-q', 'main'])->successful())->toBeTrue();
    expect(Process::run([
        'git', '-C', $primary, 'merge', '-q', '--no-ff', 'orb-230', '-m', 'Merge ORB-230',
    ])->successful())->toBeTrue();
    $merge = (new GitRepository($primary))->commit();

    mkdir($worktree.'/.loop', 0700);
    file_put_contents($worktree.'/.loop/flow.json', "{\"schema\":1,\"flow\":\"proof\"}\n");

    return [
        'primary' => $primary,
        'worktree' => $worktree,
        'candidate' => $candidate,
        'artifact' => $artifact,
        'merge' => $merge,
        'main' => $merge,
    ];
}

function closeoutState(string $worktree, string $candidate, StatePaths $hostPaths): IssueState
{
    $issue = 'ORB-230';
    $attempt = attemptId('b');
    $target = TopologyTarget::feature($issue, $attempt);
    $generation = new TopologySnapshotGeneration(
        'fixture-generation',
        str_repeat('c', 40),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        str_repeat('d', 64),
        str_repeat('e', 64),
        new LaravelRelease('v13.10.1', str_repeat('f', 40)),
        str_repeat('1', 64),
        2,
        'ubuntu-26.04-amd64-v1',
        'orbit-base-ubuntu-26.04-runtime',
        'gateway_app-dev_app-prod',
        ['gateway', 'app-dev', 'app-prod'],
        ['gateway', 'app-dev'],
    );
    $construction = TopologyConstructionInputs::forGeneration($target, $generation->id, 2);
    $topology = new FeatureTopology(
        $construction,
        AttemptPurpose::Proof,
        $generation,
        new SourceState($candidate, $candidate),
        new VerificationReport(true, ['ready' => verificationProbeFixture()]),
    );
    $manifest = new ProofInputManifest(
        4,
        $candidate,
        str_repeat('2', 40),
        [],
        [],
        '.loop/proof/ORB-230.json',
        [],
        $construction,
        null,
        [
            'static_classification' => true,
            'proof_contract' => true,
            'checkout_literals' => true,
            'observed_processes' => true,
            'observed_paths' => true,
            'pcov_cleanup' => true,
        ],
    );
    $proof = [
        'status' => 'proved',
        'issue' => $issue,
        'attempt_id' => $attempt->value,
        'candidate_sha' => $candidate,
        'plan_sha256' => str_repeat('3', 64),
        'manifest_sha256' => $manifest->fingerprint(),
        'actions' => [],
    ];
    $capture = new CapturedProof(
        $issue,
        $attempt,
        $candidate,
        str_repeat('3', 64),
        $manifest->fingerprint(),
        $proof,
        $topology,
        $manifest->toArray(),
        '2026-09-10T10:00:00Z',
    );
    $state = IssueState::forWorktree($issue, $worktree);
    $state->writeAttempt($attempt, AttemptPurpose::Proof, new OperationId(str_repeat('4', 32)));
    $state->writeTopology($topology);
    $state->writeProof($proof);
    $state->writeProofInputManifest($manifest->fingerprint(), $manifest->toArray());
    $state->captureProof($capture);
    $action = ProofReviewAction::incomplete(
        'inspect-gateway',
        'shell',
        'gateway',
        true,
        [],
        null,
        '2026-09-10T10:01:00Z',
    )->complete('passed', null, '', '', null, '2026-09-10T10:02:00Z');
    $record = ProofReviewRecord::empty(
        $issue,
        $candidate,
        $attempt,
        '2026-09-10T10:01:00Z',
    )->withAction($action, '2026-09-10T10:02:00Z');
    $state->writeReviewRecord($record);
    $evaluation = ProofReviewEvaluation::forRecord($record, '2026-09-10T10:03:00Z');
    $state->writeReviewEvaluation($evaluation);
    $archive = new AtomicJsonStore($hostPaths);
    $archive->write('proof-evidence/'.$issue.'/'.$attempt->value.'.json', $capture->toArray());
    $archive->write('proof-review/'.$issue.'/'.$attempt->value.'.json', $record->toArray());
    $archive->write('proof-review-evaluation/'.$issue.'/'.$attempt->value.'.json', $evaluation->toArray());

    return $state;
}

function closeoutService(
    array $git,
    StatePaths $hostPaths,
    Closure $refresh,
    Closure $release,
): ProofCloseoutService {
    return new ProofCloseoutService(
        new GitRepository($git['primary']),
        $hostPaths,
        new OperationId(str_repeat('5', 32)),
        new SecretRedactor(['closeout-secret']),
        $refresh,
        $release,
    );
}

it('retains the proof and records a redacted failed refresh for retry', function (): void {
    $git = closeoutGitFixture();
    $paths = new StatePaths(temporaryPath('orbit-closeout-host-', 4));
    $state = closeoutState($git['worktree'], $git['candidate'], $paths);
    $released = false;
    $service = closeoutService(
        $git,
        $paths,
        static fn (string $main): RefreshResult => new RefreshResult(
            'failed',
            str_repeat('6', 32),
            error: 'refresh exposed closeout-secret',
        ),
        static function () use (&$released): array {
            $released = true;

            return [];
        },
    );

    $result = $service->closeout(
        new TopologyRequest('ORB-230', $git['worktree']),
        $git['candidate'],
        $git['artifact'],
        $git['merge'],
        $git['main'],
    );

    expect($result->state)
        ->toBe('refresh-failed')
        ->and($result->error)
        ->toBe('refresh exposed [REDACTED]')
        ->and($released)
        ->toBeFalse()
        ->and($state->hasAttempt(AttemptPurpose::Proof))
        ->toBeTrue()
        ->and($state->capturedProof()?->candidateSha)
        ->toBe($git['candidate'])
        ->and(is_file($paths->path('proof-closeout/ORB-230/'.str_repeat('b', 32).'.json')))
        ->toBeTrue();
});

it('refreshes before exact cleanup and completes the retry-safe closeout record', function (): void {
    $git = closeoutGitFixture();
    $paths = new StatePaths(temporaryPath('orbit-closeout-host-', 4));
    $state = closeoutState($git['worktree'], $git['candidate'], $paths);
    $order = [];
    $service = closeoutService(
        $git,
        $paths,
        static function (string $main) use (&$order): RefreshResult {
            $order[] = 'refresh:'.$main;

            return new RefreshResult('promoted', str_repeat('6', 32), 'generation-1');
        },
        static function (TopologyRequest $request, CapturedProof $capture) use (&$order, $state): array {
            $order[] = 'release:'.$capture->attempt->value;
            $state->forgetAttempt(AttemptPurpose::Proof);

            return ['state' => 'released'];
        },
    );

    $result = $service->closeout(
        new TopologyRequest('ORB-230', $git['worktree']),
        $git['candidate'],
        $git['artifact'],
        $git['merge'],
        $git['main'],
    );

    expect($result->state)
        ->toBe('complete')
        ->and($result->generationId)
        ->toBe('generation-1')
        ->and($order)
        ->toBe([
            'refresh:'.$git['main'],
            'release:'.str_repeat('b', 32),
        ])
        ->and($state->hasAttempt(AttemptPurpose::Proof))
        ->toBeFalse()
        ->and($state->capturedProof()?->candidateSha)
        ->toBe($git['candidate']);
});

it('retries a failed refresh without releasing the retained attempt early', function (): void {
    $git = closeoutGitFixture();
    $paths = new StatePaths(temporaryPath('orbit-closeout-host-', 4));
    $state = closeoutState($git['worktree'], $git['candidate'], $paths);
    $refreshes = 0;
    $releases = 0;
    $service = closeoutService(
        $git,
        $paths,
        static function () use (&$refreshes): RefreshResult {
            $refreshes++;

            return $refreshes === 1
                ? new RefreshResult('failed', str_repeat('6', 32), error: 'temporary refresh failure')
                : new RefreshResult('unchanged', str_repeat('6', 32), 'generation-1');
        },
        static function (TopologyRequest $request, CapturedProof $capture) use (&$releases, $state): array {
            $releases++;
            $state->forgetAttempt(AttemptPurpose::Proof);

            return ['state' => 'released'];
        },
    );
    $request = new TopologyRequest('ORB-230', $git['worktree']);

    $first = $service->closeout(
        $request,
        $git['candidate'],
        $git['artifact'],
        $git['merge'],
        $git['main'],
    );
    expect($first->state)
        ->toBe('refresh-failed')
        ->and($state->hasAttempt(AttemptPurpose::Proof))
        ->toBeTrue()
        ->and($releases)
        ->toBe(0);

    $second = $service->closeout(
        $request,
        $git['candidate'],
        $git['artifact'],
        $git['merge'],
        $git['main'],
    );

    expect($second->state)
        ->toBe('complete')
        ->and($refreshes)
        ->toBe(2)
        ->and($releases)
        ->toBe(1)
        ->and($state->hasAttempt(AttemptPurpose::Proof))
        ->toBeFalse();
});

it('completes after cleanup removed the lease before the final record was written', function (): void {
    $git = closeoutGitFixture();
    $paths = new StatePaths(temporaryPath('orbit-closeout-host-', 4));
    $state = closeoutState($git['worktree'], $git['candidate'], $paths);
    $capture = $state->capturedProof() ?? throw new RuntimeException('Fixture capture is missing.');
    $refreshed = new ProofCloseoutRecord(
        'refresh-succeeded',
        'ORB-230',
        $capture->attempt,
        $git['candidate'],
        $git['artifact'],
        $git['merge'],
        $git['main'],
        'generation-1',
        null,
        '2026-09-10T10:04:00Z',
    );
    $state->writeCloseoutRecord($refreshed);
    new AtomicJsonStore($paths)->write(
        'proof-closeout/ORB-230/'.$capture->attempt->value.'.json',
        $refreshed->toArray(),
    );
    $state->forgetAttempt(AttemptPurpose::Proof);
    file_put_contents($git['primary'].'/main.txt', "new main after cleanup\n");
    Process::run(['git', '-C', $git['primary'], 'add', 'main.txt'])->throw();
    Process::run(['git', '-C', $git['primary'], 'commit', '-q', '-m', 'Advance main after cleanup'])->throw();
    $currentMain = new GitRepository($git['primary'])->commit();
    $releases = 0;
    $service = closeoutService(
        $git,
        $paths,
        static fn (): RefreshResult => throw new RuntimeException('refresh must not rerun'),
        static function (TopologyRequest $request, CapturedProof $released) use (&$releases, $capture): array {
            $releases++;
            expect($released->toArray())->toBe($capture->toArray());

            return ['state' => 'released'];
        },
    );

    $result = $service->closeout(
        new TopologyRequest('ORB-230', $git['worktree']),
        $git['candidate'],
        $git['artifact'],
        $git['merge'],
        $currentMain,
    );

    expect($result->state)
        ->toBe('complete')
        ->and($releases)
        ->toBe(1)
        ->and($result->mainSha)
        ->toBe($git['main'])
        ->and($state->closeoutRecord()?->state)
        ->toBe('complete');
});

it('restores a complete host closeout record after the local final write was lost', function (): void {
    $git = closeoutGitFixture();
    $paths = new StatePaths(temporaryPath('orbit-closeout-host-', 4));
    $state = closeoutState($git['worktree'], $git['candidate'], $paths);
    $capture = $state->capturedProof() ?? throw new RuntimeException('Fixture capture is missing.');
    $refreshed = new ProofCloseoutRecord(
        'refresh-succeeded',
        'ORB-230',
        $capture->attempt,
        $git['candidate'],
        $git['artifact'],
        $git['merge'],
        $git['main'],
        'generation-1',
        null,
        '2026-09-10T10:04:00Z',
    );
    $complete = new ProofCloseoutRecord(
        'complete',
        'ORB-230',
        $capture->attempt,
        $git['candidate'],
        $git['artifact'],
        $git['merge'],
        $git['main'],
        'generation-1',
        null,
        '2026-09-10T10:05:00Z',
    );
    $state->writeCloseoutRecord($refreshed);
    new AtomicJsonStore($paths)->write(
        'proof-closeout/ORB-230/'.$capture->attempt->value.'.json',
        $complete->toArray(),
    );
    $state->forgetAttempt(AttemptPurpose::Proof);
    $service = closeoutService(
        $git,
        $paths,
        static fn (): RefreshResult => throw new RuntimeException('refresh must not rerun'),
        static fn (): array => throw new RuntimeException('release must not rerun'),
    );

    $result = $service->closeout(
        new TopologyRequest('ORB-230', $git['worktree']),
        $git['candidate'],
        $git['artifact'],
        $git['merge'],
        $git['main'],
    );

    expect($result->toArray())
        ->toBe($complete->toArray())
        ->and($state->closeoutRecord()?->toArray())
        ->toBe($complete->toArray());
});

it('retries a failed refresh on newer clean main that still contains the merge', function (): void {
    $git = closeoutGitFixture();
    $paths = new StatePaths(temporaryPath('orbit-closeout-host-', 4));
    $state = closeoutState($git['worktree'], $git['candidate'], $paths);
    $refreshes = [];
    $service = closeoutService(
        $git,
        $paths,
        static function (string $main) use (&$refreshes): RefreshResult {
            $refreshes[] = $main;

            return count($refreshes) === 1
                ? new RefreshResult('failed', str_repeat('6', 32), error: 'temporary refresh failure')
                : new RefreshResult('promoted', str_repeat('6', 32), 'generation-2');
        },
        static function (TopologyRequest $request, CapturedProof $capture) use ($state): array {
            $state->forgetAttempt(AttemptPurpose::Proof);

            return ['state' => 'released'];
        },
    );
    $request = new TopologyRequest('ORB-230', $git['worktree']);
    expect($service->closeout(
        $request,
        $git['candidate'],
        $git['artifact'],
        $git['merge'],
        $git['main'],
    )->state)->toBe('refresh-failed');
    file_put_contents($git['primary'].'/main.txt', "new main\n");
    Process::run(['git', '-C', $git['primary'], 'add', 'main.txt'])->throw();
    Process::run(['git', '-C', $git['primary'], 'commit', '-q', '-m', 'Advance main'])->throw();
    $newMain = new GitRepository($git['primary'])->commit();

    $result = $service->closeout(
        $request,
        $git['candidate'],
        $git['artifact'],
        $git['merge'],
        $newMain,
    );

    expect($result->state)
        ->toBe('complete')
        ->and($result->mainSha)
        ->toBe($newMain)
        ->and($refreshes)
        ->toBe([$git['main'], $newMain]);
});

it('refuses a merge whose same-tree second parent is not the exact candidate', function (): void {
    $git = closeoutGitFixture();
    $paths = new StatePaths(temporaryPath('orbit-closeout-host-', 4));
    closeoutState($git['worktree'], $git['candidate'], $paths);
    $repository = new GitRepository($git['primary']);
    $candidateTree = $repository->tree($git['candidate']);
    $candidateParent = $repository->parents($git['candidate'])[0];
    $alternate = trim(Process::run([
        'git', '-C', $git['primary'], 'commit-tree', $candidateTree,
        '-p', $candidateParent, '-m', 'Same tree, different candidate',
    ])->throw()->output());
    $wrongMerge = trim(Process::run([
        'git', '-C', $git['primary'], 'commit-tree', $candidateTree,
        '-p', $candidateParent, '-p', $alternate, '-m', 'Wrong merge parent',
    ])->throw()->output());
    Process::run(['git', '-C', $git['primary'], 'reset', '--hard', $wrongMerge])->throw();
    $service = closeoutService(
        $git,
        $paths,
        static fn (): RefreshResult => throw new RuntimeException('refresh must not run'),
        static fn (): array => throw new RuntimeException('release must not run'),
    );

    expect(fn () => $service->closeout(
        new TopologyRequest('ORB-230', $git['worktree']),
        $git['candidate'],
        $git['artifact'],
        $wrongMerge,
        $wrongMerge,
    ))->toThrow(RuntimeException::class, 'not the exact accepted candidate');
});

it('refuses closeout when the review evaluation has a required failure', function (): void {
    $git = closeoutGitFixture();
    $paths = new StatePaths(temporaryPath('orbit-closeout-host-', 4));
    $state = closeoutState($git['worktree'], $git['candidate'], $paths);
    $failed = ProofReviewAction::incomplete(
        'inspect-gateway',
        'shell',
        'gateway',
        true,
        [],
        null,
        '2026-09-10T10:01:00Z',
    )->complete('failed', null, '', '', 'must fix', '2026-09-10T10:04:00Z');
    $failedRecord = ProofReviewRecord::empty(
        'ORB-230',
        $git['candidate'],
        attemptId('b'),
        '2026-09-10T10:01:00Z',
    )->withAction($failed, '2026-09-10T10:04:00Z');
    // Build a fresh state because completed action history is immutable.
    unlink($git['worktree'].'/.e2e/proof-review/'.str_repeat('b', 32).'.json');
    unlink($git['worktree'].'/.e2e/proof-review-evaluation/'.str_repeat('b', 32).'.json');
    $state->writeReviewRecord($failedRecord);
    $failedEvaluation = ProofReviewEvaluation::forRecord($failedRecord, '2026-09-10T10:05:00Z');
    $state->writeReviewEvaluation($failedEvaluation);
    $archive = new AtomicJsonStore($paths);
    $archive->write('proof-review/ORB-230/'.str_repeat('b', 32).'.json', $failedRecord->toArray());
    $archive->write(
        'proof-review-evaluation/ORB-230/'.str_repeat('b', 32).'.json',
        $failedEvaluation->toArray(),
    );
    $service = closeoutService(
        $git,
        $paths,
        static fn (): RefreshResult => throw new RuntimeException('refresh must not run'),
        static fn (): array => throw new RuntimeException('release must not run'),
    );

    expect(fn () => $service->closeout(
        new TopologyRequest('ORB-230', $git['worktree']),
        $git['candidate'],
        $git['artifact'],
        $git['merge'],
        $git['main'],
    ))->toThrow(RuntimeException::class, 'ready proof review evaluation');
});
