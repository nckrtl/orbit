<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\ProofInputManifest;
use App\E2E\Value\ProofPlan;
use RuntimeException;

/**
 * Validate the same acceptance evidence before capture and after resource release.
 *
 * @mago-expect lint:cyclomatic-complexity The capture boundary checks each recorded identity and completeness guard.
 */
final class ProofEvidence
{
    /** @return array<string, mixed> */
    public static function capture(IssueState $state, ProofPlan $plan): array
    {
        $topology = $state->proofTopology() ?? throw new RuntimeException('No immutable proof evidence is available.');
        $proof = $state->proof() ?? [];
        $expected = array_map(
            static fn (array $action): array => [
                'id' => $action['id'],
                'node' => $action['node'],
                'exit_code' => 0,
            ],
            [...$plan->setup, ...$plan->acceptance],
        );
        if (($proof['actions'] ?? null) !== $expected || ($proof['plan_sha256'] ?? null) !== $plan->fingerprint()) {
            throw new RuntimeException('Proof capture requires the exact plan and complete zero-exit action evidence.');
        }
        $fingerprint = $proof['manifest_sha256'] ?? null;
        if (! is_string($fingerprint)) {
            throw new RuntimeException('Proof capture requires an input manifest.');
        }
        $raw = $state->proofInputManifest($fingerprint) ?? throw new RuntimeException(
            'The proof-input manifest is missing.',
        );
        $manifest = ProofInputManifest::fromArray($raw);
        if (
            $manifest->fingerprint() !== $fingerprint
            || $manifest->provedSha !== ($proof['candidate_sha'] ?? null)
            || $topology->source->hostSha !== $manifest->provedSha
            || $topology->source->guestSha !== $manifest->provedSha
            || $manifest->construction->toArray() !== $topology->construction->toArray()
            || ! $topology->verification->passed
            || $manifest->policyVersion !== StaticProofInputPolicy::VERSION
            || in_array(false, $manifest->completeness, true)
        ) {
            throw new RuntimeException('Proof capture requires complete matching topology and input evidence.');
        }

        return ['proof' => $proof, 'topology' => $topology->toArray(), 'manifest' => $raw];
    }
}
