<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Models\Node;
use App\Models\Process;

final readonly class CascadeNodeProcessesAction
{
    public function __construct(
        private RemoveProcessAction $remove,
    ) {}

    public function execute(int $nodeId): void
    {
        Process::query()
            ->where('owner_type', Node::class)
            ->where('owner_id', $nodeId)
            ->orderBy('id')
            ->get()
            ->each(fn (Process $process) => $this->remove->execute($process));
    }
}
