<?php

declare(strict_types=1);

namespace App\Commands\Schedules;

abstract class ScheduleUuidCommand extends ScheduleCommand
{
    protected function scheduleId(): ?string
    {
        $scheduleId = $this->argument('schedule');

        if (
            preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD',
                $scheduleId,
            ) !== 1
        ) {
            $this->renderGatewayFailure('schedule.id_invalid', 'Schedule UUID is invalid.');

            return null;
        }

        return $scheduleId;
    }
}
