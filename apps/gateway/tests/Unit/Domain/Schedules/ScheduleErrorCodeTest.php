<?php

declare(strict_types=1);

use App\Domain\Schedules\ScheduleErrorCode;

it('keeps the closed stable Schedule operation error catalog', function (): void {
    expect(array_map(static fn (ScheduleErrorCode $code): string => $code->value, ScheduleErrorCode::cases()))
        ->toBe([
            'schedule.name_invalid',
            'schedule.target_invalid',
            'schedule.target_unavailable',
            'schedule.calendar_invalid',
            'schedule.command_invalid',
            'schedule.timeout_invalid',
            'schedule.retry_conflict',
            'schedule.state_invalid',
            'schedule.target_in_use',
            'schedule.artifact_conflict',
            'schedule.install_failed',
            'schedule.rollback_failed',
            'schedule.run_failed',
            'schedule.activation_failed',
            'schedule.logs_failed',
            'schedule.remove_failed',
            'schedule.node_unreachable',
        ]);
});
