<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One attempt to put a Gateway release current, including a switch-back or a pause. The record
 * exists from the moment the attempt is requested: `queued` until a release unit claims it,
 * `running` while it holds the release lock, then one final outcome.
 *
 * @property int $id
 * @property string|null $release_id
 * @property string|null $sha
 * @property string|null $requested
 * @property bool $force
 * @property array<string, mixed>|null $alert
 * @property string $trigger
 * @property string $outcome
 * @property bool $migrations_ran
 * @property bool $retryable
 * @property bool $cleanup_paused
 * @property string|null $snapshot_path
 * @property string|null $previous_release_id
 * @property array<string, mixed> $phases
 * @property string|null $error_code
 * @property string|null $message
 * @property int $duration_ms
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class GatewayRelease extends Model
{
    public const string Queued = 'queued';

    public const string Running = 'running';

    public const string Interrupted = 'interrupted';

    /** Outcomes that mark the commit failed for automatic releases, unless the failure is retryable. */
    public const array FailedOutcomes = ['failed', 'switched_back', 'paused'];

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'release_id',
        'sha',
        'trigger',
        'outcome',
        'migrations_ran',
        'retryable',
        'cleanup_paused',
        'snapshot_path',
        'previous_release_id',
        'phases',
        'error_code',
        'message',
        'duration_ms',
        'requested',
        'force',
        'alert',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'migrations_ran' => 'boolean',
            'retryable' => 'boolean',
            'cleanup_paused' => 'boolean',
            'phases' => 'array',
            'duration_ms' => 'integer',
            'force' => 'boolean',
            'alert' => 'array',
        ];
    }

    /**
     * Commits that already failed a release for the commit itself, so they never ship automatically:
     * a failed, switched-back, paused, or interrupted deploy, automatic release, or adoption with no
     * retry left.
     *
     * @return list<string>
     */
    public static function failedShas(): array
    {
        return array_values(array_unique(self::query()
            ->where('trigger', '!=', 'rollback')
            ->whereIn('outcome', [...self::FailedOutcomes, self::Interrupted])
            ->where('retryable', false)
            ->get(['sha', 'requested'])
            ->map(static fn (self $record): ?string => $record->commit())
            ->filter(static fn (?string $sha): bool => $sha !== null)
            ->all()));
    }

    /**
     * The full commit of the attempt: its resolved `sha`, or the `requested` commit when the caller
     * named a full SHA and the attempt died before prepare resolved it.
     */
    public function commit(): ?string
    {
        foreach ([$this->sha, $this->requested] as $candidate) {
            if (is_string($candidate) && preg_match('/\A[0-9a-f]{40}\z/D', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    /** Whether the attempt has ended. A queued or running record can still change. */
    public function finished(): bool
    {
        return ! in_array($this->outcome, [self::Queued, self::Running], true);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'id' => $this->id,
            'release' => $this->release_id,
            'sha' => $this->sha,
            'requested' => $this->requested,
            'trigger' => $this->trigger,
            'force' => $this->force,
            'outcome' => $this->outcome,
            'migrations_ran' => $this->migrations_ran,
            'retryable' => $this->retryable,
            'cleanup_paused' => $this->cleanup_paused,
            'snapshot' => $this->snapshot_path,
            'previous' => $this->previous_release_id,
            'phases' => $this->phases,
            'error_code' => $this->error_code,
            'message' => $this->message,
            'duration_ms' => $this->duration_ms,
            'alert' => $this->alert,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
