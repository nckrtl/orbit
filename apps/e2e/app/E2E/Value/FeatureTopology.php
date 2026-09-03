<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** @mago-expect lint:cyclomatic-complexity The manifest schema fails closed on every field. */
final readonly class FeatureTopology
{
    public const int SCHEMA = 3;

    /** The one disk device name a mounted worktree uses on every checkout role. */
    public const string SOURCE_DEVICE = 'orbit-source';

    /** The exact attempt this topology belongs to; two attempts of one issue never share resources. */
    public AttemptId $attempt;

    /**
     * @param array<array-key, mixed> $instances
     * @param array<string, array{device:string,source:string,path:string}> $mounts
     * @mago-expect lint:excessive-parameter-list The manifest keeps eight independent typed fields.
     */
    public function __construct(
        public TopologyTarget $target,
        public AttemptPurpose $purpose,
        public TopologySnapshotGeneration $generation,
        public string $network,
        public array $instances,
        public SourceState $source,
        public VerificationReport $verification,
        public array $mounts = [],
    ) {
        if ($target->isTopologySnapshot() || $target->attempt === null) {
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

        self::validateMounts($mounts, $source->mounted ? TopologyProfile::CHECKOUT_ROLES : []);
    }

    /**
     * A mounted source names one identical device on every checkout role and nothing else.
     *
     * @param array<array-key, mixed> $mounts
     * @param list<string> $mountedRoles
     */
    private static function validateMounts(array $mounts, array $mountedRoles): void
    {
        if (array_keys($mounts) !== $mountedRoles) {
            throw new InvalidArgumentException('The topology mounts do not match the source state.');
        }

        $sources = [];
        /** @mago-expect analysis:mixed-assignment Serialized input is validated one mount at a time. */
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

        return new self(
            TopologyTarget::feature($value['issue'], new AttemptId($value['attempt_id'])),
            $purpose,
            TopologySnapshotGeneration::fromArray($value['generation']),
            $value['network'],
            $value['instances'],
            SourceState::fromArray($value['source']),
            VerificationReport::fromArray($value['verification']),
            $mounts,
        );
    }
}
