<?php

declare(strict_types=1);

namespace App\Actions\Compute;

use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxState;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\Compute\TaskSandboxDrivers;
use App\Infrastructure\Compute\TaskSandboxLifecycle;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskSandbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Reserve local capacity first. Cloud overspill is restricted to the project lane. */
final readonly class AllocateTaskSandboxAction
{
    public function __construct(private TaskSandboxDrivers $drivers, private ProvisionTaskSandboxAction $cloud, private TaskSandboxLifecycle $lifecycle) {}

    public function execute(Task $group): TaskSandbox
    {
        $group->requireManagedExecution();
        $this->assertGroup($group);
        $existing = $this->existing($group);
        if ($existing instanceof TaskSandbox) {
            return $this->activate($existing);
        }
        $candidates = [];
        if (config('compute.incus.enabled', false)) {
            foreach ($this->drivers->localHosts() as $settings) {
                $images = $group->project->slug === 'orbit'
                    ? array_intersect_key($settings['orbit_images'], array_flip(['operator', 'gateway']))
                    : (isset($settings['project_images'][$group->project->slug]) ? ['operator' => $settings['project_images'][$group->project->slug]] : []);
                if (count($images) !== ($group->project->slug === 'orbit' ? 2 : 1)) {
                    continue;
                }
                // A failed observation is not evidence of a full host. Do not silently move to cloud.
                $available = $this->drivers->local($settings)->capacity();
                $candidates[] = ['settings' => $settings, 'images' => $images, 'available' => $available];
            }
        }
        $sandbox = DB::transaction(function () use ($group, $candidates): ?TaskSandbox {
            $locked = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
            $this->assertGroup($locked);
            $existing = $this->existing($locked);
            if ($existing instanceof TaskSandbox) {
                return $existing;
            }
            if ($locked->taskable_id !== null) {
                throw new ComputeException('compute.placement_conflict', 'The task group already has a workspace; its placement cannot change.');
            }
            // Serialize reservations from different groups before reading host budgets and subnets.
            $hostNodes = Node::query()->whereIn('id', array_column(array_column($candidates, 'settings'), 'node_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $locals = TaskSandbox::query()->where('provider', 'incus')->where('state', '!=', SandboxState::Destroyed->value)->get();
            foreach ($candidates as $candidate) {
                $settings = $candidate['settings'];
                $onHost = $locals->filter(fn (TaskSandbox $row): bool => ($row->spec['host_id'] ?? null) === $settings['node_id']);
                $reserved = $onHost->sum(function (TaskSandbox $row) use ($settings): int {
                    if ($row->state === SandboxState::Stopped && $row->desired_power === 'stopped') {
                        return 0;
                    }
                    $images = $row->spec['images'] ?? null;

                    return is_array($images) && $images !== [] ? count($images) : $settings['max_vms'];
                });
                $needed = count($candidate['images']);
                if ($candidate['available'] < $needed || $needed > $settings['max_vms'] - $reserved) {
                    continue;
                }
                $subnet = null;
                $used = $onHost->map(fn (TaskSandbox $row): mixed => $row->spec['subnet'] ?? null)->all();
                for ($index = 1; $index < 255; $index++) {
                    $next = '10.233.'.$index.'.0/24';
                    if (! in_array($next, $used, true)) {
                        $subnet = $next;
                        break;
                    }
                }
                if ($subnet === null) {
                    continue;
                }
                $proxy = [];
                if ($group->project->slug === 'orbit' && $settings['gateway_address'] !== null) {
                    $address = $hostNodes->get($settings['node_id'])?->wireguard_ip;
                    if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || ! str_starts_with($address, '10.44.')) {
                        throw new ComputeException('compute.invalid_configuration', 'The Incus Pi proxy needs the host WireGuard address.');
                    }
                    // The subnet remains reserved while stopped, so its proxy port does too.
                    $proxy = ['pi_host' => $address, 'pi_port' => 23000 + $index, 'gateway_address' => $settings['gateway_address']];
                }
                $id = (string) Str::uuid();

                return TaskSandbox::query()->create([
                    'id' => $id, 'group_id' => $locked->id, 'provider' => 'incus',
                    'name' => 'ot-'.substr(hash('sha256', $id), 0, 10), 'state' => SandboxState::Reserved,
                    'desired_power' => 'running', 'spec' => [
                        'host_id' => $settings['node_id'], 'project' => $settings['project'], 'pool' => $settings['pool'],
                        'images' => $candidate['images'], 'subnet' => $subnet, 'blocked_networks' => $settings['blocked_networks'], ...$proxy,
                        ...($settings['model_proxy_origin'] === null ? [] : ['model_proxy_origin' => $settings['model_proxy_origin']]),
                    ],
                ]);
            }

            return null;
        });
        if ($sandbox instanceof TaskSandbox) {
            return $this->activate($sandbox);
        }
        if ($group->project->slug === 'orbit') {
            throw new ComputeException('compute.capacity', 'No local Incus host has capacity for the Orbit sandbox pair.');
        }

        return $this->cloud->execute($group);
    }

    private function activate(TaskSandbox $sandbox): TaskSandbox
    {
        $driver = $this->drivers->forSandbox($sandbox);

        return $this->lifecycle->activate($sandbox, $driver);
    }

    private function existing(Task $group): ?TaskSandbox
    {
        return TaskSandbox::query()->where('group_id', $group->id)->where('state', '!=', SandboxState::Destroyed->value)->first();
    }

    private function assertGroup(Task $group): void
    {
        if (! $group->exists || $group->parent_id !== null || $group->task_compute !== TaskCompute::Vm
            || in_array($group->status, [TaskGroupStatus::Completed, TaskGroupStatus::Cancelled, TaskGroupStatus::Failed], true)) {
            throw new ComputeException('compute.invalid_group', 'Only an active group pinned to VM compute can allocate a sandbox.');
        }
    }
}
