<?php

declare(strict_types=1);

use App\E2E\IssueState;
use App\E2E\Value\AttemptId;
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
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyConstructionInputs;
use App\E2E\Value\TopologyExtension;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationReport;

function issueStateTopology(
    string $issue,
    AttemptId $attempt,
    AttemptPurpose $purpose = AttemptPurpose::Discovery,
    ?TopologyExtension $extension = null,
): FeatureTopology {
    $target = TopologyTarget::feature(
        $issue,
        $attempt,
        $extension?->recipe() ?? TopologyRecipe::registered(),
    );
    $generation = new TopologySnapshotGeneration(
        'g-'.str_repeat('a', 12),
        str_repeat('b', 40),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        str_repeat('c', 64),
        str_repeat('d', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
        str_repeat('e', 64),
        2,
        'ubuntu-26.04-amd64-v1',
        'orbit-base-ubuntu-26.04-runtime',
        'gateway_app-dev_app-prod',
        ['gateway', 'app-dev', 'app-prod'],
        ['gateway', 'app-dev'],
    );

    return new FeatureTopology(
        TopologyConstructionInputs::create(
            $target,
            $generation,
            2,
            $extension,
            $extension === null ? null : str_repeat('f', 64),
        ),
        $purpose,
        $generation,
        new SourceState(str_repeat('d', 40), str_repeat('d', 40)),
        new VerificationReport(true, ['ready' => verificationProbeFixture(probe: 'ready')]),
    );
}

describe('IssueState', function () {
    it('keeps the attempt, topology, proof, and log under <worktree>/.e2e/', function () {
        $worktree = temporaryPath('orbit-issue-state-', 4);
        mkdir($worktree, 0700);
        $state = IssueState::forWorktree('TST-12', $worktree);
        $attempt = new AttemptId(str_repeat('a', 32));
        $operation = new OperationId(str_repeat('b', 32));

        expect($state->hasAttempt())
            ->toBeFalse()
            ->and(fn () => $state->attempt())
            ->toThrow(RuntimeException::class, 'TST-12 has no active attempt.')
            ->and($state->topology())
            ->toBeNull();

        $state->writeAttempt($attempt, AttemptPurpose::Discovery, $operation);
        $state->writeTopology(issueStateTopology('TST-12', $attempt));
        $state->writeProof(['status' => 'diagnosis', 'attempt_id' => $attempt->value]);
        $state->log("acquire attempt={$attempt->value}\nsecond line");

        expect($state->root())
            ->toBe($worktree.'/.e2e')
            ->and($state->hasAttempt())
            ->toBeTrue()
            ->and($state->attemptId()->value)
            ->toBe($attempt->value)
            ->and($state->operationId()->value)
            ->toBe($operation->value)
            ->and($state->attempt()['purpose'])
            ->toBe('discovery')
            ->and(array_key_exists('extension', $state->attempt()))
            ->toBeTrue()
            ->and($state->attempt()['extension'])
            ->toBeNull()
            ->and($state->requireTopology()->attempt->value)
            ->toBe($attempt->value)
            ->and($state->proof())
            ->toBe(['status' => 'diagnosis', 'attempt_id' => $attempt->value])
            ->and($state->isProved())
            ->toBeFalse()
            ->and(file_get_contents($worktree.'/.e2e/log'))
            ->toMatch('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z acquire attempt=a{32} second line\n\z/')
            ->and(array_values(array_diff(scandir($worktree.'/.e2e'), ['.', '..'])))
            ->toEqualCanonicalizing(['attempt.json', 'topology.json', 'proof.json', 'log']);
    });

    it('treats an attempt as proved only when the result names the active proof attempt', function () {
        $worktree = temporaryPath('orbit-issue-state-', 4);
        mkdir($worktree, 0700);
        $state = IssueState::forWorktree('TST-12', $worktree);
        $attempt = new AttemptId(str_repeat('a', 32));
        $state->writeProof(['status' => 'proved', 'attempt_id' => str_repeat('f', 32)]);
        $state->writeAttempt($attempt, AttemptPurpose::Proof, new OperationId(str_repeat('b', 32)));

        expect($state->isProved())->toBeFalse();

        $state->writeProof(['status' => 'proved', 'attempt_id' => $attempt->value]);

        expect($state->isProved())->toBeTrue();

        $state->forgetAttempt();

        expect($state->hasAttempt())
            ->toBeFalse()
            ->and($state->topology())
            ->toBeNull()
            ->and($state->proof()['status'] ?? null)
            ->toBe('proved')
            ->and($state->isProved())
            ->toBeFalse();
    });

    it('keeps discovery and proof attempts independently', function () {
        $worktree = temporaryPath('orbit-issue-state-', 4);
        mkdir($worktree, 0700);
        $state = IssueState::forWorktree('AUX-7', $worktree);
        $discovery = new AttemptId(str_repeat('a', 32));
        $proof = new AttemptId(str_repeat('b', 32));

        $state->writeAttempt($discovery, AttemptPurpose::Discovery, new OperationId(str_repeat('c', 32)));
        $state->writeTopology(issueStateTopology('AUX-7', $discovery));
        $state->writeAttempt($proof, AttemptPurpose::Proof, new OperationId(str_repeat('d', 32)));
        $state->writeTopology(issueStateTopology('AUX-7', $proof, AttemptPurpose::Proof));
        $state->writeProof(['status' => 'diagnosis', 'attempt_id' => $proof->value]);

        expect($state->attemptId(AttemptPurpose::Discovery)->value)
            ->toBe($discovery->value)
            ->and($state->attemptId(AttemptPurpose::Proof)->value)
            ->toBe($proof->value)
            ->and($state->requireTopology(AttemptPurpose::Discovery)->purpose)
            ->toBe(AttemptPurpose::Discovery)
            ->and($state->requireTopology(AttemptPurpose::Proof)->purpose)
            ->toBe(AttemptPurpose::Proof);

        $state->forgetAttempt(AttemptPurpose::Proof);

        expect($state->hasAttempt(AttemptPurpose::Discovery))
            ->toBeTrue()
            ->and($state->hasAttempt(AttemptPurpose::Proof))
            ->toBeFalse()
            ->and($state->requireTopology(AttemptPurpose::Discovery)->attempt->value)
            ->toBe($discovery->value)
            ->and($state->proof()['status'] ?? null)
            ->toBe('diagnosis');
    });

    it('keeps candidate convergence independent from retained proof and discovery', function (): void {
        $worktree = temporaryPath('orbit-issue-state-', 4);
        mkdir($worktree, 0700);
        $state = IssueState::forWorktree('AUX-7', $worktree);
        $discovery = new AttemptId(str_repeat('a', 32));
        $proof = new AttemptId(str_repeat('b', 32));
        $candidate = new AttemptId(str_repeat('c', 32));
        $state->writeAttempt($discovery, AttemptPurpose::Discovery, new OperationId(str_repeat('d', 32)));
        $state->writeAttempt($proof, AttemptPurpose::Proof, new OperationId(str_repeat('e', 32)));
        $state->writeAttempt($candidate, AttemptPurpose::CandidateConvergence, new OperationId(str_repeat('f', 32)));
        $state->writeTopology(issueStateTopology('AUX-7', $candidate, AttemptPurpose::CandidateConvergence));

        expect($state->attemptId(AttemptPurpose::CandidateConvergence)->value)
            ->toBe($candidate->value)
            ->and($state->requireTopology(AttemptPurpose::CandidateConvergence)->purpose)
            ->toBe(AttemptPurpose::CandidateConvergence)
            ->and(fn () => $state->attempt())
            ->toThrow(RuntimeException::class, 'AUX-7 has multiple attempts; select one.');

        $state->forgetAttempt(AttemptPurpose::CandidateConvergence);

        expect($state->hasAttempt(AttemptPurpose::CandidateConvergence))
            ->toBeFalse()
            ->and($state->hasAttempt(AttemptPurpose::Proof))
            ->toBeTrue()
            ->and($state->hasAttempt(AttemptPurpose::Discovery))
            ->toBeTrue();
    });

    it('rejects a lease or record that names another issue or attempt', function () {
        $worktree = temporaryPath('orbit-issue-state-', 4);
        mkdir($worktree, 0700);
        $attempt = new AttemptId(str_repeat('a', 32));
        IssueState::forWorktree('TST-12', $worktree)
            ->writeAttempt($attempt, AttemptPurpose::Discovery, new OperationId(str_repeat('b', 32)));
        IssueState::forWorktree('TST-12', $worktree)
            ->writeTopology(issueStateTopology('TST-12', new AttemptId(str_repeat('c', 32))));

        expect(fn () => IssueState::forWorktree('TST-13', $worktree)->attempt())
            ->toThrow(RuntimeException::class, 'lease is invalid')
            ->and(fn () => IssueState::forWorktree('TST-13', $worktree)->topology())
            ->toThrow(RuntimeException::class, 'another issue')
            ->and(fn () => IssueState::forWorktree('TST-12', $worktree)->requireTopology())
            ->toThrow(RuntimeException::class, 'name different attempts');
    });

    it('records and validates the extension target for every current lease purpose', function (): void {
        $worktree = temporaryPath('orbit-issue-state-extension-', 4);
        mkdir($worktree, 0700);
        $state = IssueState::forWorktree('AUX-7', $worktree);
        $operation = new OperationId(str_repeat('d', 32));
        $discovery = new AttemptId(str_repeat('a', 32));
        $proof = new AttemptId(str_repeat('b', 32));
        $candidate = new AttemptId(str_repeat('c', 32));

        $state->writeAttempt($discovery, AttemptPurpose::Discovery, $operation, TopologyExtension::AppProd);
        $state->writeAttempt($proof, AttemptPurpose::Proof, $operation, null);
        $state->writeAttempt($candidate, AttemptPurpose::CandidateConvergence, $operation, null);

        expect($state->attempt(AttemptPurpose::Discovery)['extension'])
            ->toBe('app-prod')
            ->and(array_key_exists('extension', $state->attempt(AttemptPurpose::Proof)))
            ->toBeTrue()
            ->and($state->attempt(AttemptPurpose::Proof)['extension'])
            ->toBeNull()
            ->and(array_key_exists('extension', $state->attempt(AttemptPurpose::CandidateConvergence)))
            ->toBeTrue()
            ->and($state->attempt(AttemptPurpose::CandidateConvergence)['extension'])
            ->toBeNull();

        $state->writeTopology(issueStateTopology(
            'AUX-7',
            $discovery,
            AttemptPurpose::Discovery,
            TopologyExtension::AppProd,
        ));
        expect($state->requireTopology(AttemptPurpose::Discovery)->construction->extension)
            ->toBe(TopologyExtension::AppProd);

        $leasePath = $worktree.'/.e2e/'.IssueState::ATTEMPT;
        $lease = json_decode((string) file_get_contents($leasePath), true, 8, JSON_THROW_ON_ERROR);
        $lease['extension'] = null;
        file_put_contents($leasePath, json_encode($lease, JSON_THROW_ON_ERROR));

        expect(fn () => $state->requireTopology(AttemptPurpose::Discovery))
            ->toThrow(RuntimeException::class, 'name different extensions');
    });

    it('accepts a matching complete legacy record and recovers only a missing lease extension', function (): void {
        $worktree = temporaryPath('orbit-issue-state-legacy-', 4);
        mkdir($worktree, 0700);
        $state = IssueState::forWorktree('AUX-7', $worktree);
        $attempt = new AttemptId(str_repeat('a', 32));
        $operation = new OperationId(str_repeat('b', 32));
        $state->writeAttempt($attempt, AttemptPurpose::Discovery, $operation, TopologyExtension::AppProd);
        $state->writeTopology(issueStateTopology(
            'AUX-7',
            $attempt,
            AttemptPurpose::Discovery,
            TopologyExtension::AppProd,
        ));
        $leasePath = $worktree.'/.e2e/'.IssueState::ATTEMPT;
        $legacy = json_decode((string) file_get_contents($leasePath), true, 8, JSON_THROW_ON_ERROR);
        unset($legacy['extension']);
        file_put_contents($leasePath, json_encode($legacy, JSON_THROW_ON_ERROR));

        expect($state->requireTopology(AttemptPurpose::Discovery)->attempt->value)
            ->toBe($attempt->value)
            ->and($state->leaseHasExtension(AttemptPurpose::Discovery))
            ->toBeFalse();

        $state->recoverLeaseExtension(AttemptPurpose::Discovery, $attempt, TopologyExtension::AppProd);
        $recovered = $state->attempt(AttemptPurpose::Discovery);
        expect($recovered)
            ->toMatchArray($legacy)
            ->and($recovered['extension'])
            ->toBe('app-prod');

        $state->recoverLeaseExtension(AttemptPurpose::Discovery, $attempt, TopologyExtension::AppProd);
        expect($state->attempt(AttemptPurpose::Discovery))
            ->toBe($recovered)
            ->and(fn () => $state->recoverLeaseExtension(AttemptPurpose::Discovery, $attempt, null))
            ->toThrow(RuntimeException::class, 'cannot replace')
            ->and(fn () => $state->recoverLeaseExtension(
                AttemptPurpose::Discovery,
                new AttemptId(str_repeat('c', 32)),
                TopologyExtension::AppProd,
            ))
            ->toThrow(RuntimeException::class, 'does not match the active lease');
    });

    it('persists typed capture, monotonic review, evaluation, and closeout state by exact attempt', function (): void {
        $worktree = temporaryPath('orbit-issue-review-state-', 4);
        mkdir($worktree, 0700);
        $state = IssueState::forWorktree('AUX-230', $worktree);
        $attempt = new AttemptId(str_repeat('a', 32));
        $candidate = str_repeat('d', 40);
        $topology = issueStateTopology('AUX-230', $attempt, AttemptPurpose::Proof);
        $topology = new FeatureTopology(
            $topology->construction,
            $topology->purpose,
            $topology->generation,
            new SourceState($candidate, $candidate),
            $topology->verification,
        );
        $manifest = new ProofInputManifest(
            4,
            $candidate,
            str_repeat('b', 40),
            [],
            [],
            '.loop/proof/AUX-230.json',
            [],
            $topology->construction,
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
            'issue' => 'AUX-230',
            'attempt_id' => $attempt->value,
            'candidate_sha' => $candidate,
            'plan_sha256' => str_repeat('c', 64),
            'manifest_sha256' => $manifest->fingerprint(),
            'actions' => [],
        ];
        $state->writeProof($proof);
        $capture = new CapturedProof(
            'AUX-230',
            $attempt,
            $candidate,
            str_repeat('c', 64),
            $manifest->fingerprint(),
            $proof,
            $topology,
            $manifest->toArray(),
            '2026-09-10T10:00:00Z',
        );
        $state->captureProof($capture);
        $pending = ProofReviewAction::incomplete(
            'required-shell',
            'shell',
            'gateway',
            true,
            [],
            null,
            '2026-09-10T10:01:00Z',
        );
        $record = ProofReviewRecord::empty('AUX-230', $candidate, $attempt, '2026-09-10T10:01:00Z')
            ->withAction($pending, '2026-09-10T10:01:00Z');
        $state->writeReviewRecord($record);
        $evaluation = ProofReviewEvaluation::forRecord($record, '2026-09-10T10:02:00Z');
        $state->writeReviewEvaluation($evaluation);
        $failedCloseout = new ProofCloseoutRecord(
            'refresh-failed',
            'AUX-230',
            $attempt,
            $candidate,
            str_repeat('e', 40),
            str_repeat('f', 40),
            str_repeat('1', 40),
            null,
            'Refresh failed.',
            '2026-09-10T10:03:00Z',
        );
        $state->writeCloseoutRecord($failedCloseout);

        expect($state->capturedProof()?->fingerprint())
            ->toBe($capture->fingerprint())
            ->and($state->reviewRecord()?->action('required-shell')?->status)
            ->toBe('incomplete')
            ->and($state->reviewEvaluation()?->status)
            ->toBe('blocked')
            ->and($state->closeoutRecord()?->state)
            ->toBe('refresh-failed');

        $completed = $record->withAction(
            $pending->complete('passed', null, '', '', null, '2026-09-10T10:04:00Z'),
            '2026-09-10T10:04:00Z',
        );
        $state->writeReviewRecord($completed);

        expect($state->reviewRecord()?->action('required-shell')?->status)
            ->toBe('passed')
            ->and(fn () => $state->writeReviewRecord($record))
            ->toThrow(RuntimeException::class, 'cannot replace its retained history');
    });
});
