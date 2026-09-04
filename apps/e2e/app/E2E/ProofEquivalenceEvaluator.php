<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofEquivalenceReport;
use App\E2E\Value\ProofEquivalenceResult;
use App\E2E\Value\ProofInputClassification;
use App\E2E\Value\ProofInputManifest;
use App\E2E\Value\ProofPlan;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologyTarget;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Compare one exact current head with the immutable inputs of its retained proof.
 *
 * @mago-expect lint:cyclomatic-complexity,kan-defect,excessive-parameter-list The evaluator keeps the complete fail-closed decision at one trust boundary.
 */
final readonly class ProofEquivalenceEvaluator
{
    public function __construct(
        private StaticProofInputPolicy $policy,
        private ProofInputManifestBuilder $manifests,
        private StatePaths $hostPaths,
        private OperationId $operation,
        private string $repositoryRoot,
    ) {}

    public function evaluate(TopologyRequest $request, ProofPlan $plan, string $planPath): ProofEquivalenceReport
    {
        $repository = new GitRepository($request->worktree);
        $this->assertRepositoryIdentity($request, $repository);
        if ($repository->dirtyOverlay() !== null) {
            throw new InvalidArgumentException('The worktree must be clean before proof equivalence is evaluated.');
        }
        $acceptedSha = $repository->commit();
        $includedMainSha = $repository->commit('origin/main');
        $state = IssueState::forWorktree($request->issue, $request->worktree);
        $lock = new OperationLock($this->hostPaths);
        if (! $lock->acquire('topology-'.$request->issue, $this->operation)) {
            throw new RuntimeException('The issue topology is locked by another harness command.');
        }
        try {
            return $this->evaluateLocked(
                $request,
                $repository,
                $state,
                $acceptedSha,
                $includedMainSha,
                $plan,
                $planPath,
            );
        } finally {
            $lock->release();
        }
    }

    private function evaluateLocked(
        TopologyRequest $request,
        GitRepository $repository,
        IssueState $state,
        string $acceptedSha,
        string $includedMainSha,
        ProofPlan $plan,
        string $planPath,
    ): ProofEquivalenceReport {
        if (! $state->isProved()) {
            throw new RuntimeException("{$request->issue} has no active immutable proved topology.");
        }
        $topology = $state->requireTopology(AttemptPurpose::Proof);
        $proof = $state->proof() ?? [];
        $provedSha = $proof['candidate_sha'] ?? null;
        $planSha256 = $proof['plan_sha256'] ?? null;
        $manifestSha256 = $proof['manifest_sha256'] ?? null;
        if (
            ! is_string($provedSha)
            || preg_match('/\A[0-9a-f]{40}\z/D', $provedSha) !== 1
            || ! is_string($planSha256)
            || preg_match('/\A[0-9a-f]{64}\z/D', $planSha256) !== 1
            || ! is_string($manifestSha256)
            || preg_match('/\A[0-9a-f]{64}\z/D', $manifestSha256) !== 1
        ) {
            throw new RuntimeException('The retained proof identity is incomplete.');
        }
        if ($topology->source->hostSha !== $provedSha || $topology->source->guestSha !== $provedSha) {
            throw new RuntimeException('The retained topology does not hold the proved candidate.');
        }

        $errors = [];
        $manifest = null;
        if ($plan->fingerprint() !== $planSha256) {
            $errors[] = 'The current normalized proof plan differs from the proved plan.';
        }
        $rawManifest = $state->proofInputManifest($manifestSha256);
        if ($rawManifest === null) {
            $errors[] = 'The proof-input manifest is missing.';
        } else {
            try {
                $manifest = ProofInputManifest::fromArray($rawManifest);
                if ($manifest->fingerprint() !== $manifestSha256 || $manifest->provedSha !== $provedSha) {
                    $errors[] = 'The proof-input manifest does not match the retained proof.';
                }
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }
        if ($manifest !== null && $plan->fingerprint() === $planSha256) {
            try {
                $expected = $this->manifests->build(
                    $repository,
                    $provedSha,
                    $manifest->includedMainSha,
                    $request->issue,
                    $planPath,
                    $plan,
                    $manifest->observedInputs,
                );
                if ($expected->toArray() !== $manifest->toArray()) {
                    $errors[] = 'The recorded proof-input inventory is incomplete.';
                }
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }
        if (! $repository->isAncestor($includedMainSha, $acceptedSha)) {
            $errors[] = 'The accepted candidate does not include current origin/main.';
        }

        $provedEntries = [];
        $acceptedEntries = [];
        $provedMainEntries = [];
        $acceptedMainEntries = [];
        if ($manifest !== null) {
            $provedEntries = $repository->entries($provedSha);
            $acceptedEntries = $repository->entries($acceptedSha);
            $provedMainEntries = $repository->entries($manifest->includedMainSha);
            $acceptedMainEntries = $repository->entries($includedMainSha);
        }
        $loopRemoved = ! array_any(
            array_keys($acceptedEntries),
            static fn (string $path): bool => str_starts_with($path, '.loop/'),
        );
        $changedPaths = [];
        foreach ($repository->changes($provedSha, $acceptedSha) as $change) {
            $classification = $this->changeClassification(
                $change,
                $planPath,
                $plan,
                $manifest,
                $provedEntries,
                $acceptedEntries,
                $provedMainEntries,
                $acceptedMainEntries,
                $loopRemoved,
            );
            $changedPaths[] = [...$change, 'classification' => $classification->value];
            if ($classification === ProofInputClassification::Indeterminate) {
                $errors[] = "Changed path [{$change['path']}] has no safe proof-input classification.";
            }
        }
        $errors = array_values(array_unique($errors));
        $result = $this->result($repository, $provedSha, $acceptedSha, $changedPaths, $errors);
        $report = new ProofEquivalenceReport(
            $provedSha,
            $acceptedSha,
            $includedMainSha,
            $planSha256,
            $manifestSha256,
            $result,
            $changedPaths,
            $result === ProofEquivalenceResult::Equivalent
            && array_any(
                $changedPaths,
                static fn (array $change): bool => (
                    $change['classification'] === ProofInputClassification::UnrelatedRuntime->value
                ),
            )
                ? 'candidate-convergence'
                : (
                    in_array($result, [ProofEquivalenceResult::Exact, ProofEquivalenceResult::Equivalent], true)
                        ? 'retained-proof'
                        : null
                ),
            match (true) {
                $result === ProofEquivalenceResult::Equivalent
                    && array_any(
                        $changedPaths,
                        static fn (array $change): bool => (
                            $change['classification'] === ProofInputClassification::UnrelatedRuntime->value
                        ),
                    )
                    => 'run-candidate-convergence',
                in_array($result, [ProofEquivalenceResult::Exact, ProofEquivalenceResult::Equivalent], true)
                    => 'review-exact-head',
                $result === ProofEquivalenceResult::Stale => 'release-proof-and-run-complete-reproof',
                $result === ProofEquivalenceResult::Indeterminate
                    => 'resolve-equivalence-failure-and-run-complete-reproof',
                default => throw new \LogicException('The proof equivalence result is unsupported.'),
            },
            $errors,
            gmdate('Y-m-d\TH:i:s\Z'),
        );
        $state->writeEquivalence($report->fingerprint(), $report->toArray());

        return $report;
    }

    /**
     * @param array{path:string,previous_path:?string,change:string} $change
     */
    private function changeClassification(
        array $change,
        string $planPath,
        ProofPlan $plan,
        ?ProofInputManifest $manifest,
        array $provedEntries,
        array $acceptedEntries,
        array $provedMainEntries,
        array $acceptedMainEntries,
        bool $loopRemoved,
    ): ProofInputClassification {
        if (
            $loopRemoved
            && $change['change'] === 'deleted'
            && $change['previous_path'] === null
            && str_starts_with($change['path'], '.loop/')
        ) {
            return ProofInputClassification::NonRuntime;
        }
        $paths = array_filter([$change['path'], $change['previous_path']]);
        if (
            $manifest?->observedInputs !== null
            && array_all($paths, $this->policy->isObservablePhpSource(...))
        ) {
            $recorded = $manifest->inputPaths();
            if (array_any($paths, static fn (string $path): bool => isset($recorded[$path]))) {
                return ProofInputClassification::Runtime;
            }
            if (array_all(
                $paths,
                static fn (string $path): bool => (
                    ($acceptedEntries[$path] ?? null) === ($acceptedMainEntries[$path] ?? null)
                    && ($provedEntries[$path] ?? null) === ($provedMainEntries[$path] ?? null)
                ),
            )) {
                return ProofInputClassification::UnrelatedRuntime;
            }

            return ProofInputClassification::Runtime;
        }
        $classifications = [$this->classifyPath($change['path'], $planPath, $plan)];
        if ($change['previous_path'] !== null) {
            $classifications[] = $this->classifyPath($change['previous_path'], $planPath, $plan);
        }
        foreach ([
            ProofInputClassification::Indeterminate,
            ProofInputClassification::ProofContract,
            ProofInputClassification::Runtime,
            ProofInputClassification::UnrelatedRuntime,
            ProofInputClassification::NonRuntime,
        ] as $classification) {
            if (in_array($classification, $classifications, true)) {
                return $classification;
            }
        }

        return ProofInputClassification::Indeterminate;
    }

    private function classifyPath(
        string $path,
        string $planPath,
        ProofPlan $plan,
    ): ProofInputClassification {
        if (
            $path === $planPath
            || str_starts_with($path, ProofPlanFile::DIRECTORY.'/')
            || array_any($plan->inputs, static fn (string $input): bool => $path === $input
            || str_starts_with($path, $input.'/'))
        ) {
            return ProofInputClassification::ProofContract;
        }

        return $this->policy->classify($path);
    }

    /**
     * @param list<array{path:string,previous_path:?string,change:string,classification:string}> $changedPaths
     * @param list<string> $errors
     */
    private function result(
        GitRepository $repository,
        string $provedSha,
        string $acceptedSha,
        array $changedPaths,
        array $errors,
    ): ProofEquivalenceResult {
        if ($errors !== []) {
            return ProofEquivalenceResult::Indeterminate;
        }
        if ($provedSha === $acceptedSha || $repository->tree($provedSha) === $repository->tree($acceptedSha)) {
            return ProofEquivalenceResult::Exact;
        }
        if (array_any($changedPaths, static fn (array $change): bool => in_array(
            $change['classification'],
            [
                ProofInputClassification::Runtime->value,
                ProofInputClassification::ProofContract->value,
            ],
            true,
        ))) {
            return ProofEquivalenceResult::Stale;
        }

        return ProofEquivalenceResult::Equivalent;
    }

    private function assertRepositoryIdentity(TopologyRequest $request, GitRepository $repository): void
    {
        if ($repository->commonDirectory() !== new GitRepository($this->repositoryRoot)->commonDirectory()) {
            throw new InvalidArgumentException('The worktree repository identity does not match Orbit.');
        }
        if (! TopologyTarget::issueMatchesBranch($request->issue, $repository->branch())) {
            throw new InvalidArgumentException('The worktree branch does not match the issue.');
        }
    }
}
