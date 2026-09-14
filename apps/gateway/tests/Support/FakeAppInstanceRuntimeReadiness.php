<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Hibernation\AppInstanceRuntimeReadiness;
use App\Domain\Hibernation\HibernationException;
use App\Models\AppInstance;
use App\Models\Process;

final class FakeAppInstanceRuntimeReadiness implements AppInstanceRuntimeReadiness
{
    public bool $waited = false;

    public ?HibernationException $failure = null;

    /** @var list<int> */
    public array $processIds = [];

    public function waitUntilReady(AppInstance $instance, array $processes): void
    {
        $this->waited = true;
        $this->processIds = array_map(
            static fn (Process $process): int => (int) $process->id,
            $processes,
        );

        if ($this->failure instanceof HibernationException) {
            throw $this->failure;
        }
    }
}
