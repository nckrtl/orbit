<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** @mago-expect lint:cyclomatic-complexity,excessive-parameter-list The promoted generation validates one atomic identity record. */
final readonly class StandbyGeneration
{
    public const int SCHEMA = 3;

    /** @param array<string, string> $snapshots */
    public function __construct(
        public string $id,
        public string $mainSha,
        public array $snapshots,
        public string $preparedFingerprint,
        public string $baseImageFingerprint,
        public LaravelRelease $laravel,
        public ?string $previousGenerationId = null,
    ) {
        if (
            preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $id) !== 1
            || preg_match('/\A[a-f0-9]{40}\z/D', $mainSha) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $preparedFingerprint) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $baseImageFingerprint) !== 1
            || $previousGenerationId !== null
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $previousGenerationId) !== 1
        ) {
            throw new InvalidArgumentException('The generation identity is invalid.');
        }

        if (array_keys($snapshots) !== TopologyProfile::ROLES) {
            throw new InvalidArgumentException('The generation must contain each ordered role once.');
        }

        foreach ($snapshots as $snapshot) {
            if (preg_match('/\Amain-[A-Za-z0-9][A-Za-z0-9._-]{0,122}\z/D', $snapshot) !== 1) {
                throw new InvalidArgumentException('A snapshot identity is invalid.');
            }
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'id' => $this->id,
            'main_sha' => $this->mainSha,
            'snapshots' => $this->snapshots,
            'prepared_fingerprint' => $this->preparedFingerprint,
            'base_image_fingerprint' => $this->baseImageFingerprint,
            'laravel_pin' => ['tag' => $this->laravel->tag, 'commit' => $this->laravel->commit],
            'previous_generation_id' => $this->previousGenerationId,
        ];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (
            array_keys($value) !== [
                'schema',
                'id',
                'main_sha',
                'snapshots',
                'prepared_fingerprint',
                'base_image_fingerprint',
                'laravel_pin',
                'previous_generation_id',
            ]
            || $value['schema'] !== self::SCHEMA
            || ! is_string($value['id'])
            || ! is_string($value['main_sha'])
            || ! is_array($value['snapshots'])
            || ! is_string($value['prepared_fingerprint'])
            || ! is_string($value['base_image_fingerprint'])
            || ! is_array($value['laravel_pin'])
            || ! is_string($value['laravel_pin']['tag'] ?? null)
            || ! is_string($value['laravel_pin']['commit'] ?? null)
            || $value['previous_generation_id'] !== null
            && ! is_string($value['previous_generation_id'])
        ) {
            throw new InvalidArgumentException('The generation schema is invalid.');
        }

        $snapshots = [];
        foreach ($value['snapshots'] as $role => $snapshot) {
            if (! is_string($role) || ! is_string($snapshot)) {
                throw new InvalidArgumentException('The generation schema is invalid.');
            }
            $snapshots[$role] = $snapshot;
        }

        return new self(
            $value['id'],
            $value['main_sha'],
            $snapshots,
            $value['prepared_fingerprint'],
            $value['base_image_fingerprint'],
            new LaravelRelease($value['laravel_pin']['tag'], $value['laravel_pin']['commit']),
            $value['previous_generation_id'],
        );
    }
}
