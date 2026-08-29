<?php

declare(strict_types=1);

namespace App\E2E\Value;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * @mago-expect lint:cyclomatic-complexity Serialized inventory validation checks exact kinds, order, and identities.
 * @mago-expect lint:kan-defect The serialized boundary intentionally validates every nested field.
 * @mago-expect analysis:impossible-type-comparison Runtime serialized input can violate PHPDoc nested shapes.
 * @mago-expect analysis:mixed-argument Nested values are type-checked immediately before digest operations.
 */
final readonly class RetirementInventory
{
    public const array CANDIDATE_KINDS = ['snapshots', 'instances', 'networks', 'source_paths', 'manifests', 'locks'];
    public const array PRESERVED_KINDS = [
        'instances',
        'snapshots',
        'networks',
        'source_paths',
        'manifests',
        'locks',
        'base_images',
        'pools',
        'new_namespace',
        'evidence',
    ];

    /** @param array<string, list<array<string, mixed>>> $candidates @param array<string, list<array<string, mixed>>> $preserved */
    public function __construct(
        public array $candidates,
        public array $preserved,
        public string $createdAt,
    ) {
        self::validateGroups($candidates, self::CANDIDATE_KINDS);
        /** @var array<string, list<array<string, mixed>>> $preserved */
        self::validateGroups($preserved, self::PRESERVED_KINDS);
        if (DateTimeImmutable::createFromFormat(DATE_ATOM, $createdAt) === false) {
            throw new InvalidArgumentException('The retirement inventory timestamp is invalid.');
        }
    }

    /** @return array{version: int, created_at: string, candidates: array<string, list<array<string, mixed>>>, preserved: array<string, list<array<string, mixed>>>} */
    public function toArray(): array
    {
        /** @var array<string, list<array<string, mixed>>> $preserved */
        $preserved = $this->preserved;

        return [
            'version' => 1,
            'created_at' => $this->createdAt,
            'candidates' => $this->candidates,
            'preserved' => $preserved,
        ];
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (
            array_keys($value) !== ['version', 'created_at', 'candidates', 'preserved']
            || ($value['version'] ?? null) !== 1
            || ! is_string($value['created_at'] ?? null)
            || ! is_array($value['candidates'] ?? null)
            || ! is_array($value['preserved'] ?? null)
        ) {
            throw new InvalidArgumentException('The retirement inventory is invalid.');
        }

        /** @var array<string, list<array<string, mixed>>> $candidates */
        $candidates = $value['candidates'];
        /** @var array<string, list<array<string, mixed>>> $preserved */
        $preserved = $value['preserved'];

        return new self($candidates, $preserved, $value['created_at']);
    }

    public function sha256(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $resource */
    public static function assertLegacyCandidate(string $kind, array $resource): void
    {
        $classification = $resource['classification'] ?? null;
        $identity = $resource['identity'] ?? $resource['name'] ?? $resource['path'] ?? null;
        if ($classification !== 'legacy' || ! is_string($identity)) {
            throw new InvalidArgumentException('Every quarantine target must be classified exactly as legacy.');
        }
        if (
            in_array($kind, ['pools', 'base_images', 'new_namespace', 'evidence'], true)
            || $identity === 'oe-standby'
            || preg_match('/\Aoe-[a-f0-9]{12}\z/i', $identity) === 1
            || str_starts_with($identity, 'orbit-e2e-standby')
            || preg_match(
                '/\Aorbit-e2e-[a-z][a-z0-9]{1,9}-[1-9][0-9]{0,8}-(?:gateway|app-dev|app-prod)(?:\/|\z)/i',
                $identity,
            ) === 1
            || ($resource['namespace'] ?? null) === 'rolling-v1'
        ) {
            throw new InvalidArgumentException('A protected resource cannot be a legacy retirement target.');
        }
        if ($kind === 'networks' && ($resource['dependencies'] ?? null) !== []) {
            throw new InvalidArgumentException('A retirement network must be exactly unused.');
        }
        if (
            in_array($kind, ['instances', 'snapshots', 'networks'], true)
            && (! is_string($resource['remote'] ?? null)
            || ! is_string($resource['project'] ?? null))
        ) {
            throw new InvalidArgumentException('A legacy Incus target requires exact remote and project identity.');
        }
    }

    /** @param array<string, list<array<string, mixed>>> $groups @param list<string> $allowed */
    private static function validateGroups(array $groups, array $allowed): void
    {
        $seen = [];
        $lastKind = -1;
        foreach ($groups as $kind => $resources) {
            $position = array_search($kind, $allowed, true);
            if (! is_int($position) || $position <= $lastKind || ! array_is_list($resources)) {
                throw new InvalidArgumentException('The retirement inventory kinds or order are invalid.');
            }
            $lastKind = $position;
            $lastIdentity = null;
            foreach ($resources as $resource) {
                if (! is_array($resource) || array_is_list($resource)) {
                    throw new InvalidArgumentException('Each retirement inventory resource must be an object.');
                }
                self::validateResource($kind, $resource);
                $identity = $resource['identity'] ?? $resource['name'] ?? $resource['path'] ?? null;
                if (
                    ! is_string($identity)
                    || $identity === ''
                    || isset($seen[$kind."\0".$identity])
                    || $lastIdentity !== null
                    && strcmp($lastIdentity, $identity) >= 0
                ) {
                    throw new InvalidArgumentException(
                        'The retirement inventory identities must be exact, unique, and ordered.',
                    );
                }
                $seen[$kind."\0".$identity] = true;
                $lastIdentity = $identity;
            }
        }
    }

    /** @param array<string, mixed> $resource */
    private static function validateResource(string $kind, array $resource): void
    {
        $allowed = match ($kind) {
            'instances' => [
                'name',
                'remote',
                'project',
                'status',
                'metadata',
                'dependencies',
                'classification',
                'owner',
                'namespace',
                'sha256',
            ],
            'snapshots', 'networks' => [
                'name',
                'remote',
                'project',
                'metadata',
                'dependencies',
                'classification',
                'namespace',
                'sha256',
            ],
            'source_paths', 'manifests', 'locks' => [
                'path',
                'safe_root',
                'filesystem_type',
                'classification',
                'namespace',
                'sha256',
            ],
            'base_images' => ['name', 'identity', 'fingerprint', 'classification', 'sha256'],
            'pools' => ['name', 'identity', 'classification', 'sha256'],
            'new_namespace' => ['name', 'identity', 'remote', 'project', 'classification', 'namespace', 'sha256'],
            'evidence' => ['path', 'identity', 'filesystem_type', 'classification', 'sha256'],
            default => throw new InvalidArgumentException('The retirement resource kind is invalid.'),
        };
        foreach (array_keys($resource) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('A retirement inventory resource contains an extra field.');
            }
        }
        $identityKey = in_array($kind, ['source_paths', 'manifests', 'locks', 'evidence'], true) ? 'path' : 'name';
        foreach ([$identityKey, 'classification', 'sha256'] as $key) {
            if (! is_string($resource[$key] ?? null) || $resource[$key] === '') {
                throw new InvalidArgumentException(
                    'A retirement inventory resource is missing a required string field.',
                );
            }
        }
        $expectedFilesystemType = match ($kind) {
            'source_paths' => 'directory',
            'manifests', 'locks', 'evidence' => 'file',
            default => null,
        };
        if (
            $expectedFilesystemType !== null
            && ($resource['filesystem_type'] ?? null) !== $expectedFilesystemType
        ) {
            throw new InvalidArgumentException('A retirement inventory filesystem type is invalid.');
        }
        if (
            ! in_array($resource['classification'], ['legacy', 'preserve'], true)
            || preg_match('/\A[a-f0-9]{64}\z/', $resource['sha256']) !== 1
        ) {
            throw new InvalidArgumentException('A retirement inventory classification or digest is invalid.');
        }
        foreach (['remote', 'project', 'owner', 'namespace', 'safe_root', 'identity', 'fingerprint'] as $key) {
            if (array_key_exists($key, $resource) && ! is_string($resource[$key])) {
                throw new InvalidArgumentException("The retirement inventory {$key} field must be a string.");
            }
        }
        foreach (['metadata', 'dependencies'] as $key) {
            if (array_key_exists($key, $resource) && ! is_array($resource[$key])) {
                throw new InvalidArgumentException("The retirement inventory {$key} field must be an array.");
            }
        }
        if (isset($resource['metadata'])) {
            /** @var array<array-key, mixed> $metadata */
            $metadata = $resource['metadata'];
            foreach ($metadata as $key => $value) {
                if (! is_string($key) || ! is_string($value)) {
                    throw new InvalidArgumentException('Retirement inventory metadata must be a string map.');
                }
            }
        }
        if (isset($resource['dependencies'])) {
            /** @var array<array-key, mixed> $dependencies */
            $dependencies = $resource['dependencies'];
            if (! array_is_list($dependencies)) {
                throw new InvalidArgumentException('Retirement inventory dependencies must be a list.');
            }
            foreach ($dependencies as $dependency) {
                if (! is_string($dependency) || $dependency === '') {
                    throw new InvalidArgumentException('Every retirement inventory dependency must be a string.');
                }
            }
        }
        if (
            $kind === 'instances'
            && (! in_array($resource['status'] ?? null, ['RUNNING', 'STOPPED'], true)
            || ! is_array($resource['metadata'] ?? null)
            || ! is_array($resource['dependencies'] ?? null))
        ) {
            throw new InvalidArgumentException('An inventory instance has invalid status, metadata, or dependencies.');
        }
        $digestInput = $resource;
        unset($digestInput['sha256']);
        if (! hash_equals($resource['sha256'], hash('sha256', json_encode(
            $digestInput,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        )))) {
            throw new InvalidArgumentException('A retirement inventory resource digest does not match.');
        }
    }
}
