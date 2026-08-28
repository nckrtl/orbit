<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\OperationLock;
use App\E2E\Value\OperationId;
use App\E2E\Value\QuarantineManifest;
use App\E2E\Value\RetirementInventory;
use App\E2E\Value\RetirementResult;
use Closure;
use DateTimeImmutable;
use RuntimeException;

/**
 * @mago-expect lint:cyclomatic-complexity Retirement deliberately validates every exact target before mutation.
 * @mago-expect lint:kan-defect Exact fail-closed checks intentionally keep the lifecycle in one auditable service.
 * @mago-expect lint:too-many-methods Private validation methods keep the public lifecycle small.
 * @mago-expect analysis:mixed-argument PHPDoc array shapes are validated before each exact mutation.
 * @mago-expect analysis:less-specific-argument Serialized resource maps are validated at their use sites.
 * @mago-expect analysis:invalid-iterator Preserved resource lists come from validated immutable manifests.
 * @mago-expect analysis:invalid-destructuring-source Ordered candidates use the documented exact tuple shape.
 */
final readonly class LegacyRetirement
{
    /** @param Closure(): array<string, list<array<string, mixed>>> $observe @param Closure(string, array<string, mixed>): void $mutate @param Closure(): DateTimeImmutable $clock */
    public function __construct(
        private Closure $observe,
        private Closure $mutate,
        private Closure $clock,
        private ?OperationLock $lock = null,
    ) {}

    public function inventory(): RetirementInventory
    {
        $observed = ($this->observe)();
        assert(is_array($observed));
        $candidates = [];
        $preserved = [];

        foreach ($observed as $kind => $resources) {
            foreach ($resources as $resource) {
                $this->assertIdentity($kind, $resource);
                $legacy = $this->isLegacyCandidate($kind, $resource);
                $resource = $this->withDigest($resource);
                if ($legacy) {
                    $candidates[$kind][] = $resource;
                } else {
                    $preserved[$kind][] = $resource;
                }
            }
        }

        $candidates = $this->orderedGroups($candidates, RetirementInventory::CANDIDATE_KINDS);
        $preserved = $this->orderedGroups($preserved, RetirementInventory::PRESERVED_KINDS);
        $now = ($this->clock)();
        assert($now instanceof DateTimeImmutable);

        /** @var array<string, list<array<string, mixed>>> $candidates */
        /** @var array<string, list<array<string, mixed>>> $preserved */
        return new RetirementInventory($candidates, $preserved, $now->format(DATE_ATOM));
    }

    public function quarantine(
        RetirementInventory $inventory,
        string $acknowledgedSha256,
        string $freezeEvidence,
        ?string $journalPath = null,
    ): QuarantineManifest {
        $result = $this->withLock('legacy-retirement', fn (): QuarantineManifest => $this->quarantineUnlocked(
            $inventory,
            $acknowledgedSha256,
            $freezeEvidence,
            $journalPath,
        ));
        assert($result instanceof QuarantineManifest);

        return $result;
    }

    private function quarantineUnlocked(
        RetirementInventory $inventory,
        string $acknowledgedSha256,
        string $freezeEvidence,
        ?string $journalPath,
    ): QuarantineManifest {
        if (! hash_equals($inventory->sha256(), $acknowledgedSha256)) {
            throw new RuntimeException('The acknowledged inventory SHA-256 does not match.');
        }
        $evidenceIdentity = $this->freezeEvidenceIdentity($freezeEvidence);
        $this->assertInventoryUnchanged($inventory);

        $targets = [];
        $ordered = $this->orderedCandidates($inventory->candidates);
        foreach ($ordered as $entry) {
            [$kind, $resource] = $entry;
            $status = $resource['status'] ?? null;
            if ($kind === 'instances' && $status === 'RUNNING') {
                if (! is_string($resource['owner'] ?? null) || $resource['owner'] === '') {
                    throw new RuntimeException('Running resource ownership must be resolved before quarantine.');
                }
            }
            $targets[] = [
                'kind' => $kind,
                'identity' => $this->identity($resource),
                'original_status' => $status,
                'metadata' => $resource['metadata'] ?? [],
                'dependencies' => $resource['dependencies'] ?? [],
                'recovery' => $this->recoveryCommands($kind, $resource),
                'observed' => $resource,
                'observed_sha256' => hash('sha256', $this->canonical($resource)),
                'result' => $kind === 'instances' && $status === 'RUNNING' ? 'stopped' : 'unchanged',
            ];
        }

        $now = ($this->clock)();
        assert($now instanceof DateTimeImmutable);
        $manifest = new QuarantineManifest(
            $inventory->sha256(),
            $evidenceIdentity,
            $targets,
            $inventory->preserved,
            $now->format(DATE_ATOM),
            $now->modify('+7 days')->format(DATE_ATOM),
        );
        $this->record($journalPath, [
            'phase' => 'prepared',
            'operation' => 'quarantine',
            'manifest' => $manifest->toArray(),
            'completed' => [],
        ]);
        $completed = [];
        foreach ($targets as $target) {
            if (($target['kind'] ?? null) !== 'instances' || ($target['original_status'] ?? null) !== 'RUNNING') {
                continue;
            }
            /** @var array<string, mixed> $resource */
            $resource = $target['observed'];
            ($this->mutate)('stop', $resource);
            $completed[] = $target['identity'];
            $this->record($journalPath, [
                'phase' => 'applying',
                'operation' => 'quarantine',
                'manifest' => $manifest->toArray(),
                'completed' => $completed,
            ]);
        }
        $this->record($journalPath, [
            'phase' => 'complete',
            'operation' => 'quarantine',
            'manifest' => $manifest->toArray(),
            'completed' => $completed,
        ]);

        return $manifest;
    }

    public function delete(
        QuarantineManifest $manifest,
        string $acknowledgedSha256,
        ?string $journalPath = null,
    ): RetirementResult {
        $result = $this->withLock('legacy-retirement', fn (): RetirementResult => $this->deleteUnlocked(
            $manifest,
            $acknowledgedSha256,
            $journalPath,
        ));
        assert($result instanceof RetirementResult);

        return $result;
    }

    private function deleteUnlocked(
        QuarantineManifest $manifest,
        string $acknowledgedSha256,
        ?string $journalPath,
    ): RetirementResult {
        if (! hash_equals($manifest->sha256(), $acknowledgedSha256)) {
            throw new RuntimeException('The acknowledged quarantine SHA-256 does not match.');
        }
        $now = ($this->clock)();
        assert($now instanceof DateTimeImmutable);
        if ($now < new DateTimeImmutable($manifest->deleteAfter)) {
            throw new RuntimeException('The quarantine retention period has not elapsed.');
        }
        $this->assertFreezeEvidenceIdentity($manifest->freezeEvidence);
        $this->assertQuarantineUnchanged($manifest);

        $deleted = [];
        $orderedTargets = $this->orderedTargets($manifest->targets);
        foreach ($orderedTargets as $target) {
            $kind = $target['kind'] ?? null;
            $resource = $target['observed'] ?? null;
            if (! is_string($kind) || ! is_array($resource)) {
                throw new RuntimeException('The quarantine target is invalid.');
            }
            RetirementInventory::assertLegacyCandidate($kind, $resource);
            if (in_array($kind, ['source_paths', 'manifests', 'locks'], true)) {
                $this->assertSafeFileTarget($kind, $resource);
            }
            if (in_array($kind, ['snapshots', 'instances', 'networks'], true)) {
                $this->assertCurrentResource($kind, $resource);
            }
        }
        $this->record($journalPath, [
            'phase' => 'prepared',
            'operation' => 'delete',
            'quarantine_sha256' => $manifest->sha256(),
            'targets' => $manifest->targets,
            'deleted' => [],
        ]);
        foreach ($orderedTargets as $target) {
            /** @var array<string, mixed> $target */
            $kind = $target['kind'];
            $resource = $target['observed'];
            if (! is_string($kind) || ! is_array($resource)) {
                throw new RuntimeException('The quarantine target is invalid.');
            }
            if (in_array($kind, ['source_paths', 'manifests', 'locks'], true)) {
                $this->assertSafeFileTarget($kind, $resource);
            }
            if (in_array($kind, ['snapshots', 'instances', 'networks'], true)) {
                $this->assertCurrentResource($kind, $resource);
            }
            ($this->mutate)('delete_'.$kind, $resource);
            $deleted[] = ['kind' => $kind, 'identity' => $this->identity($resource), 'result' => 'deleted'];
            $this->record($journalPath, [
                'phase' => 'applying',
                'operation' => 'delete',
                'quarantine_sha256' => $manifest->sha256(),
                'targets' => $manifest->targets,
                'deleted' => $deleted,
            ]);
        }
        $result = new RetirementResult(true, $deleted, [], $manifest->preserved, $manifest->sha256());
        $this->record($journalPath, [
            'phase' => 'complete',
            'operation' => 'delete',
            'quarantine_sha256' => $manifest->sha256(),
            'targets' => $manifest->targets,
            'deleted' => $deleted,
            'result' => $result->toArray(),
        ]);

        return $result;
    }

    public function verify(RetirementResult $retirement): RetirementResult
    {
        $observed = ($this->observe)();
        assert(is_array($observed));
        $remaining = [];
        foreach ($retirement->deleted as $deleted) {
            $kind = $deleted['kind'] ?? null;
            $identity = $deleted['identity'] ?? null;
            if (is_string($kind) && is_string($identity) && $this->find($observed[$kind] ?? [], $identity) !== null) {
                $remaining[] = [
                    'kind' => $kind,
                    'identity' => $identity,
                    'result' => 'remaining',
                    'reason' => 'not_deleted',
                ];
            }
        }
        foreach ($retirement->preserved as $kind => $resources) {
            foreach ($resources as $resource) {
                $actual = $this->find($observed[$kind] ?? [], $this->identity($resource));
                if ($actual === null || $this->canonical($this->withDigest($actual)) !== $this->canonical($resource)) {
                    $remaining[] = [
                        'kind' => $kind,
                        'identity' => $this->identity($resource),
                        'result' => 'remaining',
                        'reason' => 'preserved_identity_changed',
                    ];
                }
            }
        }
        $reviewed = [];
        foreach ($retirement->deleted as $deleted) {
            $kind = $deleted['kind'] ?? null;
            $identity = $deleted['identity'] ?? null;
            if (is_string($kind) && is_string($identity)) {
                $reviewed[$kind."\0".$identity] = true;
            }
        }
        $current = $this->inventory();
        foreach ($current->candidates as $kind => $resources) {
            foreach ($resources as $resource) {
                $key = $kind."\0".$this->identity($resource);
                if (! isset($reviewed[$key])) {
                    $remaining[] = [
                        'kind' => $kind,
                        'identity' => $this->identity($resource),
                        'result' => 'remaining',
                        'reason' => 'unexpected_legacy_identity',
                    ];
                }
            }
        }

        return new RetirementResult(
            $remaining === [],
            $retirement->deleted,
            $remaining,
            $retirement->preserved,
            $retirement->quarantineSha256,
        );
    }

    /** @param array<string, mixed> $value */
    public function write(string $path, array $value): void
    {
        if (! str_starts_with($path, '/') || is_link($path)) {
            throw new RuntimeException('The manifest output path is unsafe.');
        }
        $directory = dirname($path);
        if (! is_dir($directory) || is_link($directory)) {
            throw new RuntimeException('The manifest output directory is unsafe.');
        }
        $cursor = '';
        foreach (array_filter(explode('/', $directory), static fn (string $part): bool => $part !== '') as $component) {
            $cursor .= '/'.$component;
            if (lstat($cursor) === false || is_link($cursor)) {
                throw new RuntimeException('The manifest output directory crosses a symbolic link.');
            }
        }
        $temporary = tempnam($directory, '.legacy-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create an atomic retirement manifest.');
        }
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
            if (
                file_put_contents($temporary, $json, LOCK_EX) === false
                || ! chmod($temporary, 0600)
                || ! rename($temporary, $path)
            ) {
                throw new RuntimeException('Unable to atomically write the retirement manifest.');
            }
            if (! chmod($path, 0600)) {
                throw new RuntimeException('The retirement manifest permissions could not be protected.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @return array<string, mixed> */
    public function read(string $path): array
    {
        return self::readProtectedJson($path);
    }

    /** @return array<string, mixed> */
    public static function readProtectedJson(string $path): array
    {
        if (
            ! str_starts_with($path, '/')
            || preg_match('/[\x00\r\n\\\\]/', $path) === 1
            || str_contains($path, '//')
            || in_array('.', explode('/', $path), true)
            || in_array('..', explode('/', $path), true)
        ) {
            throw new RuntimeException('The protected JSON path is unsafe.');
        }
        $cursor = '';
        foreach (array_filter(explode('/', $path), static fn (string $part): bool => $part !== '') as $component) {
            $cursor .= '/'.$component;
            if (! file_exists($cursor) && ! is_link($cursor) || is_link($cursor)) {
                throw new RuntimeException('The protected JSON path has a missing or symbolic-link component.');
            }
        }
        if (! is_file($path) || (fileperms($path) & 0777) !== 0600) {
            throw new RuntimeException('The protected JSON file must be a regular 0600 file.');
        }
        $contents = file_get_contents($path);
        $value = json_decode($contents === false ? '' : $contents, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException('The retirement manifest is invalid.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    private function assertInventoryUnchanged(RetirementInventory $inventory): void
    {
        $fresh = $this->inventory();
        if (
            $this->canonical([$fresh->candidates, $fresh->preserved]) !== $this->canonical([
                $inventory->candidates,
                $inventory->preserved,
            ])
        ) {
            throw new RuntimeException('The reviewed retirement inventory drifted.');
        }
    }

    private function assertQuarantineUnchanged(QuarantineManifest $manifest): void
    {
        $observed = ($this->observe)();
        assert(is_array($observed));
        foreach ($manifest->targets as $target) {
            $kind = $target['kind'] ?? null;
            $resource = $target['observed'] ?? null;
            if (! is_string($kind) || ! is_array($resource)) {
                throw new RuntimeException('The quarantine target is invalid.');
            }
            $actual = $this->find($observed[$kind] ?? [], $this->identity($resource));
            if ($actual === null) {
                throw new RuntimeException('A quarantined resource is missing before deletion.');
            }
            $expected = $resource;
            if ($kind === 'instances' && ($expected['status'] ?? null) === 'RUNNING') {
                $expected['status'] = 'STOPPED';
            }
            $actual = $this->withDigest($actual);
            $expected = $this->withDigest($expected);
            if ($this->canonical($actual) !== $this->canonical($expected)) {
                throw new RuntimeException('A quarantined resource drifted before deletion.');
            }
        }
        foreach ($manifest->preserved as $kind => $resources) {
            foreach ($resources as $resource) {
                $actual = $this->find($observed[$kind] ?? [], $this->identity($resource));
                if ($actual === null || $this->canonical($this->withDigest($actual)) !== $this->canonical($resource)) {
                    throw new RuntimeException('A preserved resource drifted before deletion.');
                }
            }
        }
    }

    /** @param array<string, mixed> $expected */
    private function assertCurrentResource(string $kind, array $expected): void
    {
        if ($kind === 'instances' && ($expected['status'] ?? null) === 'RUNNING') {
            $expected['status'] = 'STOPPED';
        }
        $observed = ($this->observe)();
        $actual = $this->find($observed[$kind] ?? [], $this->identity($expected));
        if (
            $actual === null
            || $this->canonical($this->withDigest($actual)) !== $this->canonical($this->withDigest($expected))
        ) {
            throw new RuntimeException('The exact remote resource identity changed before mutation.');
        }
    }

    /** @param array<string, mixed> $resource */
    private function isLegacyCandidate(string $kind, array $resource): bool
    {
        $classification = $resource['classification'] ?? null;
        if (! in_array($classification, ['legacy', 'preserve'], true)) {
            throw new RuntimeException('Every observed resource requires an exact reviewed classification.');
        }
        if ($classification === 'preserve') {
            return false;
        }
        RetirementInventory::assertLegacyCandidate($kind, $resource);

        return true;
    }

    /** @param array<string, list<array<string, mixed>>> $groups @return list<array{string, array<string, mixed>}> */
    private function orderedCandidates(array $groups): array
    {
        $result = [];
        foreach (['snapshots', 'instances', 'networks', 'source_paths', 'manifests', 'locks'] as $kind) {
            foreach ($groups[$kind] ?? [] as $resource) {
                $result[] = [$kind, $resource];
            }
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $targets @return list<array<string, mixed>> */
    private function orderedTargets(array $targets): array
    {
        $order = array_flip(['snapshots', 'instances', 'networks', 'source_paths', 'manifests', 'locks']);
        usort(
            $targets,
            fn (array $left, array $right): int => (
                ($order[$left['kind'] ?? ''] ?? 99) <=> ($order[$right['kind'] ?? ''] ?? 99)
            ),
        );

        return $targets;
    }

    /** @return array{path: string, sha256: string, mode: int} */
    private function freezeEvidenceIdentity(string $path): array
    {
        if (
            ! str_starts_with($path, '/')
            || preg_match('/[\x00\r\n\\\\]/', $path) === 1
            || str_contains($path, '//')
            || in_array('.', explode('/', $path), true)
            || in_array('..', explode('/', $path), true)
            || ! is_file($path)
            || is_link($path)
            || (fileperms($path) & 0777) !== 0600
            || filesize($path) === 0
        ) {
            throw new RuntimeException('External freeze evidence must be a non-empty regular 0600 file.');
        }
        $cursor = '';
        foreach (array_filter(explode('/', $path), static fn (string $part): bool => $part !== '') as $component) {
            $cursor .= '/'.$component;
            if (lstat($cursor) === false || is_link($cursor)) {
                throw new RuntimeException('External freeze evidence cannot cross a symbolic link.');
            }
        }
        $sha256 = hash_file('sha256', $path);
        if ($sha256 === false) {
            throw new RuntimeException('Unable to hash external freeze evidence.');
        }

        return ['path' => $path, 'sha256' => $sha256, 'mode' => fileperms($path) & 0777];
    }

    /** @param array{path: string, sha256: string, mode: int} $evidence */
    private function assertFreezeEvidenceIdentity(array $evidence): void
    {
        $actual = $this->freezeEvidenceIdentity($evidence['path']);
        if ($actual !== $evidence) {
            throw new RuntimeException('The external freeze evidence changed after quarantine.');
        }
    }

    /** @param array<string, mixed> $state */
    private function record(?string $path, array $state): void
    {
        if ($path !== null) {
            $this->write($path, $state);
        }
    }

    /** @param array<string, mixed> $resource */
    private function assertSafeFileTarget(string $kind, array $resource): void
    {
        $path = $this->identity($resource);
        $root = $resource['safe_root'] ?? null;
        if (
            ! is_string($root)
            || ! str_starts_with($root, '/')
            || preg_match('/[\x00\r\n\\\\]/', $path.$root) === 1
            || str_contains($path, '//')
            || str_contains($root, '//')
            || $path === $root
            || ! str_starts_with($path, rtrim($root, '/').'/')
            || in_array('.', explode('/', $path), true)
            || in_array('..', explode('/', $path), true)
            || ! file_exists($root)
            && ! is_link($root)
            || ! file_exists($path)
            && ! is_link($path)
            || is_link($root)
            || is_link($path)
        ) {
            throw new RuntimeException("The {$kind} deletion target is outside its reviewed safe root.");
        }
        $cursor = rtrim($root, '/');
        foreach (explode('/', ltrim(substr($path, strlen($cursor)), '/')) as $component) {
            $cursor .= '/'.$component;
            if (! file_exists($cursor) && ! is_link($cursor) || is_link($cursor)) {
                throw new RuntimeException("The {$kind} deletion target crosses a symbolic link.");
            }
        }
    }

    /** @param array<string, mixed> $resource */
    private function assertIdentity(string $kind, array $resource): void
    {
        $identity = $this->identity($resource);
        $valid = match ($kind) {
            'snapshots' => preg_match(
                '/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\/[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/D',
                $identity,
            ) === 1,
            'instances', 'networks' => preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/D', $identity) === 1,
            default => $identity !== '' && ! str_contains($identity, '*') && ! str_contains($identity, '?'),
        };
        if (! $valid || str_contains($identity, ':')) {
            throw new RuntimeException("The {$kind} resource has an unsafe identity.");
        }
    }

    /** @param array<string, mixed> $resource */
    private function identity(array $resource): string
    {
        $identity = $resource['identity'] ?? $resource['name'] ?? $resource['path'] ?? null;
        if (! is_string($identity)) {
            throw new RuntimeException('A retirement resource has no exact identity.');
        }

        return $identity;
    }

    /** @param list<array<string, mixed>> $resources */
    private function sortResources(array &$resources): void
    {
        usort($resources, fn (array $left, array $right): int => $this->identity($left) <=> $this->identity($right));
    }

    /** @param array<string, list<array<string, mixed>>> $groups @param list<string> $order @return array<string, list<array<string, mixed>>> */
    private function orderedGroups(array $groups, array $order): array
    {
        $ordered = [];
        foreach ($order as $kind) {
            if (! isset($groups[$kind])) {
                continue;
            }
            $resources = $groups[$kind];
            $this->sortResources($resources);
            $ordered[$kind] = $resources;
        }
        if (count($ordered) !== count($groups)) {
            throw new RuntimeException('The observation contains an unsupported retirement resource kind.');
        }

        return $ordered;
    }

    /** @param list<array<string, mixed>> $resources @return array<string, mixed>|null */
    private function find(array $resources, string $identity): ?array
    {
        foreach ($resources as $resource) {
            if ($this->identity($resource) === $identity) {
                return $resource;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $resource @return list<string> */
    private function recoveryCommands(string $kind, array $resource): array
    {
        return (
            $kind === 'instances' && ($resource['status'] ?? null) === 'RUNNING'
                ? [sprintf(
                    'incus --project %s start %s:%s',
                    escapeshellarg((string) ($resource['project'] ?? '')),
                    escapeshellarg((string) ($resource['remote'] ?? '')),
                    escapeshellarg($this->identity($resource)),
                )]
                : []
        );
    }

    private function canonical(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function withLock(string $name, Closure $operation): mixed
    {
        if ($this->lock === null) {
            return $operation();
        }
        $id = new OperationId(bin2hex(random_bytes(16)));
        if (! $this->lock->acquire($name, $id, timeoutSeconds: 5.0)) {
            throw new RuntimeException('Another legacy retirement operation is in progress.');
        }
        try {
            return $operation();
        } finally {
            $this->lock->release();
        }
    }

    /** @param array<string, mixed> $resource @return array<string, mixed> */
    private function withDigest(array $resource): array
    {
        unset($resource['sha256']);
        $resource['sha256'] = hash('sha256', $this->canonical($resource));

        return $resource;
    }
}
