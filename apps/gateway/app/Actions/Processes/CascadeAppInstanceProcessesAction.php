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
            ->where('owner_type', AppInstance::class)
            ->where('owner_id', $appInstanceId)
            ->orderBy('id')
            ->get()
            ->each(fn (Process $process) => $this->remove->execute($process));
    }
}
