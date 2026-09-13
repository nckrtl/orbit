<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use SensitiveParameter;

final class NativeProcessRuntimeLease implements ProcessRuntimeLease
{
    /** @var array<string, int> */
    private array $depths = [];

    public function run(#[SensitiveParameter] Process $process, Closure $operation): mixed
    {
        return $this->execute($process, $operation, relocations: 0);
    }

    /**
     * @template T
     *
     * @param  Closure(Process): T  $operation
     * @return T
     */
    private function execute(
        #[SensitiveParameter]
        Process $process,
        Closure $operation,
        int $relocations,
    ): mixed {
        $key = $this->key($process);

        if (($this->depths[$key] ?? 0) > 0) {
            $this->depths[$key]++;

            try {
                return $operation($this->fresh($process));
            } finally {
                $this->depths[$key]--;
            }
        }

        $lock = Cache::lock($key, 3_600);

        if (! $lock->get()) {
            throw $this->busy($process);
        }

        $this->depths[$key] = 1;

        try {
            $fresh = $this->fresh($process);
            $relocatedKey = $this->key($fresh);

            if ($relocatedKey !== $key) {
                return $this->relocate($fresh, $operation, $relocations, $key, $lock);
            }

            return $operation($fresh);
        } finally {
            if (array_key_exists($key, $this->depths)) {
                unset($this->depths[$key]);
                $lock->release();
            }
        }
    }

    /**
     * @template T
     *
     * @param  Closure(Process): T  $operation
     * @return T
     */
    private function relocate(
        #[SensitiveParameter]
        Process $process,
        Closure $operation,
        int $relocations,
        string $key,
        Lock $lock,
    ): mixed {
        unset($this->depths[$key]);
        $lock->release();

        if ($relocations >= 1) {
            throw $this->busy($process);
        }

        return $this->execute($process, $operation, $relocations + 1);
    }

    private function fresh(#[SensitiveParameter] Process $process): Process
    {
        return Process::query()->whereKey($process->id)->firstOrFail();
    }

    private function key(#[SensitiveParameter] Process $process): string
    {
        $nodeId = match ($process->owner_type) {
            AppInstance::class => (int) (AppInstance::query()->whereKey($process->owner_id)->value('node_id') ?? 0),
            Node::class => $process->owner_id,
            default => 0,
        };

        return "orbit:process-runtime:{$nodeId}:{$process->id}";
    }

    private function busy(#[SensitiveParameter] Process $process): ProcessOperationException
    {
        return new ProcessOperationException(
            step: 'lock-runtime',
            errorCode: 'process.runtime_lock_failed',
            message: "Process [{$process->name}] runtime mutation is already active.",
        );
    }
}
