<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final readonly class FeatureTopology
{
    public const int SCHEMA = 1;

    /**
     * @param array<array-key, mixed> $instances
     * @mago-expect lint:excessive-parameter-list The manifest keeps six independent typed fields.
     */
    public function __construct(
        public TopologyTarget $target,
        public StandbyGeneration $generation,
        public string $network,
        public array $instances,
        public SourceState $source,
        public VerificationReport $verification,
    ) {
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
        $keys = ['schema', 'issue', 'profile', 'generation', 'network', 'instances', 'source', 'verification'];

        if (
            array_keys($value) !== $keys
            || $value['schema'] !== self::SCHEMA
            || $value['profile'] !== TopologyProfile::NAME
            || ! is_string($value['issue'])
            || ! is_string($value['network'])
            || ! is_array($value['generation'])
            || ! is_array($value['instances'])
            || ! is_array($value['source'])
            || ! is_array($value['verification'])
        ) {
            throw new InvalidArgumentException('The feature topology schema is invalid.');
        }

        return new self(
            new TopologyTarget($value['issue']),
            StandbyGeneration::fromArray($value['generation']),
            $value['network'],
            $value['instances'],
            SourceState::fromArray($value['source']),
            VerificationReport::fromArray($value['verification']),
        );
    }
}
