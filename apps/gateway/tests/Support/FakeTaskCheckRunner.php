<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckProcess;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskProbeRetirement;
use App\Domain\Tasks\TaskWorkspaceSnapshot;
use App\Models\Instance;
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

    /** @var array<string, TaskCheckProcess> */
    public array $keyedStarts = [];

    /** @var list<int> */
    public array $startTransactionLevels = [];

    public ?\Closure $afterStart = null;

    public ?\Closure $beforeStart = null;

    /** @var list<array<string, mixed>|null> */
    public array $requestedDeliverables = [];

    public function start(Instance $instance, ?string $command, array $setup = [], ?array $deliverables = null, ?string $key = null): TaskCheckProcess
    {
        $this->startTransactionLevels[] = DB::transactionLevel();
        $this->requestedDeliverables[] = $deliverables;
        if ($this->beforeStart !== null) {
            ($this->beforeStart)();
        }
        if ($key !== null && in_array($key, $this->retiredKeys, true)) {
            throw new TaskCheckException('The probe reservation is retired.');
        }
        if ($key !== null && isset($this->keyedStarts[$key])) {
            return $this->keyedStarts[$key];
        }
        $this->starts++;
        $this->setups[] = $setup;
        $this->deliverables[] = $deliverables;
        $this->commands[] = $command;

        $process = new TaskCheckProcess(4000 + $this->starts, 'Wed Sep 23 12:00:0'.$this->starts.' 2026', str_repeat('a', 40), str_repeat('b', 40));
        if ($key !== null) {
            $this->keyedStarts[$key] = $process;
        }
        if ($this->afterStart !== null) {
            ($this->afterStart)($process);
        }

        return $process;
    }

    /** @var list<string> */
    public array $retiredKeys = [];

    public bool $failNextRetirement = false;

    public function retireProbe(Instance $instance, string $key): TaskProbeRetirement
    {
        $this->retiredKeys[] = $key;
        if ($this->failNextRetirement) {
            $this->failNextRetirement = false;
            throw new TaskCheckException('The probe retirement reply was lost.');
        }

        return new TaskProbeRetirement($this->keyedStarts[$key] ?? null,
            ['managed_user' => 'orbit', 'uid' => 1001, 'tmpdir' => isset($this->keyedStarts[$key]) ? '/tmp/orbit-check-1001-held' : '']);
    }

    public function read(Instance $instance, TaskCheckProcess $process): TaskCheckReading
    {
        if ($this->readings === null) {
            return self::passed();
        }

        return array_shift($this->readings) ?? self::passed();
    }

    public function cancel(Instance $instance, TaskCheckProcess $process): void
    {
        $this->cancels++;
        $this->cancelTransactionLevels[] = DB::transactionLevel();
        if ($this->failNextCancel) {
            $this->failNextCancel = false;

            throw new TaskCheckException('The Node is unreachable.');
        }
    }

    public function snapshot(Instance $instance): TaskWorkspaceSnapshot
    {
        if ($this->failSnapshot) {
            throw new TaskCheckException('The workspace tree could not be read.');
        }

        return new TaskWorkspaceSnapshot($this->head, $this->tree, $this->parent, $this->commitTree);
    }
}
