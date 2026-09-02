<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/**
 * Durable lineage for the exact runtime state installed by proof promotion.
 *
 * @mago-expect lint:excessive-parameter-list Every independent lineage identity is explicit.
 */
final readonly class ProofPromotionRecord
{
    public const int SCHEMA = 1;

    public function __construct(
        public string $issue,
        public string $generationId,
        public string $provedSha,
        public string $acceptedSha,
        public string $mergedSha,
        public string $runtimeFingerprint,
        public string $manifestSha256,
        public ?string $equivalenceSha256,
        public string $recordedAt,
    ) {
        TopologyTarget::assertIssue($issue);
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $generationId) !== 1) {
            throw new InvalidArgumentException('The promoted generation identity is invalid.');
        }
        foreach ([$provedSha, $acceptedSha, $mergedSha] as $sha) {
            if (preg_match('/\A[0-9a-f]{40}\z/D', $sha) !== 1) {
                throw new InvalidArgumentException('A proof promotion Git identity is invalid.');
            }
        }
        foreach ([$runtimeFingerprint, $manifestSha256] as $fingerprint) {
            if (preg_match('/\A[0-9a-f]{64}\z/D', $fingerprint) !== 1) {
                throw new InvalidArgumentException('A proof promotion fingerprint is invalid.');
            }
        }
        if (
            $equivalenceSha256 !== null
            && preg_match('/\A[0-9a-f]{64}\z/D', $equivalenceSha256) !== 1
        ) {
            throw new InvalidArgumentException('The proof promotion equivalence fingerprint is invalid.');
        }
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $recordedAt) !== 1) {
            throw new InvalidArgumentException('The proof promotion time is invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'state' => 'promoted',
            'promotion_path' => 'retained-proof',
            'issue' => $this->issue,
            'generation_id' => $this->generationId,
            'proved_sha' => $this->provedSha,
            'accepted_sha' => $this->acceptedSha,
            'merged_sha' => $this->mergedSha,
            'runtime_fingerprint' => $this->runtimeFingerprint,
            'manifest_sha256' => $this->manifestSha256,
            'equivalence_sha256' => $this->equivalenceSha256,
            'promoted_runtime' => [
                'main_sha' => $this->mergedSha,
                'prepared_fingerprint' => $this->runtimeFingerprint,
            ],
            'recorded_at' => $this->recordedAt,
        ];
    }
}
