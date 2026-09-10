<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final readonly class FeatureTopology
{
    public const int SCHEMA = 4;

    /** The one disk device name a mounted worktree uses on every checkout role. */
    public const string SOURCE_DEVICE = 'orbit-source';

    public TopologyTarget $target;

    public string $network;

    /** @var array<string, string> */
    public array $instances;

    /** The exact attempt this topology belongs to; two attempts of one issue never share resources. */
    public AttemptId $attempt;

    /**
     * @param  array<string, array{device:string,source:string,path:string}>  $mounts
     */
    public function __construct(
        public TopologyConstructionInputs $construction,
        public AttemptPurpose $purpose,
        public TopologySnapshotGeneration $generation,
        public SourceState $source,
        public VerificationReport $verification,
        public array $mounts = [],
    ) {
        $this->target = $this->construction->target;

        $target = $this->target;
        if ($target->isTopologySnapshot() || $target->attempt === null) {
            throw new InvalidArgumentException('A feature topology requires an attempt-scoped target.');
        }

        $this->attempt = $target->attempt;
        if ($this->construction->sourceGeneration !== $generation->id) {
            throw new InvalidArgumentException('The topology construction inputs do not match the target.');
        }

        $this->network = $target->network();
        $instances = [];
        foreach ($this->construction->nodes as $role => $node) {
            $instances[$role] = $node['instance'];
        }
        $this->instances = $instances;

        self::validateMounts($mounts, $source->mounted ? $target->recipe->checkoutNodeKeys() : []);
    }

    /**
     * A mounted source names one identical device on every checkout role and nothing else.
     *
     * @param  array<array-key, mixed>  $mounts
     * @param  list<string>  $mountedRoles
     */
    private static function validateMounts(array $mounts, array $mountedRoles): void
    {
        if (array_keys($mounts) !== $mountedRoles) {
            throw new InvalidArgumentException('The topology mounts do not match the source state.');
        }

        $sources = [];
        foreach ($mounts as $mount) {
            if (
                ! is_array($mount)
                || array_keys($mount) !== ['device', 'source', 'path']
                || $mount['device'] !== self::SOURCE_DEVICE
                || ! is_string($mount['source'])
                || ! is_string($mount['path'])
                || ! MountPath::isSafe($mount['source'])
                || ! MountPath::isSafe($mount['path'])
            ) {
                throw new InvalidArgumentException('A topology mount is invalid.');
            }
            $sources[$mount['source'].':'.$mount['path']] = true;
        }

        if (count($sources) > 1) {
            throw new InvalidArgumentException('Every topology mount must share one source and one path.');
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
            'construction' => $this->construction->toArray(),
            'generation' => $this->generation->toArray(),
            'network' => $this->network,
            'instances' => $this->instances,
            'mounts' => $this->mounts,
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
            'construction',
            'generation',
            'network',
            'instances',
            'mounts',
            'source',
            'verification',
        ];

        if (($value['schema'] ?? null) !== self::SCHEMA) {
            $version = json_encode($value['schema'] ?? null, JSON_THROW_ON_ERROR);

            throw new InvalidArgumentException(
                "The feature topology schema {$version} is not supported; release with the previous harness.",
            );
        }

        if (
            array_keys($value) !== $keys
            || $value['profile'] !== TopologyProfile::NAME
            || ! is_string($value['issue'])
            || ! is_string($value['attempt_id'])
            || ! is_string($value['purpose'])
            || ! is_string($value['network'])
            || ! is_array($value['construction'])
            || ! is_array($value['generation'])
            || ! is_array($value['instances'])
            || ! is_array($value['mounts'])
            || ! is_array($value['source'])
            || ! is_array($value['verification'])
        ) {
            throw new InvalidArgumentException('The feature topology schema is invalid.');
        }

        $purpose = AttemptPurpose::tryFrom($value['purpose']);

        if ($purpose === null) {
            throw new InvalidArgumentException('The feature topology attempt purpose is invalid.');
        }

        /** @var array<string, array{device:string,source:string,path:string}> $mounts */
        $mounts = $value['mounts'];

        $construction = TopologyConstructionInputs::fromArray($value['construction']);

        $topology = new self(
            $construction,
            $purpose,
            TopologySnapshotGeneration::fromArray($value['generation']),
            SourceState::fromArray($value['source']),
            VerificationReport::fromArray($value['verification']),
            $mounts,
        );
        if (
            $value['issue'] !== $topology->target->issue
            || $value['attempt_id'] !== $topology->attempt->value
            || $value['network'] !== $topology->network
            || $value['instances'] !== $topology->instances
        ) {
            throw new InvalidArgumentException('The feature topology identity does not match its construction inputs.');
        }

        return $topology;
    }
}
