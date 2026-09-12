<?php

declare(strict_types=1);

namespace App\Data\Schedules;

use App\Domain\Schedules\ScheduleTargetType;
use App\Models\AppInstance;
use App\Models\Schedule;
use DateTimeInterface;
use SensitiveParameter;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

#[MapOutputName(SnakeCaseMapper::class)]
final class ScheduleData extends Data
{
    public function __construct(
        public string $id,
        public string $targetType,
        public int $targetId,
        public string $name,
        public string $calendar,
        #[SensitiveParameter]
        public string|Optional $command,
        public int $timeoutSeconds,
        public string $desiredTimerState,
        public string $status,
        public ?string $failedStep,
        public ?string $errorCode,
        public ?string $lastRunAt,
        public ?string $lastRunStatus,
    ) {}

    public static function fromModel(#[SensitiveParameter] Schedule $schedule, bool $includeCommand = true): self
    {
        return new self(
            id: $schedule->id,
            targetType: $schedule->target_type === AppInstance::class
                ? ScheduleTargetType::AppInstance->value
                : ScheduleTargetType::Node->value,
            targetId: $schedule->target_id,
            name: $schedule->name,
            calendar: $schedule->calendar,
            command: $includeCommand ? $schedule->command : Optional::create(),
            timeoutSeconds: $schedule->timeout_seconds,
            desiredTimerState: $schedule->desired_timer_state->value,
            status: $schedule->status->value,
            failedStep: $schedule->failed_step,
            errorCode: $schedule->error_code,
            lastRunAt: $schedule->last_run_at?->format(DateTimeInterface::ATOM),
            lastRunStatus: $schedule->last_run_status?->value,
        );
    }
}
