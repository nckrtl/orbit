<?php

declare(strict_types=1);

namespace App\Data\GatewayReleases;

use App\Models\GatewayRelease;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use stdClass;

/**
 * One Gateway release record ([Update and recover a Gateway](/reference/gateway-recovery#automatic-releases)).
 * `release` and `sha` stay null while a queued deploy names a short SHA that prepare has not resolved yet.
 */
#[MapOutputName(SnakeCaseMapper::class)]
final class GatewayReleaseData extends Data
{
    /**
     * @param  array<string, mixed>  $phases
     * @param  array<string, mixed>|null  $alert
     */
    public function __construct(
        public int $id,
        public ?string $release,
        public ?string $sha,
        public ?string $requested,
        public string $trigger,
        public bool $force,
        public string $outcome,
        public bool $finished,
        public bool $migrationsRan,
        public bool $retryable,
        public bool $cleanupPaused,
        public ?string $snapshot,
        public ?string $previous,
        public array $phases,
        public ?string $errorCode,
        public ?string $message,
        public int $durationMs,
        public ?array $alert,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {}

    public static function fromModel(GatewayRelease $release): self
    {
        return new self(
            id: $release->id,
            release: $release->release_id,
            sha: $release->sha,
            requested: $release->requested,
            trigger: $release->trigger,
            force: $release->force,
            outcome: $release->outcome,
            finished: $release->finished(),
            migrationsRan: $release->migrations_ran,
            retryable: $release->retryable,
            cleanupPaused: $release->cleanup_paused,
            snapshot: $release->snapshot_path,
            previous: $release->previous_release_id,
            phases: $release->phases,
            errorCode: $release->error_code,
            message: $release->message,
            durationMs: $release->duration_ms,
            alert: $release->alert,
            createdAt: $release->created_at?->toIso8601String(),
            updatedAt: $release->updated_at?->toIso8601String(),
        );
    }

    /**
     * `phases` is a JSON object even before the first step ends.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        $array = parent::toArray();

        if ($array['phases'] === []) {
            $array['phases'] = new stdClass;
        }

        return $array;
    }
}
