<?php

declare(strict_types=1);

namespace App\Actions\Compute;

use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxNetworkPolicy;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExecutionLock;
use App\Infrastructure\Compute\ComputeLocks;
use App\Infrastructure\Compute\IncusSandboxNodeBootstrap;
use App\Infrastructure\Compute\SandboxFleetIdentity;
use App\Infrastructure\Compute\TaskSandboxDrivers;
use App\Models\Node;
use App\Models\TaskSandbox;
use Throwable;

/** Keep local admission opt-in until Project preview and cleanup are accepted. */
final readonly class EnrollIncusSandboxAction
{
    public function __construct(private TaskSandboxDrivers $drivers, private ComputeLocks $locks, private TaskExecutionLock $groups,
        private SandboxFleetIdentity $identity, private IncusSandboxNodeBootstrap $bootstrap, private SandboxNetworkPolicy $network) {}

    public function execute(TaskSandbox $sandbox): Node
    {
        if (! config('compute.incus.enrollment_enabled', false) || $sandbox->provider !== 'incus' || $sandbox->group_id === null) {
            throw new ComputeException('compute.enrollment_disabled', 'Local Project sandbox enrollment is disabled.');
        }

        return $this->groups->synchronized($sandbox->group_id, fn (): Node => $this->locks->sandbox($sandbox->id, function () use ($sandbox): Node {
            $sandbox->refresh();
            $this->identity->assertActive($sandbox);
            try {
                $sandbox = $this->drivers->forSandbox($sandbox)->observe($sandbox);
                $this->identity->assertActive($sandbox);
                if ($sandbox->network_policy !== 'sealed') {
                    throw new ComputeException('compute.network_unavailable', 'The Project host policy is not confirmed.');
                }
                $key = $sandbox->enrollment === null ? $this->bootstrap->identity($sandbox) : null;
                $node = $this->locks->upcloud(fn (): Node => $this->identity->reserve($sandbox, $key));
                $this->bootstrap->prepare($sandbox, $node);
                $this->network->ensure($sandbox);
                if ($sandbox->enrolled_at === null) {
                    $node = $this->bootstrap->enroll($sandbox, $node);
                }
                $sandbox->refresh();
                $node->refresh();
                if ($node->status !== LifecycleStatus::Active
                    || ! $node->roles()->where('role', RoleName::AppDev)->where('status', LifecycleStatus::Active)->exists()) {
                    throw new ComputeException('compute.enrollment_not_ready', 'The local Project Node has not completed enrollment.');
                }
                $sandbox->enrolled_at ??= now();
                $this->identity->assertReady($sandbox, $node);
                $sandbox->error_code = null;
                $sandbox->save();

                return $node;
            } catch (Throwable $e) {
                $sandbox->error_code = $e instanceof ComputeException ? $e->errorCode : 'compute.enrollment_failed';
                $sandbox->save();
                if ($e instanceof ComputeException) {
                    throw $e;
                }
                throw new ComputeException('compute.enrollment_failed', 'Local Project enrollment failed; its ownership is retained for retry.');
            }
        }));
    }
}
