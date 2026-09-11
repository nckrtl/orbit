<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Data\Schedules\AddScheduleData;
use App\Domain\Shared\ResourceOperationException;
use SensitiveParameter;

final readonly class ScheduleSpecificationValidator
{
    public function validate(#[SensitiveParameter] AddScheduleData $data): void
    {
        if (
            preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $data->name) !== 1
            || strlen($data->name) > 63
        ) {
            $this->invalid(ScheduleErrorCode::NameInvalid, 'The Schedule name is invalid.');
        }

        if (
            $data->calendar === ''
            || strlen($data->calendar) > 255
            || preg_match('/\A[\x20-\x7E]+\z/D', $data->calendar) !== 1
        ) {
            $this->invalid(ScheduleErrorCode::CalendarInvalid, 'The Schedule calendar is invalid.');
        }

        if (
            $data->command === ''
            || strlen($data->command) > 4096
            || ! mb_check_encoding($data->command, 'UTF-8')
            || str_contains($data->command, "\0")
            || str_contains($data->command, "\r")
            || str_contains($data->command, "\n")
        ) {
            $this->invalid(ScheduleErrorCode::CommandInvalid, 'The Schedule command is invalid.');
        }

        if ($data->timeoutSeconds < 1 || $data->timeoutSeconds > 86400) {
            $this->invalid(ScheduleErrorCode::TimeoutInvalid, 'The Schedule timeout is invalid.');
        }

        if ($data->targetId < 1) {
            $this->invalid(ScheduleErrorCode::TargetInvalid, 'The Schedule target is invalid.');
        }

        if ($data->targetType === ScheduleTargetType::Node && ! $data->start) {
            $this->invalid(ScheduleErrorCode::StateInvalid, 'A Node Schedule timer must start enabled.');
        }
    }

    private function invalid(ScheduleErrorCode $error, string $message): never
    {
        throw new ResourceOperationException($error->value, $message);
    }
}
