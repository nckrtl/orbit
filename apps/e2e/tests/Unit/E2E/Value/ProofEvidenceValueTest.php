<?php

declare(strict_types=1);

use App\E2E\Value\ObservedPhpInputs;
use App\E2E\Value\ProofEquivalenceReport;
use App\E2E\Value\ProofEquivalenceResult;
use App\E2E\Value\ProofInputManifest;
use App\E2E\Value\ProofPromotionRecord;

require_once dirname(__DIR__).'/Support/ObservedPhpRuntimeFixtures.php';

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

        $malformed = $observed->toArray();
        $malformed['phases']['setup'] = 'invalid';

        expect(fn () => ObservedPhpInputs::fromArray($malformed))
            ->toThrow(InvalidArgumentException::class, 'setup surfaces are invalid');

        $different = $runtime('gateway');
        $different['package_versions']['php8.5-pcov'] = '1.0.13-sury';

        expect(fn () => new ObservedPhpInputs(
            [$runtime('app-dev'), $different],
            ['setup' => $surfaces, 'acceptance' => $surfaces],
        ))
            ->toThrow(InvalidArgumentException::class, 'not identical');
    });

    it('rejects the live malformed runtime fixtures at the retained evidence boundary', function (string $fixture): void {
        $runtime = malformedObservedPhpRuntime($fixture);
        $entry = static fn (string $role): array => ['role' => $role, ...$runtime];
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

        expect(fn () => new ObservedPhpInputs(
            [$entry('app-dev'), $entry('gateway')],
            ['setup' => $surfaces, 'acceptance' => $surfaces],
        ))
            ->toThrow(InvalidArgumentException::class, 'runtime entry is invalid');
    })->with([
        'PHP version' => 'php-version',
        'PCOV version' => 'pcov-version',
        'package version' => 'package-version',
    ]);

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
            [],
            '2026-09-02T10:00:00Z',
        );

        $serialized = json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        expect($serialized)
            ->toBe(<<<'JSON'
                {"schema":1,"proved_sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","accepted_sha":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","included_main_sha":"cccccccccccccccccccccccccccccccccccccccc","plan_sha256":"dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd","manifest_sha256":"eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee","result":"equivalent","changed_paths":[{"path":"docs/reference/note.md","previous_path":null,"change":"content-changed","classification":"non-runtime"}],"promotion_path":"retained-proof","next_action":"review-exact-head","errors":[],"recorded_at":"2026-09-02T10:00:00Z","fingerprint":"6eb0b5c2d7f19595d03a8b09fcfce769840f883db393c8c6b8f8ac811298ef58"}
                JSON)
            ->and(ProofEquivalenceReport::fromArray($report->toArray())->toArray())
            ->toBe($report->toArray())
            ->and(fn () => new ProofEquivalenceReport(
                str_repeat('a', 40),
                str_repeat('b', 40),
                str_repeat('c', 40),
                str_repeat('d', 64),
                str_repeat('e', 64),
                ProofEquivalenceResult::Stale,
                [],
                [],
                '2026-09-02T10:00:00Z',
            ))
            ->toThrow(InvalidArgumentException::class, 'decision is invalid');

        $tampered = $report->toArray();
        $tampered['promotion_path'] = 'candidate-convergence';
        $tampered['next_action'] = 'run-candidate-convergence';
        unset($tampered['fingerprint']);
        $tampered['fingerprint'] = hash('sha256', json_encode(
            $tampered,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        expect(fn () => ProofEquivalenceReport::fromArray($tampered))
            ->toThrow(InvalidArgumentException::class, 'decision is invalid');
    });

    it('derives the follow-up policy for every equivalence outcome', function (
        ProofEquivalenceResult $result,
        array $changedPaths,
        array $errors,
        ?string $promotionPath,
        string $nextAction,
    ): void {
        $report = new ProofEquivalenceReport(
            str_repeat('a', 40),
            str_repeat('b', 40),
            str_repeat('c', 40),
            str_repeat('d', 64),
            str_repeat('e', 64),
            $result,
            $changedPaths,
            $errors,
            '2026-09-02T10:00:00Z',
        );

        expect($report->promotionPath)
            ->toBe($promotionPath)
            ->and($report->nextAction)
            ->toBe($nextAction);
    })->with([
        'exact' => [ProofEquivalenceResult::Exact, [], [], 'retained-proof', 'review-exact-head'],
        'equivalent non-runtime' => [
            ProofEquivalenceResult::Equivalent,
            [[
                'path' => 'docs/reference/note.md',
                'previous_path' => null,
                'change' => 'content-changed',
                'classification' => 'non-runtime',
            ]],
            [],
            'retained-proof',
            'review-exact-head',
        ],
        'equivalent unrelated runtime' => [
            ProofEquivalenceResult::Equivalent,
            [[
                'path' => 'apps/cli/app/Unrelated.php',
                'previous_path' => null,
                'change' => 'content-changed',
                'classification' => 'unrelated-runtime',
            ]],
            [],
            'candidate-convergence',
            'run-candidate-convergence',
        ],
        'stale' => [
            ProofEquivalenceResult::Stale,
            [[
                'path' => 'apps/cli/app/Runtime.php',
                'previous_path' => null,
                'change' => 'content-changed',
                'classification' => 'runtime',
            ]],
            [],
            null,
            'release-proof-and-run-complete-reproof',
        ],
        'indeterminate' => [
            ProofEquivalenceResult::Indeterminate,
            [],
            ['Unknown proof input.'],
            null,
            'resolve-equivalence-failure-and-run-complete-reproof',
        ],
    ]);

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
