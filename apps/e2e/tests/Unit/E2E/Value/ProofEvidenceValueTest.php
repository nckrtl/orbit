<?php

declare(strict_types=1);

use App\E2E\Value\ProofEquivalenceReport;
use App\E2E\Value\ProofEquivalenceResult;
use App\E2E\Value\ProofInputManifest;
use App\E2E\Value\ProofPromotionRecord;

describe('proof reuse evidence', function (): void {
    it('round-trips canonical immutable manifests and refuses fingerprint tampering', function (): void {
        $manifest = new ProofInputManifest(
            2,
            str_repeat('a', 40),
            str_repeat('b', 40),
            ['apps/cli/app/Feature.php'],
            [[
                'path' => 'apps/cli/app/Feature.php',
                'classification' => 'runtime',
                'mode' => '100644',
                'blob' => str_repeat('c', 40),
            ]],
            'proofs/ORB-99.json',
            [],
            [],
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

        expect(ProofInputManifest::fromArray($manifest->toArray())->toArray())->toBe($manifest->toArray());
        $tampered = $manifest->toArray();
        $tampered['proved_sha'] = str_repeat('d', 40);

        expect(fn () => ProofInputManifest::fromArray($tampered))
            ->toThrow(InvalidArgumentException::class, 'fingerprint is invalid');
    });

    it('round-trips equivalence decisions and binds promotability to exact or equivalent results', function (): void {
        $report = new ProofEquivalenceReport(
            str_repeat('a', 40),
            str_repeat('b', 40),
            str_repeat('c', 40),
            str_repeat('d', 64),
            str_repeat('e', 64),
            ProofEquivalenceResult::Equivalent,
            [[
                'path' => 'docs/reference/note.md',
                'previous_path' => null,
                'change' => 'content-changed',
                'classification' => 'non-runtime',
            ]],
            'retained-proof',
            'review-exact-head',
            [],
            '2026-09-02T10:00:00Z',
        );

        expect(ProofEquivalenceReport::fromArray($report->toArray())->toArray())
            ->toBe($report->toArray())
            ->and(fn () => new ProofEquivalenceReport(
                str_repeat('a', 40),
                str_repeat('b', 40),
                str_repeat('c', 40),
                str_repeat('d', 64),
                str_repeat('e', 64),
                ProofEquivalenceResult::Stale,
                [],
                'retained-proof',
                'reproof',
                [],
                '2026-09-02T10:00:00Z',
            ))
            ->toThrow(InvalidArgumentException::class, 'decision is invalid');
    });

    it('binds unrelated runtime equivalence to candidate convergence', function (): void {
        $report = new ProofEquivalenceReport(
            str_repeat('a', 40),
            str_repeat('b', 40),
            str_repeat('c', 40),
            str_repeat('d', 64),
            str_repeat('e', 64),
            ProofEquivalenceResult::Equivalent,
            [[
                'path' => 'apps/cli/app/Unrelated.php',
                'previous_path' => null,
                'change' => 'content-changed',
                'classification' => 'unrelated-runtime',
            ]],
            'candidate-convergence',
            'run-candidate-convergence',
            [],
            '2026-09-02T10:00:00Z',
        );

        expect(ProofEquivalenceReport::fromArray($report->toArray())->promotionPath)
            ->toBe('candidate-convergence');
    });

    it('records proved, accepted, merged, and runtime lineage for retained promotion', function (): void {
        $record = new ProofPromotionRecord(
            'ORB-99',
            'generation-1',
            str_repeat('a', 40),
            str_repeat('b', 40),
            str_repeat('c', 40),
            str_repeat('d', 64),
            str_repeat('e', 64),
            str_repeat('f', 64),
            '2026-09-02T10:00:00Z',
        );

        expect($record->toArray())->toMatchArray([
            'promotion_path' => 'retained-proof',
            'proved_sha' => str_repeat('a', 40),
            'accepted_sha' => str_repeat('b', 40),
            'merged_sha' => str_repeat('c', 40),
            'runtime_fingerprint' => str_repeat('d', 64),
            'manifest_sha256' => str_repeat('e', 64),
            'equivalence_sha256' => str_repeat('f', 64),
        ]);
    });
});
