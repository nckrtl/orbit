<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

final readonly class ScheduleRuntimeAccount
{
    public function __construct(
        public string $user,
        public string $group,
        public string $home,
        public string $shell,
    ) {}
}
