<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckProcess;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskWorkspaceSnapshot;
use App\Models\AppInstance;
use Illuminate\Support\Facades\DB;

final class FakeTaskCheckRunner implements TaskCheckRunner
{
    public int $starts = 0;

    public int $cancels = 0;

    public bool $failNextCancel = false;

    public bool $failSnapshot = false;

    public string $head;

    public string $tree;

    public ?string $parent = null;

    public ?string $commitTree = null;

    /** @var list<int> the database transaction level at each cancel */
    public array $cancelTransactionLevels = [];

    /**
     * @param  list<TaskCheckReading>|null  $readings  one reading per read; null finishes every check with exit code 0
     */
    public function __construct(private ?array $readings = null)
    {
        $this->head = str_repeat('a', 40);
        $this->tree = str_repeat('b', 40);
    }

    /** @param array<array-key, mixed>|null $deliverables the deliverable evidence the check records */
    public static function passed(?array $deliverables = null): TaskCheckReading
    {
        return TaskCheckReading::finished(0, str_repeat('a', 40), str_repeat('b', 40), [], "checks passed\n", deliverables: $deliverables);
    }

    /** @var list<list<array{name: string, command: string, timeout_seconds: int}>> */
    public array $setups = [];

    /** @var list<array<string, mixed>|null> the deliverables each started check was asked to verify */
    public array $deliverables = [];

    /** @var list<string|null> configured commands for each started check */
    public array $commands = [];

    public function start(AppInstance $instance, ?string $command, array $setup = [], ?array $deliverables = null): TaskCheckProcess
    {
        $this->starts++;
        $this->setups[] = $setup;
        $this->deliverables[] = $deliverables;
        $this->commands[] = $command;

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
        $this->cancelTransactionLevels[] = DB::transactionLevel();
        if ($this->failNextCancel) {
            $this->failNextCancel = false;

            throw new TaskCheckException('The Node is unreachable.');
        }
    }

    public function snapshot(AppInstance $instance): TaskWorkspaceSnapshot
    {
        if ($this->failSnapshot) {
            throw new TaskCheckException('The workspace tree could not be read.');
        }

        return new TaskWorkspaceSnapshot($this->head, $this->tree, $this->parent, $this->commitTree);
    }
}
