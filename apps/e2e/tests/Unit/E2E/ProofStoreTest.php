<?php

declare(strict_types=1);

use App\E2E\ProofRecordReader;
use App\E2E\ProofStore;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\ProofPlan;
use App\E2E\Value\ProofResult;
use App\E2E\Value\ProofStatus;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\VerificationReport;

/** @return array<string, mixed> */
function proofStorePlanFixture(): array
{
    return [
        'setup' => [
            ['id' => 'seed', 'node' => 'app-dev', 'argv' => ['true'], 'timeout_seconds' => 30],
        ],
        'acceptance' => [
            [
                'id' => 'list',
                'node' => 'app-dev',
                'argv' => ['orbit', 'workspace:list', '--json'],
                'timeout_seconds' => 60,
            ],
        ],
        'post_deployment_actions' => [
            [
                'target' => 'gateway',
                'operation' => 'php artisan migrate --force',
                'reason' => 'new table',
                'recovery' => 'php artisan migrate:rollback',
                'verification' => 'php artisan migrate:status',
            ],
        ],
    ];
}

/** @return array{id:string,node:string,argv:list<string>,exit_code:int,stdout:string,stderr:string,started_at:string,finished_at:string} */
function proofActionResult(array $action, int $exitCode = 0): array
{
    return [
        'id' => $action['id'],
        'node' => $action['node'],
        'argv' => $action['argv'],
        'exit_code' => $exitCode,
        'stdout' => $exitCode === 0 ? "ok\n" : '',
        'stderr' => $exitCode === 0 ? '' : "failed\n",
        'started_at' => '2026-08-30T10:00:00Z',
        'finished_at' => '2026-08-30T10:00:01Z',
    ];
}

/** @param array<string, mixed> $overrides */
function proofResultFixture(array $overrides = []): ProofResult
{
    $plan = ProofPlan::fromArray(proofStorePlanFixture());
    $verification = new VerificationReport(true, ['fixture' => verificationProbeFixture()]);
    $values = $overrides
    + [
        'issue' => 'NCK-12',
        'attempt' => attemptId(),
        'status' => ProofStatus::Proved,
        'candidateSha' => str_repeat('c', 40),
        'candidateTree' => str_repeat('e', 40),
        'guestScriptHash' => str_repeat('d', 64),
        'source' => new SourceState(str_repeat('c', 40), str_repeat('c', 40)),
        'plan' => $plan,
        'setupResults' => [proofActionResult($plan->setup[0])],
        'acceptanceResults' => [proofActionResult($plan->acceptance[0])],
        'verification' => $verification,
        'recordedAt' => '2026-08-30T10:00:02Z',
        'operationId' => str_repeat('a', 32),
    ];

    return new ProofResult(
        $values['issue'],
        $values['attempt'],
        $values['status'],
        $values['candidateSha'],
        $values['candidateTree'],
        $values['guestScriptHash'],
        $values['source'],
        $values['plan'],
        $values['setupResults'],
        $values['acceptanceResults'],
        $values['verification'],
        $values['recordedAt'],
        $values['operationId'],
    );
}

