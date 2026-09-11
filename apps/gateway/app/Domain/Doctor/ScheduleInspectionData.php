<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

final readonly class ScheduleInspectionData
{
    public function __construct(
        public bool $artifactsPresent,
        public bool $permissionsMatch,
        public bool $specificationMatch,
        public bool $timerStateMatch,
        public bool $calendarMatch,
        public bool $executionContextMatch,
        public bool $completionCallbackMatch,
    ) {}
}
