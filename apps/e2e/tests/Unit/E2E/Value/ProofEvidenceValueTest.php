<?php

declare(strict_types=1);

use App\E2E\Value\ObservedPhpInputs;
use App\E2E\Value\ProofEquivalenceReport;
use App\E2E\Value\ProofEquivalenceResult;
use App\E2E\Value\ProofInputManifest;
use App\E2E\Value\ProofPromotionRecord;

describe('proof reuse evidence', function (): void {
    it('requires identical CLI, FPM, PCOV, and package runtime evidence across roles', function (): void {
        $packages = array_fill_keys(ObservedPhpInputs::PACKAGES, '8.5.10-sury');
        $packages['php8.5-pcov'] = '1.0.12-sury';
        $runtime = static fn (string $role): array => [
            'role' => $role,
            'php_version' => '8.5.10',
            'fpm_version' => '8.5.10',
            'pcov_version' => '1.0.12',
            'package_versions' => $packages,
        ];
        $surface = static fn (string $role, string $type, string $id): array => [
            'role' => $role,
            'process_type' => $type,
            'processes' => [[
                'id' => str_repeat($id, 32),
                'started_at' => '2026-09-03T10:00:00.000001Z',
                'finished_at' => '2026-09-03T10:00:00.000002Z',
            ]],
            'paths' => ['apps/cli/orbit'],
        ];
        $surfaces = [
            $surface('app-dev', 'cli', '1'),
            $surface('gateway', 'cli', '2'),
            $surface('gateway', 'fpm', '3'),
        ];
        $observed = new ObservedPhpInputs(
            [$runtime('app-dev'), $runtime('gateway')],
            ['setup' => $surfaces, 'acceptance' => $surfaces],
        );

        expect(ObservedPhpInputs::fromArray($observed->toArray())->toArray())->toBe($observed->toArray());

        $different = $runtime('gateway');
        $different['package_versions']['php8.5-pcov'] = '1.0.13-sury';

        expect(fn () => new ObservedPhpInputs(
            [$runtime('app-dev'), $different],
            ['setup' => $surfaces, 'acceptance' => $surfaces],
        ))
            ->toThrow(InvalidArgumentException::class, 'not identical');
    });

    it('round-trips canonical immutable manifests and refuses fingerprint tampering', function (): void {
        $manifest = new ProofInputManifest(
            3,
            str_repeat('a', 40),
            str_repeat('b', 40),
            ['apps/cli/app/Feature.php'],
            [[
                'path' => 'apps/cli/app/Feature.php',
                'classification' => 'runtime',
                'mode' => '100644',
                'blob' => str_repeat('c', 40),
            ]],
            '.loop/proof/AUX-99.json',
            [],
            topologyConstructionFixture(),
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
            'AUX-99',
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