describe('ProofResult', function () {
    it('serializes every proof field in the locked order and round-trips', function () {
        $result = proofResultFixture();
        $array = $result->toArray();

        expect(array_keys($array))
            ->toBe([
                'schema',
                'issue',
                'attempt_id',
                'status',
                'candidate_sha',
                'candidate_tree',
                'guest_script_hash',
                'profile',
                'source',
                'plan',
                'setup_results',
                'acceptance_results',
                'verification',
                'post_deployment_actions',
                'recorded_at',
                'operation_id',
            ])
            ->and($array['schema'])
            ->toBe(1)
            ->and($array['status'])
            ->toBe('proved')
            ->and($array['profile'])
            ->toBe(TopologyProfile::NAME)
            ->and($array['plan'])
            ->toBe(['setup' => $result->plan->setup, 'acceptance' => $result->plan->acceptance])
            ->and($array['post_deployment_actions'])
            ->toBe($result->plan->postDeploymentActions)
            ->and(ProofResult::fromArray($array)->toArray())
            ->toBe($array);
    });

    it('refuses a proved status without complete passing evidence', function (string $case) {
        $plan = ProofPlan::fromArray(proofStorePlanFixture());
        $overrides = match ($case) {
            'verification' => ['verification' => new VerificationReport(false, [
                'fixture' => verificationProbeFixture(false),
            ])],
            'setup-exit' => ['setupResults' => [proofActionResult($plan->setup[0], 1)]],
            'acceptance-exit' => ['acceptanceResults' => [proofActionResult($plan->acceptance[0], 2)]],
            'acceptance-missing' => ['acceptanceResults' => []],
            'setup-missing' => ['setupResults' => []],
            'script-hash' => ['guestScriptHash' => null],
        };

        expect(fn () => proofResultFixture($overrides))
            ->toThrow(InvalidArgumentException::class, 'proved')
            ->and(proofResultFixture($overrides + ['status' => ProofStatus::Diagnosis])->status)
            ->toBe(ProofStatus::Diagnosis);
    })->with(['verification', 'setup-exit', 'acceptance-exit', 'acceptance-missing', 'setup-missing', 'script-hash']);

    it('binds observed action results to the declared actions in order', function (string $case) {
        $plan = ProofPlan::fromArray(proofStorePlanFixture());
        $observed = proofActionResult($plan->acceptance[0]);
        match ($case) {
            'id' => $observed['id'] = 'other',
            'node' => $observed['node'] = 'gateway',
            'argv' => $observed['argv'] = ['false'],
            'keys' => $observed['extra'] = true,
            'timestamp' => $observed['started_at'] = 'yesterday',
            'excess' => null,
        };
        $results = $case === 'excess' ? [$observed, $observed] : [$observed];

        expect(fn () => proofResultFixture(['acceptanceResults' => $results, 'status' => ProofStatus::Diagnosis]))
            ->toThrow(InvalidArgumentException::class, 'action result');
    })->with(['id', 'node', 'argv', 'keys', 'timestamp', 'excess']);

    it('caps recorded action output', function () {
        $plan = ProofPlan::fromArray(proofStorePlanFixture());
        $observed = proofActionResult($plan->acceptance[0]);
        $observed['stdout'] = str_repeat('x', ProofResult::OUTPUT_LIMIT + 1);

        expect(fn () => proofResultFixture(['acceptanceResults' => [$observed]]))
            ->toThrow(InvalidArgumentException::class, 'output');
    });

    it('rejects invalid identities', function (array $overrides) {
        expect(fn () => proofResultFixture($overrides))->toThrow(InvalidArgumentException::class);
    })->with([
        [['candidateSha' => 'abc']],
        [['candidateTree' => str_repeat('g', 40)]],
        [['guestScriptHash' => 'short']],
        [['operationId' => 'nope']],
        [['recordedAt' => '2026-08-30 10:00:02']],
        [['source' => new SourceState(str_repeat('c', 40), str_repeat('b', 40))]],
    ]);

    it('rejects a serialized record with wrong schema, status, or keys', function (array $mutation) {
        $array = [...proofResultFixture()->toArray(), ...$mutation];

        expect(fn () => ProofResult::fromArray($array))->toThrow(InvalidArgumentException::class);
    })->with([
        [['schema' => 2]],
        [['status' => 'passed']],
        [['profile' => 'other']],
        [['unexpected' => true]],
    ]);
});

