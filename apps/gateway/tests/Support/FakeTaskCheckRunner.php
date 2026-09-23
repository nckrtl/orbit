<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tasks\TaskCheckProcess;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Models\AppInstance;

final class FakeTaskCheckRunner implements TaskCheckRunner
{
    public int $starts = 0;

    public int $cancels = 0;

    /**
     * @param  list<TaskCheckReading>|null  $readings  one reading per read; null finishes every check with exit code 0
     */
    public function __construct(private ?array $readings = null) {}

    public static function passed(): TaskCheckReading
    {
        return TaskCheckReading::finished(0, str_repeat('a', 40), str_repeat('b', 40), [], "checks passed\n");
    }

    /** @var list<list<array{name: string, command: string, timeout_seconds: int}>> */
    public array $setups = [];

    public function start(AppInstance $instance, array $setup = []): TaskCheckProcess
    {
        $this->starts++;
        $this->setups[] = $setup;

        return new TaskCheckProcess(4000 + $this->starts, 'Wed Sep 23 12:00:0'.$this->starts.' 2026', str_repeat('a', 40), str_repeat('b', 40));
    }

    public function read(AppInstance $instance, TaskCheckProcess $process): TaskCheckReading
    {
        if ($this->readings === null) {
            return self::passed();
        }

        return array_shift($this->readings) ?? self::passed();
    }

    public function cancel(AppInstance $instance, TaskCheckProcess $process): void
    {
        $this->cancels++;
    }
}
