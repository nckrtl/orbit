<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One attempt to put a Gateway release current, including a switch-back or a pause.
 *
 * @property int $id
 * @property string $release_id
 * @property string $sha
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
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'id' => $this->id,
            'release' => $this->release_id,
            'sha' => $this->sha,
            'trigger' => $this->trigger,
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
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
