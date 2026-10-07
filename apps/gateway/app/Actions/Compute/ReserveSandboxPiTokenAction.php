<?php

declare(strict_types=1);

namespace App\Actions\Compute;

use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxState;
use App\Domain\Tasks\TaskCompute;
use App\Models\TaskSandbox;
use Illuminate\Support\Facades\DB;

/** Persist before guest configuration so retries never rotate a working server's token. */
final readonly class ReserveSandboxPiTokenAction
{
    public function execute(TaskSandbox $sandbox): void
    {
        DB::transaction(function () use ($sandbox): void {
            $locked = TaskSandbox::query()->lockForUpdate()->findOrFail($sandbox->id);
            if ($locked->desired_power === 'destroyed' || $locked->group?->task_compute !== TaskCompute::Vm
                || in_array($locked->state, [SandboxState::Destroying, SandboxState::Destroyed], true)) {
                throw new ComputeException('compute.pi_unavailable', 'The sandbox cannot reserve Pi credentials.');
            }
            if ($locked->pi_token === null) {
                $locked->pi_token = bin2hex(random_bytes(32));
                $locked->save();
            }
        });
        $sandbox->refresh();
    }
}
