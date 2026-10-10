<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\WireGuard\WireGuardAddressAllocator;
use App\Jobs\TaskVms\ProvisionTaskVm;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records a group's task VM on the first host with room, then queues its creation. The row is named
 * `tvm-<row id>`, so a retry after a destroyed VM never reuses a name, and it holds its reserved
 * WireGuard address and Pi token from the start.
 */
final readonly class AllocateTaskVmAction
{
    public function __construct(
        private TaskVmSettings $settings,
        private WireGuardAddressAllocator $addresses,
    ) {}

    /** @throws TaskCapacityException when no host has room */
    public function execute(Task $group): TaskVm
    {
        $vm = DB::transaction(function () use ($group): TaskVm {
            $existing = TaskVm::query()->live()->where('group_id', $group->id)->first();
            if ($existing instanceof TaskVm) {
                return $existing;
            }

            $vm = TaskVm::query()->create([
                'group_id' => $group->id,
                'host_node_id' => $this->host(),
                'provider' => 'incus',
                'name' => 'tvm-pending-'.Str::uuid(),
                'state' => TaskVmState::Provisioning,
                'wireguard_ip' => $this->addresses->nextIn(
                    $this->settings->wireguardRange,
                    array_values(TaskVm::query()->live()->get()->map(static fn (TaskVm $vm): string => $vm->wireguard_ip)->all()),
                ),
                'pi_token' => bin2hex(random_bytes(32)),
            ]);
            $vm->update(['name' => 'tvm-'.$vm->id]);

            return $vm;
        });

        if ($vm->wasRecentlyCreated) {
            ProvisionTaskVm::dispatch($vm->id);
        }

        return $vm;
    }

    /** The first configured host that is an active Node with fewer live task VMs than its budget. */
    private function host(): int
    {
        foreach ($this->settings->hosts as $host) {
            $active = Node::query()->whereKey($host->nodeId)->where('status', LifecycleStatus::Active)->exists();
            if ($active && TaskVm::query()->live()->where('host_node_id', $host->nodeId)->count() < $host->maxVms) {
                return $host->nodeId;
            }
        }

        throw new TaskCapacityException(false, 'Task VM: no host has room for another task VM.');
    }
}
