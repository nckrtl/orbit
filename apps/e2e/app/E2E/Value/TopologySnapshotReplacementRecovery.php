<?php

declare(strict_types=1);

namespace App\E2E\Value;

use App\E2E\State\SecretRedactor;
use InvalidArgumentException;

/** Retry-safe progress for one exact clean snapshot replacement installation. */
final readonly class TopologySnapshotReplacementRecovery
{
    public const int SCHEMA = 1;

    public const array PHASES = [
        'authorized',
        'construction_pending',
        'construction_verified',
        'staging_pending',
        'staging_verified',
        'swap_pending',
        'swap_in_progress',
        'manifest_pending',
        'manifest_promoted',
        'old_cleanup_pending',
        'old_cleanup_verified',
        'temporary_cleanup_pending',
        'temporary_cleanup_verified',
        'preparation_failed',
        'rollback_pending',
        'rolled_back',
        'cleanup_pending',
        'recovery_required',
        'abandoned',
        'complete',
    ];

    private const array TERMINAL_PHASES = ['abandoned', 'complete'];

    private const array FAILURE_PHASES = [
        'preparation_failed',
        'rollback_pending',
        'rolled_back',
        'cleanup_pending',
        'recovery_required',
    ];

    private const array TRANSITIONS = [
        'authorized' => ['construction_pending', 'abandoned'],
        'construction_pending' => ['construction_verified', 'preparation_failed', 'recovery_required'],
        'construction_verified' => ['staging_pending', 'preparation_failed', 'recovery_required'],
        'staging_pending' => ['staging_verified', 'preparation_failed', 'recovery_required'],
        'staging_verified' => ['swap_pending', 'preparation_failed', 'recovery_required'],
        'swap_pending' => ['swap_in_progress', 'rollback_pending', 'recovery_required'],
        'swap_in_progress' => ['manifest_pending', 'rollback_pending', 'recovery_required'],
        'manifest_pending' => ['manifest_promoted', 'rollback_pending', 'recovery_required'],
        'manifest_promoted' => ['old_cleanup_pending', 'cleanup_pending', 'recovery_required'],
        'old_cleanup_pending' => ['old_cleanup_verified', 'cleanup_pending', 'recovery_required'],
        'old_cleanup_verified' => ['temporary_cleanup_pending', 'cleanup_pending', 'recovery_required'],
        'temporary_cleanup_pending' => ['temporary_cleanup_verified', 'cleanup_pending', 'recovery_required'],
        'temporary_cleanup_verified' => ['complete', 'recovery_required'],
        'preparation_failed' => ['cleanup_pending', 'abandoned', 'recovery_required'],
        'rollback_pending' => ['rolled_back', 'recovery_required'],
        'rolled_back' => ['cleanup_pending', 'abandoned', 'recovery_required'],
        'cleanup_pending' => ['temporary_cleanup_verified', 'complete', 'abandoned', 'recovery_required'],
        'recovery_required' => ['rollback_pending', 'cleanup_pending'],
        'abandoned' => [],
        'complete' => [],
    ];

    public ?string $error;

    public string $nextAction;

    /**
     * @param  list<array{phase:string,recorded_at:string}>  $history
     * @param  list<string>  $oldRenamedRoles
     * @param  list<string>  $newRenamedRoles
     * @param  list<string>  $deletedOldRoles
     */
    public function __construct(
        public TopologySnapshotReplacementInstallation $installation,
        public string $phase,
        public array $history,
        public array $oldRenamedRoles,
        public array $newRenamedRoles,
        public array $deletedOldRoles,
        public bool $generationRecorded,
        public bool $manifestPromoted,
        public bool $temporaryResourcesCleaned,
        public bool $stagedResourcesCleaned,
        ?string $error,
        string $nextAction,
    ) {
        if (! in_array($phase, self::PHASES, true)) {
            throw new InvalidArgumentException('The topology snapshot replacement recovery phase is invalid.');
        }
        if (self::historyValue($history) !== $history) {
            throw new InvalidArgumentException('The topology snapshot replacement phase history is invalid.');
        }
        foreach ([$oldRenamedRoles, $newRenamedRoles, $deletedOldRoles] as $roles) {
            if (self::roleListValue($roles) !== $roles) {
                throw new InvalidArgumentException('The topology snapshot replacement role progress is invalid.');
            }
        }
        $this->assertHistory($history, $phase);
        $this->assertRoleProgress($oldRenamedRoles, 'old rename');
        $this->assertRoleProgress($newRenamedRoles, 'new rename');
        $this->assertRoleProgress($deletedOldRoles, 'old cleanup');
        if (
            array_diff($newRenamedRoles, $oldRenamedRoles) !== []
            || array_diff($deletedOldRoles, $newRenamedRoles) !== []
        ) {
            throw new InvalidArgumentException('The topology snapshot replacement rename progress is inconsistent.');
        }
        if (
            $generationRecorded && $newRenamedRoles !== TopologyProfile::ROLES
            || $manifestPromoted && ! $generationRecorded
        ) {
            throw new InvalidArgumentException('The topology snapshot replacement manifest progress is inconsistent.');
        }
        if (
            $phase === 'complete'
            && (
                $oldRenamedRoles !== TopologyProfile::ROLES
                || $newRenamedRoles !== TopologyProfile::ROLES
                || $deletedOldRoles !== TopologyProfile::ROLES
                || ! $generationRecorded
                || ! $manifestPromoted
                || ! $temporaryResourcesCleaned
                || ! $stagedResourcesCleaned
            )
        ) {
            throw new InvalidArgumentException('A completed topology snapshot replacement has incomplete progress.');
        }
        if (
            $phase === 'abandoned'
            && ($manifestPromoted || ! $temporaryResourcesCleaned || ! $stagedResourcesCleaned)
        ) {
            throw new InvalidArgumentException('An abandoned topology snapshot replacement has incomplete cleanup.');
        }

        $redactor = new SecretRedactor;
        $this->error = $error === null ? null : $redactor->redact($error);
        $this->nextAction = $redactor->redact($nextAction);
        if ($this->nextAction === '' || str_contains($this->nextAction, "\0")) {
            throw new InvalidArgumentException('The topology snapshot replacement next action is invalid.');
        }
        if (
            in_array($phase, self::FAILURE_PHASES, true) !== ($this->error !== null)
            || $this->error === ''
        ) {
            throw new InvalidArgumentException('The topology snapshot replacement recovery error is invalid.');
        }
    }

    public static function authorized(
        TopologySnapshotReplacementInstallation $installation,
        string $recordedAt,
        ?string $nextAction = null,
    ): self {
        return new self(
            $installation,
            'authorized',
            [['phase' => 'authorized', 'recorded_at' => $recordedAt]],
            [],
            [],
            [],
            false,
            false,
            false,
            false,
            null,
            $nextAction ?? 'bin/e2e-topology closeout '.$installation->issue,
        );
    }

    public function withPhase(
        string $phase,
        string $recordedAt,
        ?string $error = null,
        ?string $nextAction = null,
    ): self {
        if ($phase === $this->phase) {
            throw new InvalidArgumentException('A topology snapshot replacement phase must advance.');
        }
        $history = $this->history;
        $history[] = ['phase' => $phase, 'recorded_at' => $recordedAt];

        return $this->copy(
            phase: $phase,
            history: $history,
            error: $error,
            nextAction: $nextAction ?? $this->nextAction,
        );
    }

    public function withOldRename(string $role): self
    {
        return $this->copy(
            oldRenamedRoles: $this->appendRole($this->oldRenamedRoles, $role),
            error: $this->error,
        );
    }

    public function withNewRename(string $role): self
    {
        return $this->copy(
            newRenamedRoles: $this->appendRole($this->newRenamedRoles, $role),
            error: $this->error,
        );
    }

    public function withOldDeleted(string $role): self
    {
        return $this->copy(
            deletedOldRoles: $this->appendRole($this->deletedOldRoles, $role),
            error: $this->error,
        );
    }

    public function withGenerationRecorded(): self
    {
        return $this->copy(generationRecorded: true, error: $this->error);
    }

    public function withManifestPromoted(): self
    {
        return $this->copy(manifestPromoted: true, error: $this->error);
    }

    public function withTemporaryResourcesCleaned(): self
    {
        return $this->copy(temporaryResourcesCleaned: true, error: $this->error);
    }

    public function withStagedResourcesCleaned(): self
    {
        return $this->copy(stagedResourcesCleaned: true, error: $this->error);
    }

    public function terminal(): bool
    {
        return in_array($this->phase, self::TERMINAL_PHASES, true);
    }

    public function canReplace(self $existing): bool
    {
        if (
            ! $this->installation->sameIdentity($existing->installation)
            || ! self::isPrefix($existing->history, $this->history)
            || ! self::isPrefix($existing->oldRenamedRoles, $this->oldRenamedRoles)
            || ! self::isPrefix($existing->newRenamedRoles, $this->newRenamedRoles)
            || ! self::isPrefix($existing->deletedOldRoles, $this->deletedOldRoles)
            || $existing->generationRecorded && ! $this->generationRecorded
            || $existing->manifestPromoted && ! $this->manifestPromoted
            || $existing->temporaryResourcesCleaned && ! $this->temporaryResourcesCleaned
            || $existing->stagedResourcesCleaned && ! $this->stagedResourcesCleaned
        ) {
            return false;
        }

        if ($existing->terminal()) {
            return $this->toArray() === $existing->toArray();
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'installation' => $this->installation->toArray(),
            'installation_sha256' => $this->installation->fingerprint(),
            'phase' => $this->phase,
            'history' => $this->history,
            'old_renamed_roles' => $this->oldRenamedRoles,
            'new_renamed_roles' => $this->newRenamedRoles,
            'deleted_old_roles' => $this->deletedOldRoles,
            'generation_recorded' => $this->generationRecorded,
            'manifest_promoted' => $this->manifestPromoted,
            'temporary_resources_cleaned' => $this->temporaryResourcesCleaned,
            'staged_resources_cleaned' => $this->stagedResourcesCleaned,
            'error' => $this->error,
            'next_action' => $this->nextAction,
        ];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (
            array_keys($value) !== [
                'schema',
                'installation',
                'installation_sha256',
                'phase',
                'history',
                'old_renamed_roles',
                'new_renamed_roles',
                'deleted_old_roles',
                'generation_recorded',
                'manifest_promoted',
                'temporary_resources_cleaned',
                'staged_resources_cleaned',
                'error',
                'next_action',
            ]
            || ($value['schema'] ?? null) !== self::SCHEMA
            || ! is_array($value['installation'] ?? null)
            || ! is_string($value['installation_sha256'] ?? null)
            || ! is_string($value['phase'] ?? null)
            || ! is_array($value['history'] ?? null)
            || ! is_array($value['old_renamed_roles'] ?? null)
            || ! is_array($value['new_renamed_roles'] ?? null)
            || ! is_array($value['deleted_old_roles'] ?? null)
            || ! is_bool($value['generation_recorded'] ?? null)
            || ! is_bool($value['manifest_promoted'] ?? null)
            || ! is_bool($value['temporary_resources_cleaned'] ?? null)
            || ! is_bool($value['staged_resources_cleaned'] ?? null)
            || $value['error'] !== null && ! is_string($value['error'])
            || ! is_string($value['next_action'] ?? null)
        ) {
            throw new InvalidArgumentException('The topology snapshot replacement recovery schema is invalid.');
        }
        $installation = TopologySnapshotReplacementInstallation::fromArray($value['installation']);
        if (! hash_equals($installation->fingerprint(), $value['installation_sha256'])) {
            throw new InvalidArgumentException('The topology snapshot replacement installation fingerprint differs.');
        }

        $history = self::historyValue($value['history']);
        $oldRenamedRoles = self::roleListValue($value['old_renamed_roles']);
        $newRenamedRoles = self::roleListValue($value['new_renamed_roles']);
        $deletedOldRoles = self::roleListValue($value['deleted_old_roles']);
        $recovery = new self(
            $installation,
            $value['phase'],
            $history,
            $oldRenamedRoles,
            $newRenamedRoles,
            $deletedOldRoles,
            $value['generation_recorded'],
            $value['manifest_promoted'],
            $value['temporary_resources_cleaned'],
            $value['staged_resources_cleaned'],
            $value['error'],
            $value['next_action'],
        );
        if ($recovery->toArray() !== $value) {
            throw new InvalidArgumentException('The topology snapshot replacement recovery schema is invalid.');
        }

        return $recovery;
    }

    /**
     * @param  list<array{phase:string,recorded_at:string}>  $history
     */
    private function assertHistory(array $history, string $phase): void
    {
        if ($history === []) {
            throw new InvalidArgumentException('The topology snapshot replacement phase history is invalid.');
        }
        $previousPhase = null;
        $previousTime = null;
        foreach ($history as $index => $entry) {
            if (
                preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $entry['recorded_at']) !== 1
                || $previousTime !== null && $entry['recorded_at'] < $previousTime
            ) {
                throw new InvalidArgumentException('The topology snapshot replacement phase history is invalid.');
            }
            if ($index === 0 && $entry['phase'] !== 'authorized') {
                throw new InvalidArgumentException('The topology snapshot replacement phase history must start authorized.');
            }
            if (
                $previousPhase !== null
                && ! in_array($entry['phase'], self::TRANSITIONS[$previousPhase] ?? [], true)
            ) {
                throw new InvalidArgumentException('The topology snapshot replacement phase transition is invalid.');
            }
            $previousPhase = $entry['phase'];
            $previousTime = $entry['recorded_at'];
        }
        if ($previousPhase !== $phase) {
            throw new InvalidArgumentException('The topology snapshot replacement current phase differs from its history.');
        }
    }

    /** @param list<string> $roles */
    private function assertRoleProgress(array $roles, string $progress): void
    {
        if (array_values(array_intersect(TopologyProfile::ROLES, $roles)) !== $roles) {
            throw new InvalidArgumentException("The topology snapshot replacement {$progress} progress is invalid.");
        }
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    private function appendRole(array $roles, string $role): array
    {
        if (! in_array($role, TopologyProfile::ROLES, true) || in_array($role, $roles, true)) {
            throw new InvalidArgumentException('The topology snapshot replacement progress role is invalid.');
        }
        $roles[] = $role;

        return $roles;
    }

    /**
     * @param  list<array{phase:string,recorded_at:string}>|null  $history
     * @param  list<string>|null  $oldRenamedRoles
     * @param  list<string>|null  $newRenamedRoles
     * @param  list<string>|null  $deletedOldRoles
     */
    private function copy(
        ?string $phase = null,
        ?array $history = null,
        ?array $oldRenamedRoles = null,
        ?array $newRenamedRoles = null,
        ?array $deletedOldRoles = null,
        ?bool $generationRecorded = null,
        ?bool $manifestPromoted = null,
        ?bool $temporaryResourcesCleaned = null,
        ?bool $stagedResourcesCleaned = null,
        ?string $error = null,
        ?string $nextAction = null,
    ): self {
        return new self(
            $this->installation,
            $phase ?? $this->phase,
            $history ?? $this->history,
            $oldRenamedRoles ?? $this->oldRenamedRoles,
            $newRenamedRoles ?? $this->newRenamedRoles,
            $deletedOldRoles ?? $this->deletedOldRoles,
            $generationRecorded ?? $this->generationRecorded,
            $manifestPromoted ?? $this->manifestPromoted,
            $temporaryResourcesCleaned ?? $this->temporaryResourcesCleaned,
            $stagedResourcesCleaned ?? $this->stagedResourcesCleaned,
            $error,
            $nextAction ?? $this->nextAction,
        );
    }

    /**
     * @template T
     *
     * @param  list<T>  $prefix
     * @param  list<T>  $value
     */
    private static function isPrefix(array $prefix, array $value): bool
    {
        return array_slice($value, 0, count($prefix)) === $prefix;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return list<array{phase:string,recorded_at:string}>
     */
    private static function historyValue(array $value): array
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException('The topology snapshot replacement phase history is invalid.');
        }
        $history = [];
        foreach ($value as $entry) {
            if (
                ! is_array($entry)
                || array_keys($entry) !== ['phase', 'recorded_at']
                || ! is_string($entry['phase'] ?? null)
                || ! is_string($entry['recorded_at'] ?? null)
            ) {
                throw new InvalidArgumentException('The topology snapshot replacement phase history is invalid.');
            }
            $history[] = ['phase' => $entry['phase'], 'recorded_at' => $entry['recorded_at']];
        }

        return $history;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return list<string>
     */
    private static function roleListValue(array $value): array
    {
        if (! array_is_list($value) || ! array_all($value, static fn (mixed $role): bool => is_string($role))) {
            throw new InvalidArgumentException('The topology snapshot replacement role progress is invalid.');
        }

        return $value;
    }
}
