<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Process;
use SensitiveParameter;

final readonly class ProxyCliProcessOwnership
{
    public function __construct(private ProxyCliState $state) {}

    public function assertRemovable(#[SensitiveParameter] Process $process): void
    {
        if (! $this->state->enabled()) {
            return;
        }

        $nodeId = $this->state->nodeId();

        if (
            $process->owner_type === Node::class
            && $process->owner_id === $nodeId
            && $process->name === ProxyCliProcess::NAME
        ) {
            throw new ResourceOperationException(
                'process.required_by_proxycli',
                "Process [{$process->name}] belongs to the proxycli extension. Disable proxycli first.",
                409,
            );
        }
    }
}
