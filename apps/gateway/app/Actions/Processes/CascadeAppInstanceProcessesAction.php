<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Models\AppInstance;
use App\Models\Process;

final readonly class CascadeAppInstanceProcessesAction
{
    public function __construct(
        private RemoveProcessAction $remove,
    ) {}

    public function execute(int $appInstanceId): void
    {
        Process::query()
            ->whereIn('owner_type', AppInstance::morphTypes())
            ->where('owner_id', $appInstanceId)
            ->orderBy('id')
            ->get()
            ->sortBy(static fn (Process $process): int => $process->isAntigravityWatch() ? 0 : 1)
            ->each(fn (Process $process) => $this->remove->execute($process));
    }
}
