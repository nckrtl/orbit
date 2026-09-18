<?php

declare(strict_types=1);

namespace App\Data\AppInstances;

use App\Models\AppInstanceDeployment;
use DateTimeInterface;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class AppInstanceDeploymentData extends Data
{
    public function __construct(
        public int $id,
        public int $appInstanceId,
        public ?string $release,
        public ?string $branch,
        public ?string $commit,
        public string $startedAt,
        public ?string $finishedAt,
        public ?int $durationSeconds,
        public string $status,
        public ?string $failedStep,
        public ?string $errorCode,
        public ?string $selectedRelease,
        public ?string $triggeredBy,
    ) {}

    public static function fromModel(AppInstanceDeployment $deployment): self
    {
        return new self(
            id: $deployment->id,
            appInstanceId: $deployment->app_instance_id,
            release: $deployment->release,
            branch: $deployment->branch,
            commit: $deployment->commit,
            startedAt: $deployment->started_at->format(DateTimeInterface::ATOM),
            finishedAt: $deployment->finished_at?->format(DateTimeInterface::ATOM),
            durationSeconds: $deployment->duration_seconds,
            status: $deployment->status,
            failedStep: $deployment->failed_step,
            errorCode: $deployment->error_code,
            selectedRelease: $deployment->selected_release,
            triggeredBy: $deployment->triggered_by,
        );
    }
}
