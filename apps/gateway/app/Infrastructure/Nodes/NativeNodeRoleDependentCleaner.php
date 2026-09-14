<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\NodeRoleDependencySet;
use App\Domain\Nodes\NodeRoleDependentCleaner;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Process;
use Throwable;

final readonly class NativeNodeRoleDependentCleaner implements NodeRoleDependentCleaner
{
    private ProcessRuntimeLease $lease;

    public function __construct(
        private ProcessRuntimeManager $processes,
        ?ProcessRuntimeLease $lease = null,
    ) {
        $this->lease = $lease ?? app(ProcessRuntimeLease::class);
    }

    public function clean(NodeRoleDependencySet $dependencies): void
    {
        foreach ($dependencies->processIds as $processId) {
            $process = Process::query()->findOrFail($processId);

            try {
                $this->lease->run($process, function (Process $fresh): void {
                    $this->processes->remove($fresh);
                });
            } catch (ProcessOperationException $exception) {
                if ($exception->errorCode !== 'process.runtime_lock_failed') {
                    $this->markFailed($process, $exception->step, $exception->errorCode);
                }

                $this->fail('process-runtime', $exception->errorCode, $exception, $exception->result);
            } catch (Throwable $exception) {
                $this->markFailed($process, 'unknown', 'process.remove_failed');
                $this->fail('process-runtime', 'process.remove_failed', $exception, null);
            }
        }
    }

    private function markFailed(Process $dependent, string $step, string $errorCode): void
    {
        $dependent->update([
            'status' => LifecycleStatus::Failed,
            'failed_step' => $step,
            'error_code' => $errorCode,
        ]);
    }

    private function fail(
        string $step,
        string $underlyingErrorCode,
        Throwable $previous,
        ?CommandResult $result,
    ): never {
        throw new NodeRoleOperationException(
            step: $step,
            errorCode: 'node_role.remove_failed',
            underlyingErrorCode: $underlyingErrorCode,
            message: "Node role dependent cleanup failed at [{$step}].",
            result: $result,
            previous: $previous,
        );
    }
}
