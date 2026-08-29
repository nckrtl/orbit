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
 * @mago-expect lint:excessive-parameter-list Each lifecycle boundary is injected explicitly for deterministic recovery.
 * @mago-expect lint:kan-defect Exact fail-closed checks intentionally keep the lifecycle in one auditable service.
 * @mago-expect lint:too-many-methods Private validation methods keep the public lifecycle small.
 * @mago-expect analysis:mixed-argument PHPDoc array shapes are validated before each exact mutation.
 * @mago-expect analysis:less-specific-argument Serialized resource maps are validated at their use sites.
 * @mago-expect analysis:invalid-iterator Preserved resource lists come from validated immutable manifests.
 * @mago-expect analysis:invalid-destructuring-source Ordered candidates use the documented exact tuple shape.
 */
final readonly class LegacyRetirement
{
    /**
     * @param Closure(): array<string, list<array<string, mixed>>> $observe
     * @param Closure(string, array<string, mixed>): void $mutate
     * @param Closure(): DateTimeImmutable $clock
     * @param (Closure(array<string, list<array<string, mixed>>>): array<string, list<array<string, mixed>>>)|null $observeCurrent
     */
    public function __construct(
        private Closure $observe,
        private Closure $mutate,
        private Closure $clock,
        private OperationLock $lock,
        private OperationId $operation,
        private ?Closure $observeCurrent = null,
    ) {}

    public function inventory(): RetirementInventory
    {
        $observed = ($this->observe)();
        assert(is_array($observed));

        return $this->inventoryFrom($observed);
    }

    /** @param array<string, list<array<string, mixed>>> $observed */
    private function inventoryFrom(array $observed): RetirementInventory
    {
        $candidates = [];
        $preserved = [];

        foreach ($observed as $kind => $resources) {
            foreach ($resources as $resource) {
                $this->assertIdentity($kind, $resource);
                $resource = $this->withFilesystemType($kind, $resource);
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
        $resume = $this->resumeJournal($journalPath);
        if ($resume !== null && ($resume['operation'] ?? null) !== 'quarantine') {
            throw new RuntimeException('The quarantine recovery journal does not match the requested operation.');
        }

        if ($resume === null) {
            $this->assertInventoryUnchanged($inventory);
        }

        $ordered = $this->orderedCandidates($inventory->candidates);
        $targets = [];
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
        if ($resume === null) {
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
            $completed = [];
        } else {
            [$manifest, $completed] = $this->resumeQuarantineEntries(
                $resume,
                $inventory,
                $evidenceIdentity,
                $targets,
                $inventory->preserved,
            );
        }
        /** @var list<array{kind: string, identity: string}> $completed */
        /** @var list<array{kind: string, identity: string}> $completed */
        $completedMap = $this->entryMap($completed);

        foreach ($this->quarantineMutationTargets($targets) as $target) {
            if (isset($completedMap[$this->targetKey($target)])) {
                continue;
            }

            /** @var array{kind: string, identity: string} $pending */
            $pending = $this->journalIdentity($target);
            $this->record($journalPath, $this->quarantineJournalState($manifest, $completed, $pending, 'pending'));

            /** @var array<string, mixed> $resource */
            /** @var array<string, mixed> $target */
            $resource = $target['observed'];
            $this->assertQuarantineMutationReady($target, $manifest->preserved);
            ($this->mutate)('stop', $resource);
            $completed[] = $pending;
            $completedMap[$this->targetKey($target)] = true;
            $this->record($journalPath, $this->quarantineJournalState($manifest, $completed, null, 'pending'));
        }

        $this->record($journalPath, $this->quarantineJournalState($manifest, $completed, null, 'complete'));

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
        $resume = $this->resumeJournal($journalPath);
        if ($resume !== null && ($resume['operation'] ?? null) !== 'delete') {
            throw new RuntimeException('The deletion recovery journal does not match the requested operation.');
        }

        $orderedTargets = $this->orderedTargets($manifest->targets);
        $deleted = $resume === null ? [] : $this->resumeDeleteEntries($resume, $manifest);
        /** @var list<array{kind: string, identity: string}> $deleted */
        $deletedMap = $this->entryMap($deleted);

        foreach ($orderedTargets as $target) {
            /** @var array<string, mixed> $target */
            $kind = $target['kind'];
            $resource = $target['observed'];
            if (! is_string($kind) || ! is_array($resource)) {
                throw new RuntimeException('The quarantine target is invalid.');
            }
            if (isset($deletedMap[$this->targetKey($target)])) {
                continue;
            }

            /** @var array{kind: string, identity: string} $pending */
            $pending = $this->journalIdentity($target);
            $this->record($journalPath, $this->deleteJournalState($manifest, $deleted, $pending, 'pending'));
            $this->assertDeleteMutationReady($target, $manifest->preserved);
            ($this->mutate)('delete_'.$kind, $resource);
            $deleted[] = $pending;
            $deletedMap[$this->targetKey($target)] = true;
            $this->record($journalPath, $this->deleteJournalState($manifest, $deleted, null, 'pending'));
        }
        /** @var list<array<string, mixed>> $deletedResults */
        $targetsByKey = [];
        foreach ($manifest->targets as $target) {
            $targetsByKey[$this->targetKey($target)] = $target;
        }
        $deletedResults = array_map(function (array $entry) use ($targetsByKey): array {
            $result = [
                'kind' => $entry['kind'],
                'identity' => $entry['identity'],
            ];
            $target = $targetsByKey[$entry['kind']."\0".$entry['identity']] ?? null;
            $observed = is_array($target) ? $target['observed'] ?? null : null;
            if (is_array($observed) && is_string($observed['filesystem_type'] ?? null)) {
                $result['filesystem_type'] = $observed['filesystem_type'];
            }
            $result['result'] = 'deleted';

            return $result;
        }, $deleted);
        $result = new RetirementResult(
            true,
            $deletedResults,
            [],
            $manifest->preserved,
            $manifest->sha256(),
        );
        $this->record($journalPath, $this->deleteJournalState($manifest, $deleted, null, 'complete'));

        return $result;
    }

    /** @param array<string, list<array<string, mixed>>>|null $observed */
    public function verify(RetirementResult $retirement, ?array $observed = null): RetirementResult
    {
        if ($observed === null) {
            $observed = ($this->observe)();
            assert(is_array($observed));
        }
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
                if (
                    $actual === null
                    || $this->canonical($this->withDigest($this->withFilesystemType(
                        $kind,
                        $actual,
                    ))) !== $this->canonical($resource)
                ) {
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
        $current = $this->inventoryFrom($observed);
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

    /**
     * @param array<string, list<array<string, mixed>>>|null $requested
     * @return array<string, list<array<string, mixed>>>
     */
    private function liveObservation(?array $requested = null): array
    {
        if ($requested !== null && $this->observeCurrent !== null) {
            $observed = ($this->observeCurrent)($requested);
        } else {
            $observed = ($this->observe)();
        }
        assert(is_array($observed));

        /** @var array<string, list<array<string, mixed>>> $observed */
        return $observed;
    }

    /** @return array<string, mixed>|null */
    private function resumeJournal(?string $path): ?array
    {
        if ($path === null || ! is_file($path)) {
            return null;
        }

        return self::readProtectedJson($path);
    }

    /** @param array<string, mixed> $target @param array<string, list<array<string, mixed>>> $preserved */
    private function assertQuarantineMutationReady(array $target, array $preserved): void
    {
        $observed = $this->liveObservation($this->mutationResources($target, $preserved));
        $this->assertQuarantineTargetPreState($target, $observed);
        $this->assertPreservedUnchanged($preserved, $observed);
    }

    /** @param array<string, mixed> $target @param array<string, list<array<string, mixed>>> $preserved */
    private function assertDeleteMutationReady(array $target, array $preserved): void
    {
        $observed = $this->liveObservation($this->mutationResources($target, $preserved));
        $this->assertDeleteTargetPresent($target, $observed);
        $this->assertPreservedUnchanged($preserved, $observed);
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, list<array<string, mixed>>> $preserved
     * @return array<string, list<array<string, mixed>>>
     */
    private function mutationResources(array $target, array $preserved): array
    {
        $kind = $target['kind'] ?? null;
        $resource = $target['observed'] ?? null;
        if (! is_string($kind) || ! is_array($resource)) {
            throw new RuntimeException('The quarantine target is invalid.');
        }
        /** @var array<string, mixed> $resource */
        $requested = $preserved;
        $requested[$kind][] = $resource;

        return $requested;
    }

    /** @param array<string, mixed> $target @param array<string, list<array<string, mixed>>> $observed */
    private function assertQuarantineTargetPreState(array $target, array $observed): void
    {
        $kind = $target['kind'] ?? null;
        $resource = $target['observed'] ?? null;
        if (! is_string($kind) || ! is_array($resource)) {
            throw new RuntimeException('The quarantine target is invalid.');
        }

        if ($kind === 'instances' && ($target['original_status'] ?? null) === 'RUNNING') {
            if (! $this->resourceMatches($kind, $resource, $observed)) {
                throw new RuntimeException('The reviewed retirement inventory drifted.');
            }

            return;
        }

        $this->assertQuarantineTargetPostState($target, $observed);
    }

    /** @param array<string, mixed> $target @param array<string, list<array<string, mixed>>> $observed */
    private function assertQuarantineTargetPostState(array $target, array $observed): void
    {
        $kind = $target['kind'] ?? null;
        $resource = $target['observed'] ?? null;
        if (! is_string($kind) || ! is_array($resource)) {
            throw new RuntimeException('The quarantine target is invalid.');
        }

        $expected = $resource;
        if ($kind === 'instances' && ($target['original_status'] ?? null) === 'RUNNING') {
            $expected['status'] = 'STOPPED';
        }
        if (! $this->resourceMatches($kind, $expected, $observed)) {
            throw new RuntimeException('A quarantined resource drifted during recovery.');
        }
    }

    /** @param array<string, mixed> $target @param array<string, list<array<string, mixed>>> $observed */
    private function assertDeleteTargetMissing(array $target, array $observed): void
    {
        $kind = $target['kind'] ?? null;
        $resource = $target['observed'] ?? null;
        if (! is_string($kind) || ! is_array($resource)) {
            throw new RuntimeException('The quarantine target is invalid.');
        }
        if ($this->find($observed[$kind] ?? [], $this->identity($resource)) !== null) {
            throw new RuntimeException('A recorded deletion was not completed.');
        }
    }

    /** @param array<string, mixed> $target @param array<string, list<array<string, mixed>>> $observed */
    private function assertDeleteTargetPresent(array $target, array $observed): void
    {
        $kind = $target['kind'] ?? null;
        $resource = $target['observed'] ?? null;
        if (! is_string($kind) || ! is_array($resource)) {
            throw new RuntimeException('The quarantine target is invalid.');
        }
        RetirementInventory::assertLegacyCandidate($kind, $resource);
        if (in_array($kind, ['source_paths', 'manifests', 'locks'], true)) {
            $this->assertSafeFileTarget($kind, $resource);
        }
        $expected = $resource;
        if ($kind === 'instances' && ($expected['status'] ?? null) === 'RUNNING') {
            $expected['status'] = 'STOPPED';
        }
        if (! $this->resourceMatches($kind, $expected, $observed)) {
            throw new RuntimeException('A quarantined resource drifted before deletion.');
        }
    }

    /** @param array<string, mixed> $expected @param array<string, list<array<string, mixed>>> $observed */
    private function resourceMatches(string $kind, array $expected, array $observed): bool
    {
        $actual = $this->find($observed[$kind] ?? [], $this->identity($expected));
        if ($actual === null) {
            return false;
        }
        $actual = $this->withFilesystemType($kind, $actual);

        return $this->canonical($this->withDigest($actual)) === $this->canonical($this->withDigest($expected));
    }

    /**
     * @param array<string, mixed> $resume
     * @param list<array<string, mixed>> $targets
     * @param array<string, list<array<string, mixed>>> $preserved
     * @return array{QuarantineManifest, list<array{kind: string, identity: string}>}
     */
    private function resumeQuarantineEntries(
        array $resume,
        RetirementInventory $inventory,
        array $evidenceIdentity,
        array $targets,
        array $preserved,
    ): array {
        if (
            array_keys($resume) !== [
                'version',
                'operation',
                'phase',
                'inventory_sha256',
                'freeze_evidence',
                'manifest',
                'targets',
                'pending',
                'completed',
            ]
            || ($resume['version'] ?? null) !== 1
            || ! in_array($resume['phase'] ?? null, ['pending', 'complete'], true)
            || ($resume['inventory_sha256'] ?? null) !== $inventory->sha256()
            || ($resume['freeze_evidence'] ?? null) !== $evidenceIdentity
            || ! is_array($resume['manifest'] ?? null)
            || ! is_array($resume['targets'] ?? null)
            || $resume['targets'] !== $targets
        ) {
            throw new RuntimeException('The quarantine recovery journal is invalid.');
        }
        $journalManifest = QuarantineManifest::fromArray($resume['manifest']);
        if (
            $journalManifest->inventorySha256 !== $inventory->sha256()
            || $journalManifest->freezeEvidence !== $evidenceIdentity
            || $journalManifest->targets !== $targets
            || $journalManifest->preserved !== $preserved
        ) {
            throw new RuntimeException('The quarantine recovery journal does not match the requested operation.');
        }

        /** @var list<array<string, mixed>> $mutableTargets */
        $mutableTargets = $this->quarantineMutationTargets($journalManifest->targets);
        $allowed = $this->allowedEntryMap($mutableTargets);
        /** @var list<array{kind: string, identity: string}> $completed */
        $completed = $this->validatedJournalEntries($resume['completed'] ?? null, $allowed);
        $pending = $this->validatedPendingEntry($resume['pending'] ?? null, $allowed, $completed);
        /** @var array{kind: string, identity: string}|null $pending */
        $pending = $pending;
        $completedMap = $this->entryMap($completed);
        $observed = $this->liveObservation($this->requestedResources(
            $journalManifest->targets,
            $journalManifest->preserved,
        ));

        foreach ($journalManifest->targets as $target) {
            $key = $this->targetKey($target);
            if (isset($completedMap[$key])) {
                $this->assertQuarantineTargetPostState($target, $observed);
                continue;
            }
            if ($pending !== null && $key === $pending['kind']."\0".$pending['identity']) {
                if ($this->quarantineTargetHasPreState($target, $observed)) {
                    continue;
                }
                if ($this->quarantineTargetHasPostState($target, $observed)) {
                    $completed[] = $pending;
                    $completedMap[$key] = true;
                    continue;
                }
                throw new RuntimeException('A quarantined resource drifted during recovery.');
            }
            if (isset($allowed[$key])) {
                if (! $this->quarantineTargetHasPreState($target, $observed)) {
                    throw new RuntimeException('An unrecorded quarantined resource changed state.');
                }
                continue;
            }
            $this->assertQuarantineTargetPostState($target, $observed);
        }
        $this->assertPreservedUnchanged($journalManifest->preserved, $observed);

        if (($resume['phase'] ?? null) === 'complete' && count($completed) !== count($mutableTargets)) {
            throw new RuntimeException('The quarantine recovery journal is invalid.');
        }

        return [$journalManifest, $completed];
    }

    /** @param array<string, mixed> $resume @return list<array{kind: string, identity: string}> */
    private function resumeDeleteEntries(array $resume, QuarantineManifest $manifest): array
    {
        if (
            array_keys($resume) !== [
                'version',
                'operation',
                'phase',
                'quarantine_sha256',
                'freeze_evidence',
                'manifest',
                'targets',
                'pending',
                'completed',
            ]
            || ($resume['version'] ?? null) !== 1
            || ! in_array($resume['phase'] ?? null, ['pending', 'complete'], true)
            || ($resume['quarantine_sha256'] ?? null) !== $manifest->sha256()
            || ($resume['freeze_evidence'] ?? null) !== $manifest->freezeEvidence
            || ! is_array($resume['manifest'] ?? null)
            || ! is_array($resume['targets'] ?? null)
            || $resume['targets'] !== $manifest->targets
        ) {
            throw new RuntimeException('The deletion recovery journal is invalid.');
        }
        $journalManifest = QuarantineManifest::fromArray($resume['manifest']);
        if ($journalManifest->toArray() !== $manifest->toArray()) {
            throw new RuntimeException('The deletion recovery journal does not match the requested operation.');
        }

        /** @var list<array<string, mixed>> $manifestTargets */
        $manifestTargets = $manifest->targets;
        $allowed = $this->allowedEntryMap($manifestTargets);
        /** @var list<array{kind: string, identity: string}> $completed */
        $completed = $this->validatedJournalEntries($resume['completed'] ?? null, $allowed);
        $pending = $this->validatedPendingEntry($resume['pending'] ?? null, $allowed, $completed);
        /** @var array{kind: string, identity: string}|null $pending */
        $pending = $pending;
        $completedMap = $this->entryMap($completed);
        $observed = $this->liveObservation($this->requestedResources($manifest->targets, $manifest->preserved));

        foreach ($manifest->targets as $target) {
            $key = $this->targetKey($target);
            if (isset($completedMap[$key])) {
                $this->assertDeleteTargetMissing($target, $observed);
                continue;
            }
            if ($pending !== null && $key === $pending['kind']."\0".$pending['identity']) {
                if ($this->deleteTargetIsPresent($target, $observed)) {
                    continue;
                }
                if ($this->deleteTargetIsMissing($target, $observed)) {
                    $completed[] = $pending;
                    $completedMap[$key] = true;
                    continue;
                }
                throw new RuntimeException('A deletion target drifted during recovery.');
            }
            if (! $this->deleteTargetIsPresent($target, $observed)) {
                throw new RuntimeException('An unrecorded deletion target is missing.');
            }
        }
        $this->assertPreservedUnchanged($manifest->preserved, $observed);

        if (($resume['phase'] ?? null) === 'complete' && count($completed) !== count($manifest->targets)) {
            throw new RuntimeException('The deletion recovery journal is invalid.');
        }

        return $completed;
    }

    /** @param array<string, list<array<string, mixed>>> $preserved @param array<string, list<array<string, mixed>>> $observed */
    private function assertPreservedUnchanged(array $preserved, array $observed): void
    {
        foreach ($preserved as $kind => $resources) {
            foreach ($resources as $resource) {
                if (! $this->resourceMatches($kind, $resource, $observed)) {
                    throw new RuntimeException('A preserved resource drifted before deletion.');
                }
            }
        }
    }

    /** @param list<array<string, mixed>> $targets @param array<string, list<array<string, mixed>>> $preserved
     * @return array<string, list<array<string, mixed>>>
     */
    private function requestedResources(array $targets, array $preserved): array
    {
        /** @var array<string, list<array<string, mixed>>> $requested */
        $requested = $preserved;
        foreach ($targets as $target) {
            $kind = $target['kind'] ?? null;
            $resource = $target['observed'] ?? null;
            if (! is_string($kind) || ! is_array($resource) || array_is_list($resource)) {
                throw new RuntimeException('The recovery target is invalid.');
            }
            foreach (array_keys($resource) as $key) {
                if (! is_string($key)) {
                    throw new RuntimeException('The recovery target is invalid.');
                }
            }
            /** @var array<string, mixed> $resource */
            /** @var list<array<string, mixed>> $resources */
            $resources = $requested[$kind] ?? [];
            $resources[] = $resource;
            $requested[$kind] = $resources;
        }

        return $requested;
    }

    /** @param list<array<string, mixed>> $targets @return list<array<string, mixed>> */
    private function quarantineMutationTargets(array $targets): array
    {
        return array_values(array_filter(
            $targets,
            fn (array $target): bool => (
                ($target['kind'] ?? null) === 'instances'
                && ($target['original_status'] ?? null) === 'RUNNING'
            ),
        ));
    }

    /** @param array<string, mixed> $target @param array<string, list<array<string, mixed>>> $observed */
    private function quarantineTargetHasPreState(array $target, array $observed): bool
    {
        try {
            $this->assertQuarantineTargetPreState($target, $observed);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $target @param array<string, list<array<string, mixed>>> $observed */
    private function quarantineTargetHasPostState(array $target, array $observed): bool
    {
        try {
            $this->assertQuarantineTargetPostState($target, $observed);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $target @param array<string, list<array<string, mixed>>> $observed */
    private function deleteTargetIsPresent(array $target, array $observed): bool
    {
        try {
            $this->assertDeleteTargetPresent($target, $observed);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $target @param array<string, list<array<string, mixed>>> $observed */
    private function deleteTargetIsMissing(array $target, array $observed): bool
    {
        try {
            $this->assertDeleteTargetMissing($target, $observed);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $target @return array{kind: string, identity: string} */
    private function journalIdentity(array $target): array
    {
        $kind = $target['kind'] ?? null;
        $identity = $target['identity'] ?? null;
        if (! is_string($kind) || ! is_string($identity)) {
            throw new RuntimeException('The quarantine target is invalid.');
        }

        return ['kind' => $kind, 'identity' => $identity];
    }

    /** @param array<string, mixed> $target */
    private function targetKey(array $target): string
    {
        $kind = $target['kind'] ?? null;
        $identity = $target['identity'] ?? null;
        if (! is_string($kind) || ! is_string($identity)) {
            throw new RuntimeException('The quarantine target is invalid.');
        }

        return $kind."\0".$identity;
    }

    /** @param list<array<string, mixed>> $targets @return array<string, true> */
    private function allowedEntryMap(array $targets): array
    {
        $allowed = [];
        foreach ($targets as $target) {
            $allowed[$this->targetKey($target)] = true;
        }

        return $allowed;
    }

    /** @param list<array{kind: string, identity: string}> $entries @return array<string, true> */
    private function entryMap(array $entries): array
    {
        $map = [];
        foreach ($entries as $entry) {
            $map[$entry['kind']."\0".$entry['identity']] = true;
        }

        return $map;
    }

    /** @param mixed $value @param array<string, true> $allowed @return list<array{kind: string, identity: string}> */
    private function validatedJournalEntries(mixed $value, array $allowed): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException('The recovery journal is invalid.');
        }
        $entries = [];
        $seen = [];
        foreach ($value as $entry) {
            if (
                ! is_array($entry)
                || array_is_list($entry)
                || array_keys($entry) !== ['kind', 'identity']
                || ! is_string($entry['kind'] ?? null)
                || ! is_string($entry['identity'] ?? null)
            ) {
                throw new RuntimeException('The recovery journal is invalid.');
            }
            /** @var array{kind: string, identity: string} $entry */
            $key = $entry['kind']."\0".$entry['identity'];
            if (! isset($allowed[$key]) || isset($seen[$key])) {
                throw new RuntimeException('The recovery journal is invalid.');
            }
            $seen[$key] = true;
            /** @var array{kind: string, identity: string} $entry */
            $entries[] = $entry;
        }

        return $entries;
    }

    /** @param mixed $value @param array<string, true> $allowed @param list<array{kind: string, identity: string}> $completed @return array{kind: string, identity: string}|null */
    private function validatedPendingEntry(mixed $value, array $allowed, array $completed): ?array
    {
        if ($value === null) {
            return null;
        }
        if (
            ! is_array($value)
            || array_is_list($value)
            || array_keys($value) !== ['kind', 'identity']
            || ! is_string($value['kind'] ?? null)
            || ! is_string($value['identity'] ?? null)
        ) {
            throw new RuntimeException('The recovery journal is invalid.');
        }
        /** @var array{kind: string, identity: string} $value */
        $key = $value['kind']."\0".$value['identity'];
        /** @var list<array{kind: string, identity: string}> $completed */
        $completedMap = $this->entryMap($completed);
        if (! isset($allowed[$key]) || isset($completedMap[$key])) {
            throw new RuntimeException('The recovery journal is invalid.');
        }

        return $value;
    }

    /** @param list<array{kind: string, identity: string}> $completed @param array{kind: string, identity: string}|null $pending @return array<string, mixed> */
    private function quarantineJournalState(
        QuarantineManifest $manifest,
        array $completed,
        ?array $pending,
        string $phase,
    ): array {
        return [
            'version' => 1,
            'operation' => 'quarantine',
            'phase' => $phase,
            'inventory_sha256' => $manifest->inventorySha256,
            'freeze_evidence' => $manifest->freezeEvidence,
            'manifest' => $manifest->toArray(),
            'targets' => $manifest->targets,
            'pending' => $pending,
            'completed' => $completed,
        ];
    }

    /** @param list<array{kind: string, identity: string}> $completed @param array{kind: string, identity: string}|null $pending @return array<string, mixed> */
    private function deleteJournalState(
        QuarantineManifest $manifest,
        array $completed,
        ?array $pending,
        string $phase,
    ): array {
        return [
            'version' => 1,
            'operation' => 'delete',
            'phase' => $phase,
            'quarantine_sha256' => $manifest->sha256(),
            'freeze_evidence' => $manifest->freezeEvidence,
            'manifest' => $manifest->toArray(),
            'targets' => $manifest->targets,
            'pending' => $pending,
            'completed' => $completed,
        ];
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

    /** @return array{path: string, sha256: string, mode: int, filesystem_type: string} */
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

        return [
            'path' => $path,
            'sha256' => $sha256,
            'mode' => fileperms($path) & 0777,
            'filesystem_type' => 'file',
        ];
    }

    /** @param array{path: string, sha256: string, mode: int, filesystem_type: string} $evidence */
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
        $path = $resource['path'] ?? null;
        if (! is_string($path)) {
            throw new RuntimeException("The {$kind} resource has no exact filesystem path.");
        }
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
        ) {
            throw new RuntimeException("The {$kind} deletion target is outside its reviewed safe root.");
        }
        if (
            ! file_exists($root)
            && ! is_link($root)
            || ! file_exists($path)
            && ! is_link($path)
        ) {
            throw new RuntimeException("The {$kind} deletion target is outside its reviewed safe root.");
        }
        if ($this->exactFilesystemType($root) !== 'directory') {
            throw new RuntimeException("The {$kind} deletion safe root has an unexpected filesystem type.");
        }
        $this->assertExactFilesystemResource($kind, $resource);
    }

    /** @param array<string, mixed> $resource */
    private function withFilesystemType(string $kind, array $resource): array
    {
        $expected = $this->expectedFilesystemType($kind);
        if ($expected === null) {
            return $resource;
        }
        if (array_key_exists('filesystem_type', $resource) && $resource['filesystem_type'] !== $expected) {
            throw new RuntimeException("The {$kind} resource has an unexpected filesystem type.");
        }
        $resource['filesystem_type'] = $expected;
        $this->assertExactFilesystemResource($kind, $resource);

        return $resource;
    }

    /** @param array<string, mixed> $resource */
    private function assertExactFilesystemResource(string $kind, array $resource): void
    {
        $expected = $this->expectedFilesystemType($kind);
        if ($expected === null) {
            return;
        }
        $path = $resource['path'] ?? null;
        if (! is_string($path)) {
            throw new RuntimeException("The {$kind} resource has no exact filesystem path.");
        }
        if (($resource['filesystem_type'] ?? null) !== $expected) {
            throw new RuntimeException("The {$kind} resource has an unexpected filesystem type.");
        }
        if ($this->exactFilesystemType($path) !== $expected) {
            throw new RuntimeException("The {$kind} resource has an unexpected filesystem type.");
        }
    }

    private function expectedFilesystemType(string $kind): ?string
    {
        return match ($kind) {
            'source_paths' => 'directory',
            'manifests', 'locks', 'evidence' => 'file',
            default => null,
        };
    }

    private function exactFilesystemType(string $path): string
    {
        if (
            ! str_starts_with($path, '/')
            || preg_match('/[\x00\r\n\\\\]/', $path) === 1
            || str_contains($path, '//')
            || in_array('.', explode('/', $path), true)
            || in_array('..', explode('/', $path), true)
        ) {
            throw new RuntimeException('The retirement filesystem path is unsafe.');
        }
        $cursor = '';
        $type = null;
        foreach (array_filter(explode('/', $path), static fn (string $part): bool => $part !== '') as $component) {
            $cursor .= '/'.$component;
            if (! file_exists($cursor) && ! is_link($cursor)) {
                throw new RuntimeException('The retirement filesystem path is missing.');
            }
            $stat = lstat($cursor);
            if ($stat === false) {
                throw new RuntimeException('The retirement filesystem path is missing.');
            }
            $mode = $stat['mode'] & 0170000;
            if ($mode === 0120000) {
                throw new RuntimeException('The retirement filesystem path crosses a symbolic link.');
            }
            $type = match ($mode) {
                0040000 => 'directory',
                0100000 => 'file',
                default => 'other',
            };
        }
        if (! is_string($type)) {
            throw new RuntimeException('The retirement filesystem path is unsafe.');
        }

        return $type;
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
        if (! $this->lock->acquire($name, $this->operation, timeoutSeconds: 5.0)) {
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
