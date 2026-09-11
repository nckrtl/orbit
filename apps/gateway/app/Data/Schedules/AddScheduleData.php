<?php

declare(strict_types=1);

namespace App\Data\Schedules;

use App\Domain\Schedules\ScheduleTargetType;
use SensitiveParameter;

final readonly class AddScheduleData
{
    public function __construct(
        public ScheduleTargetType $targetType,
        public int $targetId,
        public string $name,
        public string $calendar,
        #[SensitiveParameter]
        public string $command,
        public int $timeoutSeconds = 3600,
        public bool $start = true,
    ) {}
}
