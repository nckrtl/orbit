<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use App\E2E\IssueState;
use App\E2E\ProofEquivalenceEvaluator;
use App\E2E\ProofInputManifestBuilder;
use App\E2E\State\StatePaths;
use App\E2E\StaticProofInputPolicy;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\ObservedPhpInputs;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofEquivalenceReport;
use App\E2E\Value\ProofEquivalenceResult;
use App\E2E\Value\ProofPlan;
use App\E2E\Value\ProofResult;
use App\E2E\Value\ProofStatus;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationReport;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;

beforeEach(function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

/** @return array{root:string,main:string,proved:string,plan:ProofPlan,state:IssueState,evaluator:ProofEquivalenceEvaluator} */
function proofEquivalenceFixture(bool $observedInputs = false): array
{
    $root = temporaryPath('orbit-equivalence-', 6);
    foreach (['apps/cli/app', 'docs/reference', '.loop/proof'] as $directory) {
        mkdir($root.'/'.$directory, 0700, true);
    }
    file_put_contents($root.'/.gitignore', "/.e2e/\n");
    file_put_contents($root.'/apps/cli/app/runtime.php', "<?php\n");
    file_put_contents($root.'/docs/reference/note.md', "before\n");
    file_put_contents($root.'/.loop/proof/check.sh', "#!/bin/sh\nexit 0\n");
    chmod($root.'/.loop/proof/check.sh', 0755);
    $planValue = [
        'setup' => [],
        'acceptance' => [[
            'id' => 'check',
            'node' => 'app-dev',
            'argv' => ['bash', '/var/lib/orbit-e2e/proof/check.sh'],
            'timeout_seconds' => 30,
        ]],
    ];
    if ($observedInputs) {
        $planValue['observed_inputs'] = true;
    }
    file_put_contents($root.'/.loop/proof/AUX-99.json', json_encode($planValue, JSON_THROW_ON_ERROR));
    equivalenceGit($root, ['init', '--quiet', '-b', 'codex/aux-99-equivalence']);
    equivalenceGit($root, ['config', 'user.email', 'orbit@example.test']);
    equivalenceGit($root, ['config', 'user.name', 'Orbit']);
    equivalenceGit($root, ['add', '.']);
    equivalenceGit($root, ['commit', '--quiet', '-m', 'main']);
    $main = equivalenceGit($root, ['rev-parse', 'HEAD']);
    equivalenceGit($root, ['update-ref', 'refs/remotes/origin/main', $main]);
    file_put_contents($root.'/apps/cli/app/feature.php', "<?php\n");
    equivalenceGit($root, ['add', '.']);
    equivalenceGit($root, ['commit', '--quiet', '-m', 'proved']);
    $proved = equivalenceGit($root, ['rev-parse', 'HEAD']);
    $plan = ProofPlan::fromFile($root.'/.loop/proof/AUX-99.json');
    $policy = new StaticProofInputPolicy;
    $builder = new ProofInputManifestBuilder($policy);
    $observed = null;
    if ($observedInputs) {
        $surface = static fn (string $role, string $type): array => [
            'role' => $role,
            'process_type' => $type,
            'processes' => [[
                'id' => str_repeat($role === 'gateway' ? ($type === 'fpm' ? '3' : '2') : '1', 32),
                'started_at' => '2026-09-03T10:00:00.000001Z',
                'finished_at' => '2026-09-03T10:00:00.000002Z',
            ]],
            'paths' => ['apps/cli/app/feature.php'],
        ];
        $packages = array_fill_keys(ObservedPhpInputs::PACKAGES, '8.5.10-sury');
        $packages['php8.5-pcov'] = '1.0.12-sury';
        $runtime = static fn (string $role): array => [
            'role' => $role,
            'php_version' => '8.5.10',
            'fpm_version' => '8.5.10',
            'pcov_version' => '1.0.12',
            'package_versions' => $packages,
        ];
        $observed = new ObservedPhpInputs(
            [$runtime('app-dev'), $runtime('gateway')],
            [
                'setup' => [$surface('app-dev', 'cli'), $surface('gateway', 'cli'), $surface('gateway', 'fpm')],
                'acceptance' => [$surface('app-dev', 'cli'), $surface('gateway', 'cli'), $surface('gateway', 'fpm')],
            ],
        );
    }
    $manifest = $builder->build(
        new GitRepository($root),
        $proved,
        $main,
        'AUX-99',
        '.loop/proof/AUX-99.json',
        $plan,
        $observed,
    );
    $attempt = new AttemptId(str_repeat('a', 32));
    $target = TopologyTarget::feature('AUX-99', $attempt);
    $state = IssueState::forWorktree('AUX-99', $root);
    $state->writeAttempt($attempt, AttemptPurpose::Proof, new OperationId(str_repeat('b', 32)));
    $state->writeTopology(new FeatureTopology(
        $target,
        AttemptPurpose::Proof,
        proofEquivalenceGeneration($main),
        $target->network(),
        array_combine(TopologyProfile::ROLES, array_map($target->instance(...), TopologyProfile::ROLES)),
        new SourceState($proved, $proved),
        new VerificationReport(true, [
            'proof.verify' => [
                'passed' => true,
                'checked_at' => '2026-09-02T10:00:00Z',
                'expected' => 'healthy',
                'observed' => 'healthy',
                'evidence_ref' => 'incus://'.$target->instance('gateway').'/proof.verify',
            ],
        ]),
    ));
    $state->writeProofInputManifest($manifest->fingerprint(), $manifest->toArray());
    $state->writeProof(
        new ProofResult(
            'AUX-99',
            $attempt,
            ProofStatus::Proved,
            $proved,
            [['id' => 'check', 'node' => 'app-dev', 'exit_code' => 0, 'stdout' => '', 'stderr' => '']],
            null,
            '2026-09-02T10:00:00Z',
            planSha256: $plan->fingerprint(),
            manifestSha256: $manifest->fingerprint(),
        )->toArray(),
    );
    new GitRepository($root)->pinProof('AUX-99', $attempt, $proved);

    return [
        'root' => $root,
        'main' => $main,
        'proved' => $proved,
        'plan' => $plan,
        'state' => $state,
        'evaluator' => new ProofEquivalenceEvaluator(
            $policy,
            $builder,
            new StatePaths(temporaryPath('orbit-equivalence-host-', 6)),
            new OperationId(str_repeat('c', 32)),
            $root,
        ),
    ];
}

function proofEquivalenceGeneration(string $main): TopologySnapshotGeneration
{
    return new TopologySnapshotGeneration(
        'equivalence-generation',
        $main,
        [
            'gateway' => 'main-equivalence-gateway',
            'app-dev' => 'main-equivalence-app-dev',
            'app-prod' => 'main-equivalence-app-prod',
        ],
        str_repeat('d', 64),
        str_repeat('e', 64),
        new LaravelRelease('v13.10.1', str_repeat('f', 40)),
        str_repeat('0', 64),
        2,
        'ubuntu-26.04-amd64-v1',
        'orbit-base-ubuntu-26.04-runtime',
        TopologyProfile::NAME,
        TopologyProfile::ROLES,
        TopologyProfile::CHECKOUT_ROLES,
    );
}

/** @param list<string> $arguments */
function equivalenceGit(string $root, array $arguments): string
{
    $command = array_map(escapeshellarg(...), ['git', '-C', $root, ...$arguments]);
    $output = [];
    $exitCode = 0;
    exec(implode(' ', $command), $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException('Equivalence Git fixture command failed.');
    }

    return trim(implode("\n", $output));
}

/** @param array{root:string,plan:ProofPlan,evaluator:ProofEquivalenceEvaluator} $fixture */
function evaluateProof(array $fixture, ?ProofPlan $plan = null): ProofEquivalenceReport
{
    return $fixture['evaluator']->evaluate(
        new TopologyRequest('AUX-99', $fixture['root']),
        $plan ?? $fixture['plan'],
        '.loop/proof/AUX-99.json',
    );
}

describe('ProofEquivalenceEvaluator', function (): void {
    it('routes unobserved current-main PHP drift through candidate convergence', function (): void {
        $fixture = proofEquivalenceFixture(observedInputs: true);
        $branch = equivalenceGit($fixture['root'], ['branch', '--show-current']);
        equivalenceGit($fixture['root'], ['switch', '--quiet', '--detach', $fixture['main']]);
        file_put_contents($fixture['root'].'/apps/cli/app/runtime.php', "<?php\n// unrelated main change\n");
        equivalenceGit($fixture['root'], ['commit', '--quiet', '-am', 'unrelated main runtime']);
        $advancedMain = equivalenceGit($fixture['root'], ['rev-parse', 'HEAD']);
        equivalenceGit($fixture['root'], ['update-ref', 'refs/remotes/origin/main', $advancedMain]);
        equivalenceGit($fixture['root'], ['switch', '--quiet', $branch]);
        equivalenceGit($fixture['root'], ['merge', '--quiet', '--no-edit', $advancedMain]);

        $report = evaluateProof($fixture);

        expect($report->result)
            ->toBe(ProofEquivalenceResult::Equivalent)
            ->and($report->promotionPath)
            ->toBe('candidate-convergence')
            ->and($report->nextAction)
            ->toBe('run-candidate-convergence')
            ->and($report->changedPaths[0]['classification'])
            ->toBe('unrelated-runtime');
    });

    it('marks observed current-main PHP drift stale', function (): void {
        $fixture = proofEquivalenceFixture(observedInputs: true);
        file_put_contents($fixture['root'].'/apps/cli/app/feature.php', "<?php\n// observed change\n");
        equivalenceGit($fixture['root'], ['commit', '--quiet', '-am', 'observed runtime changed']);

        expect(evaluateProof($fixture)->result)->toBe(ProofEquivalenceResult::Stale);
    });

    it('marks a feature override of an unobserved current-main PHP change stale', function (): void {
        $fixture = proofEquivalenceFixture(observedInputs: true);
        $branch = equivalenceGit($fixture['root'], ['branch', '--show-current']);
        equivalenceGit($fixture['root'], ['switch', '--quiet', '--detach', $fixture['main']]);
        file_put_contents($fixture['root'].'/apps/cli/app/runtime.php', "<?php\n// main change\n");
        equivalenceGit($fixture['root'], ['commit', '--quiet', '-am', 'main runtime']);
        $advancedMain = equivalenceGit($fixture['root'], ['rev-parse', 'HEAD']);
        equivalenceGit($fixture['root'], ['update-ref', 'refs/remotes/origin/main', $advancedMain]);
        equivalenceGit($fixture['root'], ['switch', '--quiet', $branch]);
        equivalenceGit($fixture['root'], ['merge', '--quiet', '--no-edit', $advancedMain]);
        file_put_contents($fixture['root'].'/apps/cli/app/runtime.php', "<?php\n// feature override\n");
        equivalenceGit($fixture['root'], ['commit', '--quiet', '-am', 'override main runtime']);

        $report = evaluateProof($fixture);

        expect($report->result)
            ->toBe(ProofEquivalenceResult::Stale)
            ->and($report->changedPaths[0]['classification'])
            ->toBe('runtime');
    });

    it('reports exact for the proved head and a different commit with the same tree', function (): void {
        $fixture = proofEquivalenceFixture();

        expect(evaluateProof($fixture)->result)->toBe(ProofEquivalenceResult::Exact);
        equivalenceGit($fixture['root'], ['commit', '--quiet', '--allow-empty', '-m', 'same tree']);
        $report = evaluateProof($fixture);

        expect($report->result)
            ->toBe(ProofEquivalenceResult::Exact)
            ->and($report->acceptedSha)
            ->not
            ->toBe($fixture['proved'])
            ->and($report->changedPaths)
            ->toBe([]);
    });

    it('retains proof for a documentation-only correction', function (): void {
        $fixture = proofEquivalenceFixture();
        file_put_contents($fixture['root'].'/docs/reference/note.md', "after\n");
        equivalenceGit($fixture['root'], ['commit', '--quiet', '-am', 'documentation correction']);

        $report = evaluateProof($fixture);

        expect($report->result)
            ->toBe(ProofEquivalenceResult::Equivalent)
            ->and($report->promotionPath)
            ->toBe('retained-proof')
            ->and($report->errors)
            ->toBe([])
            ->and($report->changedPaths)
            ->toBe([[
                'path' => 'docs/reference/note.md',
                'previous_path' => null,
                'change' => 'content-changed',
                'classification' => 'non-runtime',
            ]])
            ->and($fixture['state']->equivalence())
            ->toBe($report->toArray());
    });

    it('retains proof after an equivalent rebase onto the same included main', function (): void {
        $fixture = proofEquivalenceFixture();
        file_put_contents($fixture['root'].'/docs/reference/note.md', "rebased correction\n");
        equivalenceGit($fixture['root'], ['commit', '--quiet', '-am', 'documentation correction']);
        $tree = equivalenceGit($fixture['root'], ['rev-parse', 'HEAD^{tree}']);
        $rebased = equivalenceGit($fixture['root'], [
            'commit-tree',
            $tree,
            '-p',
            $fixture['main'],
            '-m',
            'rebased correction',
        ]);
        equivalenceGit($fixture['root'], ['reset', '--quiet', '--hard', $rebased]);

        $report = evaluateProof($fixture);

        expect($report->result)
            ->toBe(ProofEquivalenceResult::Equivalent)
            ->and($report->acceptedSha)
            ->toBe($rebased)
            ->and(new GitRepository($fixture['root'])->isAncestor($fixture['proved'], $rebased))
            ->toBeFalse();
    });

    it('marks runtime and proof-contract changes stale', function (string $path): void {
        $fixture = proofEquivalenceFixture();
        file_put_contents($fixture['root'].'/'.$path, "changed\n");
        equivalenceGit($fixture['root'], ['commit', '--quiet', '-am', 'proof input changed']);

        $report = evaluateProof($fixture);

        expect($report->result)
            ->toBe(ProofEquivalenceResult::Stale)
            ->and($report->promotionPath)
            ->toBeNull()
            ->and($report->nextAction)
            ->toBe('release-proof-and-run-complete-reproof');
    })->with([
        'runtime' => 'apps/cli/app/runtime.php',
        'proof contract' => '.loop/proof/check.sh',
    ]);

    it('is indeterminate for unknown paths and current-main drift', function (Closure $mutate): void {
        $fixture = proofEquivalenceFixture();
        $mutate($fixture);

        $report = evaluateProof($fixture);

        expect($report->result)
            ->toBe(ProofEquivalenceResult::Indeterminate)
            ->and($report->promotionPath)
            ->toBeNull()
            ->and($report->errors)
            ->not->toBe([]);
    })->with([
        'unknown path' => function (array $fixture): void {
            file_put_contents($fixture['root'].'/unexpected.txt', "unknown\n");
            equivalenceGit($fixture['root'], ['add', '.']);
            equivalenceGit($fixture['root'], ['commit', '--quiet', '-m', 'unknown path']);
        },
        'main advanced outside candidate' => function (array $fixture): void {
            $main = equivalenceGit($fixture['root'], [
                'commit-tree',
                $fixture['proved'].'^{tree}',
                '-m',
                'advanced main',
            ]);
            equivalenceGit($fixture['root'], ['update-ref', 'refs/remotes/origin/main', $main]);
        },
    ]);

    it('is indeterminate when the current normalized plan differs from the proved plan', function (): void {
        $fixture = proofEquivalenceFixture();
        $plan = ProofPlan::fromArray([
            'setup' => [],
            'acceptance' => [[
                'id' => 'different-check',
                'node' => 'app-dev',
                'argv' => ['true'],
                'timeout_seconds' => 30,
            ]],
        ]);

        expect(evaluateProof($fixture, $plan)->result)->toBe(ProofEquivalenceResult::Indeterminate);
    });
});
