<?php

declare(strict_types=1);

namespace Tests\Support\Schedules;

use App\Data\Schedules\ScheduleLogsData;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Models\Schedule;
use Throwable;

final class FakeScheduleRuntimeManager implements ScheduleRuntimeManager
{
    /** @var list<string> */
    public array $installed = [];

    /** @var list<string> */
    public array $activated = [];

    /** @var list<string> */
    public array $ran = [];

    /** @var list<array{id: string, cascade: bool}> */
    public array $removed = [];

    public ?Throwable $failure = null;

    public bool $removalComplete = true;

    public function install(Schedule $schedule): void
    {
        $this->throwFailure();
        $this->installed[] = $schedule->id;
    }

    public function activate(Schedule $schedule): void
    {
        $this->throwFailure();
        $this->activated[] = $schedule->id;
    }

    public function run(Schedule $schedule): void
    {
        $this->throwFailure();
        $this->ran[] = $schedule->id;
    }

    public function logs(Schedule $schedule, int $lines): ScheduleLogsData
    {
        $this->throwFailure();

        return new ScheduleLogsData("safe\n", false);
    }

    public function remove(Schedule $schedule, bool $cascade): bool
    {
        $this->throwFailure();
        $this->removed[] = ['id' => $schedule->id, 'cascade' => $cascade];

        return $this->removalComplete;
    }

    private function throwFailure(): void
    {
        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }
    }
}
