<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Models\Process;

/**
 * Broadcasts one `process.usage` sample: the CPU and memory of every Process, read with the two
 * fleet-wide Prometheus queries the Process list uses, in parts of at most `PartSize` Processes so
 * each message stays under Reverb's 10,000-byte limit (ADR 0151).
 */
final readonly class ProcessUsageBroadcaster
{
    public const int PartSize = 200;

    public function __construct(
        private ProcessUsageIndex $usage,
        private RecordEventBroadcaster $broadcaster,
    ) {}

    /** @return int The number of parts sent. */
    public function publish(int $sampledAt): int
    {
        $owners = array_values(array_unique(array_merge(
            ...array_map(static fn (ProcessTargetType $type): array => $type->storedTypes(), ProcessTargetType::cases()),
        )));
        $processes = Process::query()->whereIn('owner_type', $owners)->orderBy('id')->get();
        $usage = $processes->isEmpty() ? [] : $this->usage->usage($processes);
        $rows = [];

        foreach ($processes as $process) {
            $sample = $usage[(int) $process->id] ?? ['cpu' => null, 'memory_bytes' => null];
            $rows[] = [(int) $process->id, $sample['cpu'] === null ? null : round($sample['cpu'], 4), $sample['memory_bytes']];
        }

        $parts = $rows === [] ? [[]] : array_chunk($rows, self::PartSize);

        foreach ($parts as $index => $part) {
            $this->broadcaster->broadcast(RecordEventType::ProcessUsage, $sampledAt, [
                'part' => $index + 1,
                'parts' => count($parts),
                'processes' => $part,
            ]);
        }

        return count($parts);
    }
}
