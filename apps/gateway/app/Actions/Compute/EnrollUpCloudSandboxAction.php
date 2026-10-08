<?php

declare(strict_types=1);

namespace App\Actions\Compute;

use App\Domain\Compute\ComputeDriver;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxNetworkPolicy;
use App\Domain\Compute\SandboxNodeBootstrap;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExecutionLock;
use App\Infrastructure\Compute\ComputeLocks;
use App\Infrastructure\Compute\SandboxFleetIdentity;
use App\Models\Node;
use App\Models\TaskSandbox;
use Throwable;

final readonly class EnrollUpCloudSandboxAction
{
    public function __construct(private ComputeDriver $driver, private ComputeLocks $locks, private TaskExecutionLock $groups,
        private SandboxFleetIdentity $identity, private SandboxNodeBootstrap $bootstrap, private SandboxNetworkPolicy $network) {}

    public function execute(TaskSandbox $sandbox): Node
    {
        if (! config('compute.upcloud.enrollment_enabled', false) || $sandbox->group_id === null) {
            throw new ComputeException('compute.enrollment_disabled', 'UpCloud sandbox enrollment is disabled.');
        }

        return $this->groups->synchronized($sandbox->group_id, fn (): Node => $this->locks->sandbox($sandbox->id, function () use ($sandbox): Node {
            $sandbox->refresh();
            $this->identity->assertActive($sandbox);
            try {
                $sandbox = $this->driver->observe($sandbox);
                $node = $this->locks->upcloud(fn (): Node => $this->identity->reserve($sandbox));
                $this->identity->assertOwned($sandbox, $node);
                if ($sandbox->enrolled_at === null) {
                    $this->bootstrap->prepare($sandbox, $node);
                }
                $sandbox = $this->driver->sealNetwork($sandbox);
                $this->network->ensure($sandbox);
                if ($sandbox->enrolled_at === null) {
                    $node = $this->bootstrap->enroll($sandbox, $node);
                }
                $node->refresh();
                $this->identity->assertOwned($sandbox, $node);
                if ($node->status !== LifecycleStatus::Active
                    || ! $node->roles()->where('role', RoleName::AppDev->value)->where('status', LifecycleStatus::Active)->exists()) {
                    throw new ComputeException('compute.enrollment_not_ready', 'The sandbox Node has not completed enrollment.');
                }
                $sandbox->enrolled_at ??= now();
                $sandbox->error_code = null;
                $sandbox->save();

                return $node;
            } catch (Throwable $e) {
                $sandbox->error_code = $e instanceof ComputeException ? $e->errorCode : 'compute.enrollment_failed';
                $sandbox->save();
                if ($e instanceof ComputeException) {
                    throw $e;
                }
                throw new ComputeException('compute.enrollment_failed', 'Sandbox enrollment failed; its ownership is retained for retry.');
            }
        }));
    }
}
