<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** Immutable successful-proof evidence captured before interactive review. */
final readonly class CapturedProof
{
    public const int SCHEMA = 1;

    /**
     * @param  array<array-key, mixed>  $proof
     * @param  array<array-key, mixed>  $manifest
     */
    public function __construct(
        public string $issue,
        public AttemptId $attempt,
        public string $candidateSha,
        public string $planSha256,
        public string $manifestSha256,
        public array $proof,
        public FeatureTopology $topology,
        public array $manifest,
        public string $capturedAt,
    ) {
        TopologyTarget::assertIssue($issue);
        if (preg_match('/\A[0-9a-f]{40}\z/D', $candidateSha) !== 1) {
            throw new InvalidArgumentException('The captured proof candidate SHA is invalid.');
        }
        foreach ([$planSha256, $manifestSha256] as $fingerprint) {
            if (preg_match('/\A[0-9a-f]{64}\z/D', $fingerprint) !== 1) {
                throw new InvalidArgumentException('A captured proof fingerprint is invalid.');
            }
        }
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $capturedAt) !== 1) {
            throw new InvalidArgumentException('The captured proof time is invalid.');
        }
        if (
            ($proof['status'] ?? null) !== 'proved'
            || ($proof['issue'] ?? null) !== $issue
            || ($proof['attempt_id'] ?? null) !== $attempt->value
            || ($proof['candidate_sha'] ?? null) !== $candidateSha
            || ($proof['plan_sha256'] ?? null) !== $planSha256
            || ($proof['manifest_sha256'] ?? null) !== $manifestSha256
        ) {
            throw new InvalidArgumentException('The captured proof result identity is invalid.');
        }
        if (
            $topology->purpose !== AttemptPurpose::Proof
            || $topology->target->issue !== $issue
            || $topology->attempt->value !== $attempt->value
            || $topology->source->hostSha !== $candidateSha
            || $topology->source->guestSha !== $candidateSha
            || ! $topology->verification->passed
        ) {
            throw new InvalidArgumentException('The captured proof topology identity is invalid.');
        }
        $inputManifest = ProofInputManifest::fromArray($manifest);
        if (
            $inputManifest->provedSha !== $candidateSha
            || $inputManifest->fingerprint() !== $manifestSha256
            || $inputManifest->construction->toArray() !== $topology->construction->toArray()
        ) {
            throw new InvalidArgumentException('The captured proof input manifest identity is invalid.');
        }
    }

    /** @return array{proof:array<array-key, mixed>,topology:array<string, mixed>,manifest:array<array-key, mixed>} */
    public function evidence(): array
    {
        return [
            'proof' => $this->proof,
            'topology' => $this->topology->toArray(),
            'manifest' => $this->manifest,
        ];
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
                'issue',
                'attempt_id',
                'candidate_sha',
                'plan_sha256',
                'manifest_sha256',
                'proof',
                'topology',
                'manifest',
                'captured_at',
                'fingerprint',
            ]
            || ($value['schema'] ?? null) !== self::SCHEMA
            || ! is_string($value['issue'] ?? null)
            || ! is_string($value['attempt_id'] ?? null)
            || ! is_string($value['candidate_sha'] ?? null)
            || ! is_string($value['plan_sha256'] ?? null)
            || ! is_string($value['manifest_sha256'] ?? null)
            || ! is_array($value['proof'] ?? null)
            || ! is_array($value['topology'] ?? null)
            || ! is_array($value['manifest'] ?? null)
            || ! is_string($value['captured_at'] ?? null)
            || ! is_string($value['fingerprint'] ?? null)
        ) {
            throw new InvalidArgumentException('The captured proof schema is invalid.');
        }

        $captured = new self(
            $value['issue'],
            new AttemptId($value['attempt_id']),
            $value['candidate_sha'],
            $value['plan_sha256'],
            $value['manifest_sha256'],
            $value['proof'],
            FeatureTopology::fromArray($value['topology']),
            $value['manifest'],
            $value['captured_at'],
        );
        if (! hash_equals($captured->fingerprint(), $value['fingerprint'])) {
            throw new InvalidArgumentException('The captured proof fingerprint is invalid.');
        }

        return $captured;
    }

    /**
     * Read both the typed schema and captures written before schema 1 existed.
     *
     * @param  array<array-key, mixed>  $value
     */
    public static function fromStoredArray(array $value): self
    {
        if (array_key_exists('schema', $value)) {
            return self::fromArray($value);
        }
        $proof = $value['proof'] ?? null;
        $topology = $value['topology'] ?? null;
        $manifest = $value['manifest'] ?? null;
        if (! is_array($proof) || ! is_array($topology) || ! is_array($manifest)) {
            throw new InvalidArgumentException('The legacy captured proof evidence is incomplete.');
        }
        $recordedAt = $proof['recorded_at'] ?? null;
        if (! is_string($recordedAt)) {
            throw new InvalidArgumentException('The legacy captured proof time is missing.');
        }

        return new self(
            (string) ($proof['issue'] ?? ''),
            new AttemptId((string) ($proof['attempt_id'] ?? '')),
            (string) ($proof['candidate_sha'] ?? ''),
            (string) ($proof['plan_sha256'] ?? ''),
            (string) ($proof['manifest_sha256'] ?? ''),
            $proof,
            FeatureTopology::fromArray($topology),
            $manifest,
            $recordedAt,
        );
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'schema' => self::SCHEMA,
            'issue' => $this->issue,
            'attempt_id' => $this->attempt->value,
            'candidate_sha' => $this->candidateSha,
            'plan_sha256' => $this->planSha256,
            'manifest_sha256' => $this->manifestSha256,
            'proof' => $this->proof,
            'topology' => $this->topology->toArray(),
            'manifest' => $this->manifest,
            'captured_at' => $this->capturedAt,
        ];
    }
}
