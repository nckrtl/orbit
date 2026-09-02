<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/**
 * One immutable decision relating a proved SHA to one later accepted SHA.
 *
 * @mago-expect lint:cyclomatic-complexity,kan-defect,excessive-parameter-list The immutable evidence boundary validates every decision field together.
 */
final readonly class ProofEquivalenceReport
{
    public const int SCHEMA = 1;

    private const array CHANGE_KINDS = [
        'added',
        'deleted',
        'renamed',
        'content-changed',
        'mode-changed',
        'content-and-mode-changed',
        'type-changed',
    ];

    /**
     * @param list<array{path:string,previous_path:?string,change:string,classification:string}> $changedPaths
     * @param list<string> $errors
     */
    public function __construct(
        public string $provedSha,
        public string $acceptedSha,
        public string $includedMainSha,
        public string $planSha256,
        public string $manifestSha256,
        public ProofEquivalenceResult $result,
        public array $changedPaths,
        public ?string $promotionPath,
        public string $nextAction,
        public array $errors,
        public string $recordedAt,
    ) {
        if (! array_is_list($changedPaths)) {
            throw new InvalidArgumentException('The equivalence report path decisions are invalid.');
        }
        foreach ([$provedSha, $acceptedSha, $includedMainSha] as $sha) {
            if (preg_match('/\A[0-9a-f]{40}\z/D', $sha) !== 1) {
                throw new InvalidArgumentException('An equivalence report Git identity is invalid.');
            }
        }
        foreach ([$planSha256, $manifestSha256] as $fingerprint) {
            if (preg_match('/\A[0-9a-f]{64}\z/D', $fingerprint) !== 1) {
                throw new InvalidArgumentException('An equivalence report fingerprint is invalid.');
            }
        }
        $orderedChanges = [];
        foreach ($changedPaths as $change) {
            if (
                array_keys($change) !== ['path', 'previous_path', 'change', 'classification']
                || ! is_string($change['path'])
                || $change['path'] === ''
                || ! is_string($change['change'])
                || ! in_array($change['change'], self::CHANGE_KINDS, true)
                || ! is_string($change['classification'])
                || ! in_array($change['classification'], array_column(ProofInputClassification::cases(), 'value'), true)
                || $change['previous_path'] !== null
                && ! is_string($change['previous_path'])
            ) {
                throw new InvalidArgumentException('An equivalence report path decision is invalid.');
            }
            $orderedChanges[] = [$change['path'], $change['previous_path'] ?? ''];
        }
        if (! array_is_list($errors) || ! array_all($errors, static fn (mixed $error): bool => is_string($error))) {
            throw new InvalidArgumentException('The equivalence report errors are invalid.');
        }
        $sortedChanges = $orderedChanges;
        sort($sortedChanges, SORT_REGULAR);
        $classifications = array_column($changedPaths, 'classification');
        $hasMaterial = array_intersect($classifications, [
            ProofInputClassification::Runtime->value,
            ProofInputClassification::ProofContract->value,
        ]) !== [];
        $hasIndeterminate = in_array(ProofInputClassification::Indeterminate->value, $classifications, true);
        $decisionValid = match ($result) {
            ProofEquivalenceResult::Exact => $changedPaths === [] && $errors === [],
            ProofEquivalenceResult::Equivalent => $changedPaths !== []
                && ! $hasMaterial
                && ! $hasIndeterminate
                && $errors === [],
            ProofEquivalenceResult::Stale => $hasMaterial && $errors === [],
            ProofEquivalenceResult::Indeterminate => $errors !== [] || $hasIndeterminate,
        };
        $expectedNextAction = match ($result) {
            ProofEquivalenceResult::Exact, ProofEquivalenceResult::Equivalent => 'review-exact-head',
            ProofEquivalenceResult::Stale => 'release-proof-and-run-complete-reproof',
            ProofEquivalenceResult::Indeterminate => 'resolve-equivalence-failure-and-run-complete-reproof',
        };
        if (
            $promotionPath !== null
            && $promotionPath !== 'retained-proof'
            || in_array($result, [ProofEquivalenceResult::Exact, ProofEquivalenceResult::Equivalent], true)
                !== ($promotionPath !== null)
            || $nextAction !== $expectedNextAction
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $recordedAt) !== 1
            || $orderedChanges !== $sortedChanges
            || count($orderedChanges) !== count(array_unique(array_map(serialize(...), $orderedChanges)))
            || count($errors) !== count(array_unique($errors))
            || ! $decisionValid
        ) {
            throw new InvalidArgumentException('The equivalence report decision is invalid.');
        }
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode(
            $this->payload(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [...$this->payload(), 'fingerprint' => $this->fingerprint()];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (
            array_keys($value) !== [
                'schema',
                'proved_sha',
                'accepted_sha',
                'included_main_sha',
                'plan_sha256',
                'manifest_sha256',
                'result',
                'changed_paths',
                'promotion_path',
                'next_action',
                'errors',
                'recorded_at',
                'fingerprint',
            ]
            || ($value['schema'] ?? null) !== self::SCHEMA
            || ! is_string($value['proved_sha'])
            || ! is_string($value['accepted_sha'])
            || ! is_string($value['included_main_sha'])
            || ! is_string($value['plan_sha256'])
            || ! is_string($value['manifest_sha256'])
            || ! is_string($value['result'])
            || ! is_array($value['changed_paths'])
            || $value['promotion_path'] !== null
            && ! is_string($value['promotion_path'])
            || ! is_string($value['next_action'])
            || ! is_array($value['errors'])
            || ! is_string($value['recorded_at'])
            || ! is_string($value['fingerprint'])
            || ! array_is_list($value['changed_paths'])
            || ! array_all($value['changed_paths'], static fn (mixed $item): bool => is_array($item))
            || ! array_is_list($value['errors'])
            || ! array_all($value['errors'], static fn (mixed $item): bool => is_string($item))
        ) {
            throw new InvalidArgumentException('The equivalence report schema is invalid.');
        }
        $result = ProofEquivalenceResult::tryFrom($value['result']);
        if ($result === null) {
            throw new InvalidArgumentException('The equivalence report result is invalid.');
        }
        /** @var list<array{path:string,previous_path:?string,change:string,classification:string}> $changedPaths */
        $changedPaths = array_values($value['changed_paths']);
        /** @var list<string> $errors */
        $errors = array_values($value['errors']);
        $report = new self(
            $value['proved_sha'],
            $value['accepted_sha'],
            $value['included_main_sha'],
            $value['plan_sha256'],
            $value['manifest_sha256'],
            $result,
            $changedPaths,
            $value['promotion_path'],
            $value['next_action'],
            $errors,
            $value['recorded_at'],
        );
        if (! hash_equals($report->fingerprint(), $value['fingerprint'])) {
            throw new InvalidArgumentException('The equivalence report fingerprint is invalid.');
        }

        return $report;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'schema' => self::SCHEMA,
            'proved_sha' => $this->provedSha,
            'accepted_sha' => $this->acceptedSha,
            'included_main_sha' => $this->includedMainSha,
            'plan_sha256' => $this->planSha256,
            'manifest_sha256' => $this->manifestSha256,
            'result' => $this->result->value,
            'changed_paths' => $this->changedPaths,
            'promotion_path' => $this->promotionPath,
            'next_action' => $this->nextAction,
            'errors' => $this->errors,
            'recorded_at' => $this->recordedAt,
        ];
    }
}
