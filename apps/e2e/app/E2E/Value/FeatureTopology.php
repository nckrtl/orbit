<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** @mago-expect lint:cyclomatic-complexity The manifest schema fails closed on every field. */
final readonly class FeatureTopology
{
    public const int SCHEMA = 2;

    /** The exact attempt this topology belongs to; two attempts of one issue never share resources. */
    public AttemptId $attempt;

    /**
     * @param array<array-key, mixed> $instances
     * @mago-expect lint:excessive-parameter-list The manifest keeps seven independent typed fields.
     */
    public function __construct(
        public TopologyTarget $target,
        public AttemptPurpose $purpose,
        public StandbyGeneration $generation,
        public string $network,
        public array $instances,
        public SourceState $source,
        public VerificationReport $verification,
    ) {
        if ($target->isStandby() || $target->attempt === null) {
            throw new InvalidArgumentException('A feature topology requires an attempt-scoped target.');
        }

        $this->attempt = $target->attempt;

        if ($network !== $target->network() || array_keys($instances) !== TopologyProfile::ROLES) {
            throw new InvalidArgumentException('The topology resources do not match the target.');
        }

        foreach ($instances as $role => $instance) {
            if (! is_string($role)) {
                throw new InvalidArgumentException('Topology instance roles must be strings.');
            }
            if ($instance !== $target->instance($role)) {
                throw new InvalidArgumentException('A topology instance does not match its role.');
            }
        }

        if (count(array_unique($instances)) !== count($instances)) {
            throw new InvalidArgumentException('Topology resource identities must be unique.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'issue' => $this->target->issue,
            'attempt_id' => $this->attempt->value,
            'purpose' => $this->purpose->value,
            'profile' => TopologyProfile::NAME,
            'generation' => $this->generation->toArray(),
            'network' => $this->network,
            'instances' => $this->instances,
            'source' => $this->source->toArray(),
            'verification' => $this->verification->toArray(),
        ];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        $keys = [
            'schema',
            'issue',
            'attempt_id',
            'purpose',
            'profile',
            'generation',
            'network',
            'instances',
            'source',
            'verification',
        ];

        if (
            array_keys($value) !== $keys
            || $value['schema'] !== self::SCHEMA
            || $value['profile'] !== TopologyProfile::NAME
            || ! is_string($value['issue'])
            || ! is_string($value['attempt_id'])
            || ! is_string($value['purpose'])
            || ! is_string($value['network'])
            || ! is_array($value['generation'])
            || ! is_array($value['instances'])
            || ! is_array($value['source'])
            || ! is_array($value['verification'])
        ) {
            throw new InvalidArgumentException('The feature topology schema is invalid.');
        }

        $purpose = AttemptPurpose::tryFrom($value['purpose']);

        if ($purpose === null) {
            throw new InvalidArgumentException('The feature topology attempt purpose is invalid.');
        }

        return new self(
            TopologyTarget::feature($value['issue'], new AttemptId($value['attempt_id'])),
            $purpose,
            StandbyGeneration::fromArray($value['generation']),
            $value['network'],
            $value['instances'],
            SourceState::fromArray($value['source']),
            VerificationReport::fromArray($value['verification']),
        );
    }
}
