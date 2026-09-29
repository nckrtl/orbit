<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Models\Instance;
use App\Models\Process;

final readonly class CascadeInstanceProcessesAction
{
    public function __construct(
        private RemoveProcessAction $remove,
    ) {}

    public function execute(int $instanceId): void
    {
        Process::query()
            ->whereIn('owner_type', Instance::morphTypes())
            ->where('owner_id', $instanceId)
            ->orderBy('id')
            ->get()
            ->each(fn (Process $process) => $this->remove->execute($process));
    }
}
