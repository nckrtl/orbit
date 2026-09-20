<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Data\Processes\ProcessData;
use App\Domain\Processes\ProcessRuntimeStatusIndex;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Processes\ProcessUsageIndex;
use App\Models\Process;
use Illuminate\Support\Collection;

final readonly class ListProcessesAction
{
    public function __construct(
        private ProcessRuntimeStatusIndex $statuses,
        private ProcessUsageIndex $usage,
    ) {}

    /** @return Collection<int, array<string, mixed>> */
    public function execute(ProcessTargetType $targetType, int $targetId): Collection
    {
        $processes = Process::query()
            ->whereIn('owner_type', $targetType->storedTypes())
            ->where('owner_id', $targetId)
            ->orderBy('name')
            ->get();

        return $this->withStatuses($processes);
    }

    /**
     * Every Process in the fleet, for a caller that draws all of them.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function executeAll(): Collection
    {
        // Only the owner types a target can name. The table also holds Processes owned by a
        // legacy model that no target selects, and that ProcessData cannot describe, so listing
        // the fleet returns exactly what listing every target one by one would have.
        $owners = array_values(array_unique(array_merge(
            ...array_map(
                static fn (ProcessTargetType $type): array => $type->storedTypes(),
                ProcessTargetType::cases(),
            ),
        )));

        return $this->withStatuses(
            Process::query()
                ->whereIn('owner_type', $owners)
                ->orderBy('owner_type')
                ->orderBy('owner_id')
                ->orderBy('name')
                ->get(),
        );
    }

    /**
     * @param  Collection<int, Process>  $processes
     * @return Collection<int, array<string, mixed>>
     */
    private function withStatuses(Collection $processes): Collection
    {
        // One lookup for every Process, rather than one round trip each: see
        // ProcessRuntimeStatusIndex for why a list cannot ask each Node in turn.
        $statuses = $this->statuses->statuses($processes);
        $usage = $this->usage->usage($processes);

        /** @var Collection<int, array<string, mixed>> $result */
        $result = new Collection;

        foreach ($processes as $process) {
            $processUsage = $usage[(int) $process->id] ?? ['cpu' => null, 'memory_bytes' => null];
            /** @var array<string, mixed> $data */
            $data = ProcessData::fromModel(
                $process,
                $statuses[(int) $process->id] ?? 'unknown',
                $processUsage['cpu'],
                $processUsage['memory_bytes'],
            )->toArray();
            $result->push($data);
        }

        return $result;
    }
}
