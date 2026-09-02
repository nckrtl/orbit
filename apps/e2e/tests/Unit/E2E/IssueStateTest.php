<?php

declare(strict_types=1);

use App\E2E\IssueState;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationReport;

function issueStateTopology(
    string $issue,
    AttemptId $attempt,
    AttemptPurpose $purpose = AttemptPurpose::Discovery,
): FeatureTopology {
    $target = TopologyTarget::feature($issue, $attempt);

    return new FeatureTopology(
        $target,
        $purpose,
        new TopologySnapshotGeneration(
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
        ),
        $target->network(),
        array_combine(TopologyProfile::ROLES, array_map($target->instance(...), TopologyProfile::ROLES)),
        new SourceState(str_repeat('d', 40), str_repeat('d', 40)),
        new VerificationReport(true, ['ready' => verificationProbeFixture(probe: 'ready')]),
    );
}

describe('IssueState', function () {
    it('keeps the attempt, topology, proof, and log under <worktree>/.e2e/', function () {
        $worktree = temporaryPath('orbit-issue-state-', 4);
        mkdir($worktree, 0700);
        $state = IssueState::forWorktree('NCK-12', $worktree);
        $attempt = new AttemptId(str_repeat('a', 32));
        $operation = new OperationId(str_repeat('b', 32));

        expect($state->hasAttempt())
            ->toBeFalse()
            ->and(fn () => $state->attempt())
            ->toThrow(RuntimeException::class, 'NCK-12 has no active attempt.')
            ->and($state->topology())
            ->toBeNull();

        $state->writeAttempt($attempt, AttemptPurpose::Discovery, $operation);
        $state->writeTopology(issueStateTopology('NCK-12', $attempt));
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

    it('treats an attempt as proved only when the proof names the live attempt', function () {
        $worktree = temporaryPath('orbit-issue-state-', 4);
        mkdir($worktree, 0700);
        $state = IssueState::forWorktree('NCK-12', $worktree);
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
        $state = IssueState::forWorktree('ORB-7', $worktree);
        $discovery = new AttemptId(str_repeat('a', 32));
        $proof = new AttemptId(str_repeat('b', 32));

        $state->writeAttempt($discovery, AttemptPurpose::Discovery, new OperationId(str_repeat('c', 32)));
        $state->writeTopology(issueStateTopology('ORB-7', $discovery));
        $state->writeAttempt($proof, AttemptPurpose::Proof, new OperationId(str_repeat('d', 32)));
        $state->writeTopology(issueStateTopology('ORB-7', $proof, AttemptPurpose::Proof));
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

    it('rejects a lease or record that names another issue or attempt', function () {
        $worktree = temporaryPath('orbit-issue-state-', 4);
        mkdir($worktree, 0700);
        $attempt = new AttemptId(str_repeat('a', 32));
        IssueState::forWorktree('NCK-12', $worktree)
            ->writeAttempt($attempt, AttemptPurpose::Discovery, new OperationId(str_repeat('b', 32)));
        IssueState::forWorktree('NCK-12', $worktree)
            ->writeTopology(issueStateTopology('NCK-12', new AttemptId(str_repeat('c', 32))));

        expect(fn () => IssueState::forWorktree('NCK-13', $worktree)->attempt())
            ->toThrow(RuntimeException::class, 'lease is invalid')
            ->and(fn () => IssueState::forWorktree('NCK-13', $worktree)->topology())
            ->toThrow(RuntimeException::class, 'another issue')
            ->and(fn () => IssueState::forWorktree('NCK-12', $worktree)->requireTopology())
            ->toThrow(RuntimeException::class, 'name different attempts');
    });
});
