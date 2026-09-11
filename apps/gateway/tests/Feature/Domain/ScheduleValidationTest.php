<?php

declare(strict_types=1);

use App\Data\Schedules\AddScheduleData;
use App\Domain\Schedules\ScheduleSpecificationValidator;
use App\Domain\Schedules\ScheduleTargetType;
use App\Domain\Shared\ResourceOperationException;

function schedule_validation_data(
    string $name = 'backup-daily',
    string $calendar = 'daily',
    string $command = 'php artisan backup:run',
    int $timeout = 3600,
): AddScheduleData {
    return new AddScheduleData(
        ScheduleTargetType::AppInstance,
        1,
        $name,
        $calendar,
        $command,
        $timeout,
    );
}

it('accepts every exact Schedule specification boundary', function (): void {
    $validator = new ScheduleSpecificationValidator;
    $validator->validate(schedule_validation_data(name: 'a'));
    $validator->validate(schedule_validation_data(name: str_repeat('a', 63)));
    $validator->validate(schedule_validation_data(calendar: str_repeat('~', 255)));
    $validator->validate(schedule_validation_data(command: str_repeat('x', 4096)));
    $validator->validate(schedule_validation_data(timeout: 1));
    $validator->validate(schedule_validation_data(timeout: 86400));

    expect(schedule_validation_data()->timeoutSeconds)->toBe(3600);
});

it('rejects invalid Schedule names with one stable redacted error', function (string $name): void {
    expect(fn () => new ScheduleSpecificationValidator()->validate(schedule_validation_data(name: $name)))
        ->toThrow(function (ResourceOperationException $exception) use ($name): void {
            expect($exception->errorCode)->toBe('schedule.name_invalid');

            if ($name !== '') {
                expect($exception->getMessage())->not->toContain($name);
            }
        });
})->with(['', '-daily', 'daily-', 'daily--backup', 'Daily', 'daily_backup', str_repeat('a', 64)]);

it('rejects invalid calendar bytes and size', function (string $calendar): void {
    expect(fn () => new ScheduleSpecificationValidator()->validate(schedule_validation_data(calendar: $calendar)))
        ->toThrow(ResourceOperationException::class, 'Schedule calendar is invalid');
})->with(['', "daily\n", "daily\t", 'é', str_repeat('a', 256)]);

it('rejects invalid command encoding lines and size', function (string $command): void {
    expect(fn () => new ScheduleSpecificationValidator()->validate(schedule_validation_data(command: $command)))
        ->toThrow(ResourceOperationException::class, 'Schedule command is invalid');
})->with(['', "a\0b", "a\rb", "a\nb", "\xC3\x28", str_repeat('a', 4097)]);

it('rejects timeout values outside the closed range', function (int $timeout): void {
    expect(fn () => new ScheduleSpecificationValidator()->validate(schedule_validation_data(timeout: $timeout)))
        ->toThrow(ResourceOperationException::class, 'Schedule timeout is invalid');
})->with([0, 86401]);

it('rejects a disabled Node timer at admission', function (): void {
    $data = new AddScheduleData(
        ScheduleTargetType::Node,
        1,
        'backup',
        'daily',
        'true',
        start: false,
    );

    expect(fn () => new ScheduleSpecificationValidator()->validate($data))
        ->toThrow(ResourceOperationException::class, 'Node Schedule timer must start enabled');
});
