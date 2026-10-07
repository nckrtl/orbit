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
            $result = $this->activateOwned($sandbox, $driver);
            if ($result->state === SandboxState::Running) {
                $result->update(['review_started_at' => null, 'parked_at' => null]);
            }

            return $result;
        });
    }

    public function park(TaskSandbox $sandbox, ComputeDriver $driver): TaskSandbox
    {
        return $this->locks->sandbox($sandbox->id, function () use ($sandbox, $driver): TaskSandbox {
            $sandbox->refresh();

            return $this->parkOwned($sandbox, $driver);
        });
    }

    /** The caller reconciles only a published group that is waiting for review. */
    public function review(TaskSandbox $sandbox, ComputeDriver $driver, bool $preview, bool $capacityWaiting): TaskSandbox
    {
        return $this->locks->sandbox($sandbox->id, function () use ($sandbox, $driver, $preview, $capacityWaiting): TaskSandbox {
            $sandbox->refresh();
            if ($sandbox->state === SandboxState::Destroyed) {
                return $sandbox;
            }
            if ($sandbox->desired_power === 'destroyed') {
                return $this->destroyOwned($sandbox, $driver);
            }
            $sandbox->review_started_at ??= now();
            $sandbox->preview = $preview;
            $sandbox->save();
            if ($preview) {
                return $sandbox->state === SandboxState::Running && $sandbox->desired_power === 'running'
                    ? $sandbox
                    : $this->activateOwned($sandbox, $driver);
            }
            if ($sandbox->provider === 'upcloud' && $sandbox->review_started_at->copy()->addHour()->lessThanOrEqualTo(now())) {
                return $this->destroyOwned($sandbox, $driver);
            }
            if ($capacityWaiting || $sandbox->review_started_at->copy()->addMinutes(5)->lessThanOrEqualTo(now())
                || $sandbox->desired_power === 'stopped') {
                return $this->parkOwned($sandbox, $driver);
            }

            return $sandbox;
        });
    }

    public function destroy(TaskSandbox $sandbox, ComputeDriver $driver): TaskSandbox
    {
        return $this->locks->sandbox($sandbox->id, function () use ($sandbox, $driver): TaskSandbox {
            $sandbox->refresh();

            return $this->destroyOwned($sandbox, $driver);
        });
    }

    private function activateOwned(TaskSandbox $sandbox, ComputeDriver $driver): TaskSandbox
    {
        $this->keys->ensure($sandbox);
        $this->pi->execute($sandbox);

        return $sandbox->state === SandboxState::Stopped || $sandbox->desired_power === 'stopped'
            ? $driver->resume($sandbox)
            : $driver->provision($sandbox);
    }

    private function parkOwned(TaskSandbox $sandbox, ComputeDriver $driver): TaskSandbox
    {
        $result = $sandbox->state === SandboxState::Stopped && $sandbox->desired_power === 'stopped'
            ? $sandbox
            : $driver->park($sandbox);
        if ($result->state === SandboxState::Stopped && $result->parked_at === null) {
            $result->update(['parked_at' => now()]);
        }

        return $result;
    }

    private function destroyOwned(TaskSandbox $sandbox, ComputeDriver $driver): TaskSandbox
    {
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
    }
}
