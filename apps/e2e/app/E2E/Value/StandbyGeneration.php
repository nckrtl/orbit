<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** @mago-expect lint:cyclomatic-complexity,excessive-parameter-list,kan-defect The promoted generation validates one atomic identity record. */
final readonly class StandbyGeneration
{
    public const int SCHEMA = 6;
    public const int ASSIGNMENT_SCHEMA = 5;
    public const int LEGACY_SCHEMA = 4;

    /** @param array<string, string> $snapshots */
    public function __construct(
        public string $id,
        public string $mainSha,
        public array $snapshots,
        public string $preparedFingerprint,
        public string $baseImageFingerprint,
        public LaravelRelease $laravel,
        public string $structuralFingerprint,
        public int $preparedSchema,
        public string $coldEpoch,
        public string $baseImageAlias,
        public string $topologyProfile,
        /** @var list<string> */
        public array $topologyRoles,
        /** @var list<string> */
        public array $checkoutRoles,
        public ?string $previousGenerationId = null,
        /** @var array<string, list<string>>|null */
        public ?array $topologyAssignments = TopologyProfile::ASSIGNMENTS,
        public int $manifestSchema = self::SCHEMA,
        public ?string $standbyNamespace = null,
    ) {
        if (
            preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $id) !== 1
            || preg_match('/\A[a-f0-9]{40}\z/D', $mainSha) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $preparedFingerprint) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $baseImageFingerprint) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $structuralFingerprint) !== 1
            || $preparedSchema < 1
            || $coldEpoch === ''
            || $baseImageAlias === ''
            || $topologyProfile === ''
            || ! in_array(
                $manifestSchema,
                [self::LEGACY_SCHEMA, self::ASSIGNMENT_SCHEMA, self::SCHEMA],
                true,
            )
            || $previousGenerationId !== null
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $previousGenerationId) !== 1
            || $standbyNamespace !== null
            && $standbyNamespace !== ''
            && preg_match('/\A[a-z][a-z0-9-]{0,31}\z/D', $standbyNamespace) !== 1
            || $manifestSchema === self::SCHEMA
            && $standbyNamespace === null
            || $manifestSchema !== self::SCHEMA
            && $standbyNamespace !== null
        ) {
            throw new InvalidArgumentException('The generation identity is invalid.');
        }

        if (array_keys($snapshots) !== TopologyProfile::ROLES) {
            throw new InvalidArgumentException('The generation must contain each ordered role once.');
        }

        if (
            serialize($topologyRoles) !== serialize(TopologyProfile::ROLES)
            || serialize($checkoutRoles) !== serialize(TopologyProfile::CHECKOUT_ROLES)
        ) {
            throw new InvalidArgumentException('The generation topology profile is invalid.');
        }

        if (
            $manifestSchema !== self::LEGACY_SCHEMA
            && ($preparedSchema !== 2
            || serialize($topologyAssignments) !== serialize(TopologyProfile::ASSIGNMENTS))
        ) {
            throw new InvalidArgumentException('The generation assignment declaration is invalid.');
        }

        if ($manifestSchema === self::LEGACY_SCHEMA && $topologyAssignments !== null) {
            throw new InvalidArgumentException('A legacy generation cannot declare assignments.');
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
        $topology = [
            'profile' => $this->topologyProfile,
            'roles' => $this->topologyRoles,
            'checkout_roles' => $this->checkoutRoles,
        ];
        if ($this->topologyAssignments !== null) {
            $topology['assignments'] = $this->topologyAssignments;
        }

        return [
            'schema' => $this->manifestSchema,
            'id' => $this->id,
            ...(
                $this->manifestSchema === self::SCHEMA
                    ? ['standby_namespace' => $this->standbyNamespace]
                    : []
            ),
            'main_sha' => $this->mainSha,
            'snapshots' => $this->snapshots,
            'prepared_fingerprint' => $this->preparedFingerprint,
            'base_image_fingerprint' => $this->baseImageFingerprint,
            'structural_fingerprint' => $this->structuralFingerprint,
            'prepared_schema' => $this->preparedSchema,
            'cold_epoch' => $this->coldEpoch,
            'base_image_alias' => $this->baseImageAlias,
            'topology' => $topology,
            'laravel_pin' => ['tag' => $this->laravel->tag, 'commit' => $this->laravel->commit],
            'previous_generation_id' => $this->previousGenerationId,
        ];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        $schema = $value['schema'] ?? null;
        $expectedKeys = match ($schema) {
            self::LEGACY_SCHEMA, self::ASSIGNMENT_SCHEMA => [
                'schema',
                'id',
                'main_sha',
                'snapshots',
                'prepared_fingerprint',
                'base_image_fingerprint',
                'structural_fingerprint',
                'prepared_schema',
                'cold_epoch',
                'base_image_alias',
                'topology',
                'laravel_pin',
                'previous_generation_id',
            ],
            self::SCHEMA => [
                'schema',
                'id',
                'standby_namespace',
                'main_sha',
                'snapshots',
                'prepared_fingerprint',
                'base_image_fingerprint',
                'structural_fingerprint',
                'prepared_schema',
                'cold_epoch',
                'base_image_alias',
                'topology',
                'laravel_pin',
                'previous_generation_id',
            ],
            default => [],
        };

        if (
            ! in_array($schema, [self::LEGACY_SCHEMA, self::ASSIGNMENT_SCHEMA, self::SCHEMA], true)
            || array_keys($value) !== $expectedKeys
            || ! is_string($value['id'])
            || $schema === self::SCHEMA
            && ! is_string($value['standby_namespace'])
            || ! is_string($value['main_sha'])
            || ! is_array($value['snapshots'])
            || ! is_string($value['prepared_fingerprint'])
            || ! is_string($value['base_image_fingerprint'])
            || ! is_string($value['structural_fingerprint'])
            || ! is_int($value['prepared_schema'])
            || ! is_string($value['cold_epoch'])
            || ! is_string($value['base_image_alias'])
            || ! is_array($value['topology'])
            || ! is_string($value['topology']['profile'] ?? null)
            || ! is_array($value['topology']['roles'] ?? null)
            || ! is_array($value['topology']['checkout_roles'] ?? null)
            || ! is_array($value['laravel_pin'])
            || ! is_string($value['laravel_pin']['tag'] ?? null)
            || ! is_string($value['laravel_pin']['commit'] ?? null)
            || $value['previous_generation_id'] !== null
            && ! is_string($value['previous_generation_id'])
        ) {
            throw new InvalidArgumentException('The generation schema is invalid.');
        }

        $topologyKeys = array_keys($value['topology']);
        $expectedTopologyKeys = $schema === self::LEGACY_SCHEMA
            ? ['profile', 'roles', 'checkout_roles']
            : ['profile', 'roles', 'checkout_roles', 'assignments'];
        if ($topologyKeys !== $expectedTopologyKeys) {
            throw new InvalidArgumentException('The generation schema is invalid.');
        }

        $snapshots = [];
        foreach ($value['snapshots'] as $role => $snapshot) {
            if (! is_string($role) || ! is_string($snapshot)) {
                throw new InvalidArgumentException('The generation schema is invalid.');
            }
            $snapshots[$role] = $snapshot;
        }

        if (
            ! array_all($value['topology']['roles'], static fn (mixed $item, string|int $key): bool => is_string($item))
            || ! array_all($value['topology']['checkout_roles'], static fn (
                mixed $item,
                string|int $key,
            ): bool => is_string($item))
        ) {
            throw new InvalidArgumentException('The generation schema is invalid.');
        }
        /** @var list<string> $topologyRoles */
        $topologyRoles = array_values($value['topology']['roles']);
        /** @var list<string> $checkoutRoles */
        $checkoutRoles = array_values($value['topology']['checkout_roles']);
        /** @var array<string, list<string>>|null $assignments */
        $assignments = null;
        if ($schema !== self::LEGACY_SCHEMA) {
            if (! is_array($value['topology']['assignments']) || array_is_list($value['topology']['assignments'])) {
                throw new InvalidArgumentException('The generation schema is invalid.');
            }
            $assignments = [];
            foreach ($value['topology']['assignments'] as $node => $rolesForNode) {
                if (
                    ! is_string($node)
                    || ! is_array($rolesForNode)
                    || ! array_is_list($rolesForNode)
                    || ! array_all($rolesForNode, static fn (mixed $role): bool => is_string($role))
                ) {
                    throw new InvalidArgumentException('The generation schema is invalid.');
                }
                /** @var list<string> $orderedRoles */
                $orderedRoles = array_values($rolesForNode);
                $assignments[$node] = $orderedRoles;
            }
        }
        /** @var ?string $standbyNamespace */
        $standbyNamespace = $schema === self::SCHEMA ? $value['standby_namespace'] : null;

        return new self(
            $value['id'],
            $value['main_sha'],
            $snapshots,
            $value['prepared_fingerprint'],
            $value['base_image_fingerprint'],
            new LaravelRelease($value['laravel_pin']['tag'], $value['laravel_pin']['commit']),
            $value['structural_fingerprint'],
            $value['prepared_schema'],
            $value['cold_epoch'],
            $value['base_image_alias'],
            $value['topology']['profile'],
            $topologyRoles,
            $checkoutRoles,
            $value['previous_generation_id'],
            $assignments,
            $schema,
            $standbyNamespace,
        );
    }

    public function isLegacy(): bool
    {
        return $this->manifestSchema === self::LEGACY_SCHEMA;
    }
}