describe('ProofStore', function () {
    it('writes and reads one proof record per exact attempt', function () {
        $paths = new StatePaths(temporaryPath('orbit-proof-store-', 8));
        $store = new AtomicJsonStore($paths);
        $proofs = new ProofStore($store);
        $result = proofResultFixture();

        expect($proofs->read('NCK-12', attemptId()))->toBeNull();

        $proofs->write($result);

        expect($proofs->read('NCK-12', attemptId())?->toArray())
            ->toBe($result->toArray())
            ->and($store->read('evidence/proofs/NCK-12/'.attemptId()->value.'.json'))
            ->toBe($result->toArray())
            ->and(new ProofRecordReader($store)->isProved('NCK-12', attemptId()))
            ->toBeTrue()
            ->and($proofs->read('NCK-12', attemptId('b')))
            ->toBeNull();
    });

    it('refuses a record whose identity does not match its path', function () {
        $paths = new StatePaths(temporaryPath('orbit-proof-store-', 8));
        $store = new AtomicJsonStore($paths);
        $store->write(
            'evidence/proofs/NCK-12/'.attemptId()->value.'.json',
            proofResultFixture([
                'attempt' => attemptId('b'),
            ])->toArray(),
        );

        expect(fn () => new ProofStore($store)->read('NCK-12', attemptId()))
            ->toThrow(RuntimeException::class, 'identity');
    });

    it('replaces proved with diagnosis only through diagnose and never changes back', function () {
        $paths = new StatePaths(temporaryPath('orbit-proof-store-', 8));
        $store = new AtomicJsonStore($paths);
        $proofs = new ProofStore($store);
        $proofs->write(proofResultFixture());

        expect(fn () => $proofs->write(proofResultFixture()))
            ->toThrow(RuntimeException::class, 'already')
            ->and(fn () => $proofs->write(proofResultFixture([
                'status' => ProofStatus::Diagnosis,
                'candidateSha' => str_repeat('9', 40),
                'source' => new SourceState(str_repeat('9', 40), str_repeat('9', 40)),
            ])))
            ->toThrow(RuntimeException::class, 'already')
            ->and(fn () => $proofs->write(proofResultFixture(['status' => ProofStatus::Diagnosis])))
            ->toThrow(RuntimeException::class, 'already')
            ->and($proofs->read('NCK-12', attemptId())?->status)
            ->toBe(ProofStatus::Proved);

        $diagnosis = $proofs->diagnose('NCK-12', attemptId());

        expect($diagnosis->status)
            ->toBe(ProofStatus::Diagnosis)
            ->and($proofs->read('NCK-12', attemptId())?->status)
            ->toBe(ProofStatus::Diagnosis)
            ->and($diagnosis->candidateSha)
            ->toBe(str_repeat('c', 40))
            ->and(fn () => $proofs->diagnose('NCK-12', attemptId()))
            ->toThrow(RuntimeException::class, 'not proved')
            ->and(fn () => $proofs->write(proofResultFixture()))
            ->toThrow(RuntimeException::class, 'already')
            ->and(fn () => $proofs->diagnose('NCK-12', attemptId('b')))
            ->toThrow(RuntimeException::class, 'does not exist')
            ->and($proofs->read('NCK-12', attemptId())?->status)
            ->toBe(ProofStatus::Diagnosis);
    });

    it('accepts a diagnosis record as the first write', function () {
        $paths = new StatePaths(temporaryPath('orbit-proof-store-', 8));
        $proofs = new ProofStore(new AtomicJsonStore($paths));
        $proofs->write(proofResultFixture(['status' => ProofStatus::Diagnosis]));

        expect($proofs->read('NCK-12', attemptId())?->status)->toBe(ProofStatus::Diagnosis);
    });

    it('rejects an invalid issue before touching the store', function () {
        $paths = new StatePaths(temporaryPath('orbit-proof-store-', 8));
        $proofs = new ProofStore(new AtomicJsonStore($paths));

        expect(fn () => $proofs->read('bad issue', attemptId()))
            ->toThrow(InvalidArgumentException::class, 'Linear issue')
            ->and(fn () => $proofs->diagnose('bad issue', new AttemptId(str_repeat('a', 32))))
            ->toThrow(InvalidArgumentException::class, 'Linear issue')
            ->and(is_dir($paths->root().'/evidence'))
            ->toBeFalse();
    });
});
