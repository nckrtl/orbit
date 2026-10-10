<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Actions\Nodes\ProvisionNodeAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmSettings;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Sleep;

/**
 * Waits for cloud-init, reads the guest address and host key from the host, and enrolls the VM as the
 * `app-dev` Node `tvm-<id>` through the host as jump Node. The guest values are read only here, before
 * any code but cloud-init has run in the VM. It never creates the VM again.
 */
final class EnrollTaskVm implements ShouldBeUnique, ShouldQueue
{
    use ProvisionsTaskVm;

    /** Each poll releases the job and counts as a try. 150 tries of 5 seconds cover the 10-minute boot window; they also bound a job that a worker stop keeps cutting off. */
    public int $tries = 150;

    public int $maxExceptions = 3;

    public int $backoff = 30;

    public int $timeout = 1500;

    /** A VM from the base image is done with cloud-init about 20 seconds after launch. */
    private const int PollSeconds = 5;

    private const int BootstrapMinutes = 10;

    /** Failures that a retry cannot fix. */
    private const array Permanent = ['task_vm.bootstrap_failed', 'task_vm.bootstrap_timeout', 'task_vm.vm_not_running'];

    /** @param  int  $launchedAt  when `ProvisionTaskVm` launched the VM: the boot limit counts from then, not from the row, because jobs run one at a time */
    public function __construct(public int $taskVmId, public int $launchedAt)
    {
        $this->onConnection('task-vms')->onQueue('task-vms');
    }

    public function handle(TaskVmProvider $provider, ProvisionNodeAction $nodes, TaskVmSettings $settings): void
    {
        $vm = $this->provisioning();
        if (! $vm instanceof TaskVm) {
            return;
        }
        if ($vm->node?->status === LifecycleStatus::Active) {
            PrepareTaskVmRuntime::dispatch($vm->id);

            return;
        }

        try {
            $address = $this->bootstrappedAddress($vm, $provider);
        } catch (TaskVmException $exception) {
            if (! in_array($exception->errorCode, self::Permanent, true)) {
                throw $exception;
            }
            $this->fail($exception);

            return;
        }
        if ($address === null) {
            $this->release(self::PollSeconds);

            return;
        }

        $fingerprint = $provider->sshHostFingerprint($vm);
        $vm->update(['address' => $address]);
        $nodes->execute(new ProvisionNodeData(
            name: $vm->name,
            publicSshHost: $address,
            roles: [RoleName::AppDev],
            user: 'orbit',
            orbitUser: 'orbit',
            wireguardIp: $vm->wireguard_ip,
            expectedSshHostFingerprint: $fingerprint,
            clusterProvided: true,
            clusterId: $settings->devClusterId,
            sshJumpNodeId: $vm->host_node_id,
            taskVmId: $vm->id,
        ));
        PrepareTaskVmRuntime::dispatch($vm->id);
    }

    /**
     * The guest's bridge address once cloud-init is done without errors, or null while the VM boots.
     *
     * @throws TaskVmException
     */
    private function bootstrappedAddress(TaskVm $vm, TaskVmProvider $provider): ?string
    {
        $observation = $provider->observe($vm);
        if ($observation?->running === false) {
            // A guest reboot shows the VM stopped for about a second.
            Sleep::for(5)->seconds();
            $observation = $provider->observe($vm);
        }
        if ($observation?->running !== true) {
            throw new TaskVmException('task_vm.vm_not_running', "Task VM [{$vm->name}] is ".($observation === null ? 'absent' : 'stopped').' on its host.', 502);
        }
        if ($observation->address !== null && $provider->bootstrapReady($vm)) {
            return $observation->address;
        }
        if (now()->getTimestamp() - $this->launchedAt > self::BootstrapMinutes * 60) {
            throw new TaskVmException('task_vm.bootstrap_timeout', "Cloud-init on task VM [{$vm->name}] was not done ".self::BootstrapMinutes.' minutes after launch.', 504);
        }

        return null;
    }
}
