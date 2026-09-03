<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\Value\ObservedPhpInputs;
use App\E2E\Value\ProofInputClassification;
use App\E2E\Value\ProofInputManifest;
use App\E2E\Value\ProofPlan;
use App\E2E\Value\TopologyTarget;
use InvalidArgumentException;

/**
 * Build and verify the complete phase-one proof-input inventory from Git objects.
 *
 * @mago-expect lint:cyclomatic-complexity,kan-defect,excessive-parameter-list The builder proves completeness across one explicit Git trust boundary.
 */
final readonly class ProofInputManifestBuilder
{
    public function __construct(
        private StaticProofInputPolicy $policy,
    ) {}

    public function build(
        GitRepository $repository,
        string $provedSha,
        string $includedMainSha,
        string $issue,
        string $planPath,
        ProofPlan $plan,
        ?ObservedPhpInputs $observedInputs = null,
        bool $pcovCleanup = true,
    ): ProofInputManifest {
        if ($plan->observedInputs !== ($observedInputs !== null)) {
            throw new InvalidArgumentException('The observed PHP inputs do not match the proof plan.');
        }
        TopologyTarget::assertIssue($issue);
        if (! $repository->isAncestor($includedMainSha, $provedSha)) {
            throw new InvalidArgumentException('The proof candidate does not include current origin/main.');
        }
        $entries = $repository->entries($provedSha);
        $contractPaths = $this->contractPaths($entries, $issue, $planPath, $plan);
        $featureRuntimePaths = $this->featureRuntimePaths($repository, $includedMainSha, $provedSha);
        $unknown = [];
        foreach (array_keys($entries) as $path) {
            if (
                ! isset($contractPaths[$path])
                && $this->policy->classify($path) === ProofInputClassification::Indeterminate
            ) {
                $unknown[] = $path;
            }
        }
        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Static proof input classification is incomplete: '.implode(', ', $unknown).'.',
            );
        }

        $this->assertCheckoutLiterals($entries, $contractPaths, $plan);
        $inputs = [];
        foreach ($entries as $path => $entry) {
            $classification = isset($contractPaths[$path])
                ? ProofInputClassification::ProofContract
                : $this->policy->classify($path);
            if (! in_array(
                $classification,
                [
                    ProofInputClassification::Runtime,
                    ProofInputClassification::ProofContract,
                ],
                true,
            )) {
                continue;
            }
            if (
                $plan->observedInputs
                && ! isset($contractPaths[$path])
                && $this->policy->isObservablePhpSource($path)
                && ! in_array($path, $featureRuntimePaths, true)
            ) {
                continue;
            }
            if ($entry['type'] !== 'blob') {
                throw new InvalidArgumentException("Proof input [{$path}] is not a Git blob.");
            }
            $inputs[] = [
                'path' => $path,
                'classification' => $classification->value,
                'mode' => $entry['mode'],
                'blob' => $entry['object'],
            ];
        }
        usort($inputs, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);

        $fixtureIssues = $plan->fixtureIssues;
        sort($fixtureIssues, SORT_STRING);

        return new ProofInputManifest(
            StaticProofInputPolicy::VERSION,
            $provedSha,
            $includedMainSha,
            $featureRuntimePaths,
            $inputs,
            $planPath,
            $fixtureIssues,
            $plan->inputs,
            $observedInputs,
            [
                'static_classification' => true,
                'proof_contract' => true,
                'checkout_literals' => true,
                'observed_processes' => $observedInputs !== null || ! $plan->observedInputs,
                'observed_paths' => $observedInputs !== null || ! $plan->observedInputs,
                'pcov_cleanup' => $pcovCleanup,
            ],
        );
    }

    public function validateContract(
        GitRepository $repository,
        string $provedSha,
        string $includedMainSha,
        string $issue,
        string $planPath,
        ProofPlan $plan,
    ): void {
        TopologyTarget::assertIssue($issue);
        if (! $repository->isAncestor($includedMainSha, $provedSha)) {
            throw new InvalidArgumentException('The proof candidate does not include current origin/main.');
        }
        $entries = $repository->entries($provedSha);
        $contractPaths = $this->contractPaths($entries, $issue, $planPath, $plan);
        foreach (array_keys($entries) as $path) {
            if (
                ! isset($contractPaths[$path])
                && $this->policy->classify($path) === ProofInputClassification::Indeterminate
            ) {
                throw new InvalidArgumentException("Static proof input classification is incomplete: {$path}.");
            }
        }
        $this->assertCheckoutLiterals($entries, $contractPaths, $plan);
    }

    /** @return list<string> */
    private function featureRuntimePaths(
        GitRepository $repository,
        string $includedMainSha,
        string $provedSha,
    ): array {
        $paths = [];
        foreach ($repository->changes($includedMainSha, $provedSha) as $change) {
            foreach (array_filter([$change['path'], $change['previous_path']]) as $path) {
                if ($this->policy->classify($path) === ProofInputClassification::Runtime) {
                    $paths[$path] = true;
                }
            }
        }
        $paths = array_keys($paths);
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * @param array<string, array{mode:string,type:string,object:string}> $entries
     * @return array<string, true>
     */
    private function contractPaths(array $entries, string $issue, string $planPath, ProofPlan $plan): array
    {
        $contract = [];
        $this->requirePath($entries, $planPath, $contract);
        $this->addDirectory($entries, 'proofs/'.$issue, $contract, required: false);
        foreach ($plan->fixtureIssues as $fixtureIssue) {
            $this->addDirectory($entries, 'proofs/'.$fixtureIssue, $contract, required: true);
        }
        foreach ($plan->inputs as $input) {
            $this->addFileOrDirectory($entries, $input, $contract);
        }

        return $contract;
    }

    /**
     * @param array<string, array{mode:string,type:string,object:string}> $entries
     * @param array<string, true> $contract
     */
    private function requirePath(array $entries, string $path, array &$contract): void
    {
        if (! isset($entries[$path]) || $entries[$path]['type'] !== 'blob') {
            throw new InvalidArgumentException("Proof contract input [{$path}] is missing or is not a blob.");
        }
        $contract[$path] = true;
    }

    /**
     * @param array<string, array{mode:string,type:string,object:string}> $entries
     * @param array<string, true> $contract
     */
    private function addDirectory(array $entries, string $directory, array &$contract, bool $required): void
    {
        $prefix = rtrim($directory, '/').'/';
        $matched = false;
        foreach ($entries as $path => $entry) {
            if (! str_starts_with($path, $prefix)) {
                continue;
            }
            if ($entry['type'] !== 'blob') {
                throw new InvalidArgumentException("Proof contract input [{$path}] is not a blob.");
            }
            $contract[$path] = true;
            $matched = true;
        }
        if ($required && ! $matched) {
            throw new InvalidArgumentException("Proof contract directory [{$directory}] is missing or empty.");
        }
    }

    /**
     * @param array<string, array{mode:string,type:string,object:string}> $entries
     * @param array<string, true> $contract
     */
    private function addFileOrDirectory(array $entries, string $input, array &$contract): void
    {
        if (isset($entries[$input])) {
            $this->requirePath($entries, $input, $contract);

            return;
        }
        $this->addDirectory($entries, $input, $contract, required: true);
    }

    /**
     * @param array<string, array{mode:string,type:string,object:string}> $entries
     * @param array<string, true> $contractPaths
     */
    private function assertCheckoutLiterals(array $entries, array $contractPaths, ProofPlan $plan): void
    {
        foreach ([...$plan->setup, ...$plan->acceptance] as $action) {
            foreach ($action['argv'] as $argument) {
                preg_match_all(
                    '~(?<![A-Za-z0-9._/-])/home/orbit/orbit(?:/([A-Za-z0-9_@.+,=:/-]+))?~D',
                    $argument,
                    $matches,
                );
                foreach ($matches[0] as $index => $literal) {
                    $path = rtrim($matches[1][$index] ?? '', '/');
                    if (
                        $path !== ''
                        && ($this->policy->allowsLiteralPath($path)
                        || $this->coveredByContract($path, $entries, $contractPaths))
                    ) {
                        continue;
                    }

                    throw new InvalidArgumentException(
                        "Proof action [{$action['id']}] reads undeclared checkout input [{$literal}].",
                    );
                }
            }
        }
    }

    /**
     * @param array<string, array{mode:string,type:string,object:string}> $entries
     * @param array<string, true> $contractPaths
     */
    private function coveredByContract(string $path, array $entries, array $contractPaths): bool
    {
        if (isset($contractPaths[$path])) {
            return true;
        }
        $prefix = rtrim($path, '/').'/';
        $descendants = array_filter(
            array_keys($entries),
            static fn (string $candidate): bool => str_starts_with($candidate, $prefix),
        );

        return (
            $descendants !== []
            && array_all($descendants, static fn (string $candidate): bool => isset($contractPaths[$candidate]))
        );
    }
}
