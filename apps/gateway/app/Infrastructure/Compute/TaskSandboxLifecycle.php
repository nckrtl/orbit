<?php

declare(strict_types=1);

namespace App\Infrastructure\Compute;

use App\Actions\Compute\ReserveSandboxPiTokenAction;
use App\Domain\Compute\ComputeDriver;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxState;
use App\Models\TaskSandbox;

/** Serialize credential and compute transitions for one reservation. */
final readonly class TaskSandboxLifecycle
{
    public function __construct(private ComputeLocks $locks, private SandboxModelKeys $keys, private ReserveSandboxPiTokenAction $pi) {}

    public function activate(TaskSandbox $sandbox, ComputeDriver $driver): TaskSandbox
    {
        return $this->locks->sandbox($sandbox->id, function () use ($sandbox, $driver): TaskSandbox {
            $sandbox->refresh();
            $this->keys->ensure($sandbox);
            $this->pi->execute($sandbox);

            return $sandbox->state === SandboxState::Stopped || $sandbox->desired_power === 'stopped'
                ? $driver->resume($sandbox)
                : $driver->provision($sandbox);
        });
    }

    public function park(TaskSandbox $sandbox, ComputeDriver $driver): TaskSandbox
    {
        return $this->locks->sandbox($sandbox->id, fn (): TaskSandbox => $driver->park($sandbox));
    }

    public function destroy(TaskSandbox $sandbox, ComputeDriver $driver): TaskSandbox
    {
        return $this->locks->sandbox($sandbox->id, function () use ($sandbox, $driver): TaskSandbox {
            $sandbox->refresh();
            if ($sandbox->node_id !== null) {
                throw new ComputeException('compute.node_attached', 'Remove the sandbox Node from the fleet before destroying its VM.');
            }
            $sandbox->update(['desired_power' => 'destroyed']);
            $this->keys->revoke($sandbox);
            $result = $driver->destroy($sandbox);
            if ($result->state === SandboxState::Destroyed) {
                $result->pi_token = null;
                $result->save();
            }

            return $result;
        });
    }
}
