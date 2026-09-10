<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;
use JsonException;

/** Immutable authorization and exact resource inventory for one clean snapshot replacement. */
final readonly class TopologySnapshotReplacementInstallation
{
    public const int SCHEMA = 1;

    /**
     * @param  array<string, string>  $temporaryInstances
     * @param  array<string, string>  $canonicalInstances
     * @param  array<string, string>  $nextInstances
     * @param  array<string, string>  $oldInstances
     */
    public function __construct(
        public string $issue,
        public AttemptId $proofAttempt,
        public AttemptId $replacementAttempt,
        public OperationId $resourceOperation,
        public string $candidateSha,
        public string $artifactSha,
        public string $mergeSha,
        public string $mainSha,
        public string $capturedProofFingerprint,
        public string $manifestFingerprint,
        public TopologySnapshotGeneration $oldGeneration,
        public TopologySnapshotGeneration $newGeneration,
        public string $genericImageAlias,
        public string $genericImageFingerprint,
        public string $temporaryNetwork,
        public array $temporaryInstances,
        public array $canonicalInstances,
        public array $nextInstances,
        public array $oldInstances,
    ) {
        TopologyTarget::assertIssue($issue);
        if ($proofAttempt->value === $replacementAttempt->value) {
            throw new InvalidArgumentException('The clean replacement attempt must differ from the proof attempt.');
        }
        foreach ([$candidateSha, $artifactSha, $mergeSha, $mainSha] as $sha) {
            if (preg_match('/\A[a-f0-9]{40}\z/D', $sha) !== 1) {
                throw new InvalidArgumentException('A topology snapshot replacement Git identity is invalid.');
            }
        }
        foreach ([$capturedProofFingerprint, $manifestFingerprint, $genericImageFingerprint] as $fingerprint) {
            if (preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
                throw new InvalidArgumentException('A topology snapshot replacement fingerprint is invalid.');
            }
        }
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._\/-]{0,126}\z/D', $genericImageAlias) !== 1) {
            throw new InvalidArgumentException('The topology snapshot replacement image alias is invalid.');
        }
        self::assertResourceName($temporaryNetwork, 'network');
        foreach ([$temporaryInstances, $canonicalInstances, $nextInstances, $oldInstances] as $instances) {
            $this->assertInstanceMap($instances);
        }
        $instanceNames = array_merge(
            array_values($temporaryInstances),
            array_values($canonicalInstances),
            array_values($nextInstances),
            array_values($oldInstances),
        );
        if (count($instanceNames) !== count(array_unique($instanceNames))) {
            throw new InvalidArgumentException('Topology snapshot replacement instance identities must be distinct.');
        }
        if (
            $oldGeneration->id === $newGeneration->id
            || $newGeneration->previousGenerationId !== $oldGeneration->id
            || $newGeneration->mainSha !== $mainSha
            || $newGeneration->baseImageAlias !== $genericImageAlias
            || $newGeneration->baseImageFingerprint !== $genericImageFingerprint
        ) {
            throw new InvalidArgumentException('The topology snapshot replacement generation binding is invalid.');
        }
    }

    public function fingerprint(): string
    {
        try {
            return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'The topology snapshot replacement installation cannot be fingerprinted.',
                previous: $exception,
            );
        }
    }

    public function sameIdentity(self $other): bool
    {
        return hash_equals($this->fingerprint(), $other->fingerprint());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'issue' => $this->issue,
            'proof_attempt_id' => $this->proofAttempt->value,
            'replacement_attempt_id' => $this->replacementAttempt->value,
            'resource_operation_id' => $this->resourceOperation->value,
            'candidate_sha' => $this->candidateSha,
            'artifact_sha' => $this->artifactSha,
            'merge_sha' => $this->mergeSha,
            'main_sha' => $this->mainSha,
            'captured_proof_sha256' => $this->capturedProofFingerprint,
            'manifest_sha256' => $this->manifestFingerprint,
            'old_generation' => $this->oldGeneration->toArray(),
            'new_generation' => $this->newGeneration->toArray(),
            'generic_image_alias' => $this->genericImageAlias,
            'generic_image_sha256' => $this->genericImageFingerprint,
            'temporary_network' => $this->temporaryNetwork,
            'temporary_instances' => $this->temporaryInstances,
            'canonical_instances' => $this->canonicalInstances,
            'next_instances' => $this->nextInstances,
            'old_instances' => $this->oldInstances,
        ];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (
            array_keys($value) !== [
                'schema',
                'issue',
                'proof_attempt_id',
                'replacement_attempt_id',
                'resource_operation_id',
                'candidate_sha',
                'artifact_sha',
                'merge_sha',
                'main_sha',
                'captured_proof_sha256',
                'manifest_sha256',
                'old_generation',
                'new_generation',
                'generic_image_alias',
                'generic_image_sha256',
                'temporary_network',
                'temporary_instances',
                'canonical_instances',
                'next_instances',
                'old_instances',
            ]
            || ($value['schema'] ?? null) !== self::SCHEMA
            || ! is_string($value['issue'] ?? null)
            || ! is_string($value['proof_attempt_id'] ?? null)
            || ! is_string($value['replacement_attempt_id'] ?? null)
            || ! is_string($value['resource_operation_id'] ?? null)
            || ! is_string($value['candidate_sha'] ?? null)
            || ! is_string($value['artifact_sha'] ?? null)
            || ! is_string($value['merge_sha'] ?? null)
            || ! is_string($value['main_sha'] ?? null)
            || ! is_string($value['captured_proof_sha256'] ?? null)
            || ! is_string($value['manifest_sha256'] ?? null)
            || ! is_array($value['old_generation'] ?? null)
            || ! is_array($value['new_generation'] ?? null)
            || ! is_string($value['generic_image_alias'] ?? null)
            || ! is_string($value['generic_image_sha256'] ?? null)
            || ! is_string($value['temporary_network'] ?? null)
            || ! is_array($value['temporary_instances'] ?? null)
            || ! is_array($value['canonical_instances'] ?? null)
            || ! is_array($value['next_instances'] ?? null)
            || ! is_array($value['old_instances'] ?? null)
        ) {
            throw new InvalidArgumentException('The topology snapshot replacement installation schema is invalid.');
        }

        /** @var array<string, string> $temporaryInstances */
        $temporaryInstances = $value['temporary_instances'];
        /** @var array<string, string> $canonicalInstances */
        $canonicalInstances = $value['canonical_instances'];
        /** @var array<string, string> $nextInstances */
        $nextInstances = $value['next_instances'];
        /** @var array<string, string> $oldInstances */
        $oldInstances = $value['old_instances'];
        $installation = new self(
            $value['issue'],
            new AttemptId($value['proof_attempt_id']),
            new AttemptId($value['replacement_attempt_id']),
            new OperationId($value['resource_operation_id']),
            $value['candidate_sha'],
            $value['artifact_sha'],
            $value['merge_sha'],
            $value['main_sha'],
            $value['captured_proof_sha256'],
            $value['manifest_sha256'],
            TopologySnapshotGeneration::fromArray($value['old_generation']),
            TopologySnapshotGeneration::fromArray($value['new_generation']),
            $value['generic_image_alias'],
            $value['generic_image_sha256'],
            $value['temporary_network'],
            $temporaryInstances,
            $canonicalInstances,
            $nextInstances,
            $oldInstances,
        );
        if ($installation->toArray() !== $value) {
            throw new InvalidArgumentException('The topology snapshot replacement installation schema is invalid.');
        }

        return $installation;
    }

    /** @param array<string, string> $instances */
    private function assertInstanceMap(array $instances): void
    {
        if (array_keys($instances) !== TopologyProfile::ROLES) {
            throw new InvalidArgumentException('A replacement instance map must contain each ordered role once.');
        }
        foreach ($instances as $instance) {
            self::assertResourceName($instance, 'instance');
        }
        if (count($instances) !== count(array_unique($instances))) {
            throw new InvalidArgumentException('A replacement instance map must contain distinct identities.');
        }
    }

    private static function assertResourceName(string $name, string $type): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,62}\z/D', $name) !== 1) {
            throw new InvalidArgumentException("The topology snapshot replacement {$type} identity is invalid.");
        }
    }
}
