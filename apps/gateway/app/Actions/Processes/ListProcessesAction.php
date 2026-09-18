<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Data\Processes\ProcessData;
use App\Domain\Processes\ProcessRuntimeStatusIndex;
use App\Domain\Processes\ProcessTargetType;
use App\Models\Process;
use Illuminate\Support\Collection;

final readonly class ListProcessesAction
{
    public function __construct(
        private ProcessRuntimeStatusIndex $statuses,
    ) {}

    /** @return Collection<int, array<string, mixed>> */
    public function execute(ProcessTargetType $targetType, int $targetId): Collection
    {
        $processes = Process::query()
            ->where('owner_type', $targetType->modelClass())
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
        return $this->withStatuses(
            Process::query()->orderBy('owner_type')->orderBy('owner_id')->orderBy('name')->get(),
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

        /** @var Collection<int, array<string, mixed>> $result */
        $result = new Collection;

        foreach ($processes as $process) {
            /** @var array<string, mixed> $data */
            $data = ProcessData::fromModel(
                $process,
                $statuses[(int) $process->id] ?? 'unknown',
            )->toArray();
            $result->push($data);
        }

        return $result;
    }
}
