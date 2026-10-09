<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\Pi;

use App\Domain\Compute\SandboxState;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\TaskCompute;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmPlacement;
use App\Infrastructure\Compute\SandboxFleetIdentity;
use App\Infrastructure\Compute\TaskSandboxDrivers;
use App\Models\Instance;
use App\Models\Node;
use App\Models\TaskSandbox;
use Throwable;

final readonly class SandboxPiConnection
{
    public function __construct(private TaskSandboxDrivers $drivers, private SandboxFleetIdentity $identity) {}

    public function endpoint(Instance $workspace, Node $node): PiEndpoint|Node
    {
        if ($workspace->node_id !== $node->id) {
            throw $this->unavailable();
        }
        if ($workspace->task_sandbox_id === null) {
            try {
                TaskVmPlacement::assertOwnedWorkspace($workspace);
            } catch (TaskVmException) {
                throw $this->unavailable();
            }

            return $node;
        }
        $sandbox = TaskSandbox::query()->find($workspace->task_sandbox_id);
        $group = $sandbox?->group;
        $token = $sandbox?->pi_token;
        if ($sandbox === null || $group === null || $group->task_compute !== TaskCompute::Vm
            || $group->project_id !== $workspace->project_id || $group->taskable_id !== $workspace->id
            || $group->taskable_type !== $workspace->getMorphClass() || $sandbox->state !== SandboxState::Running
            || $sandbox->desired_power !== 'running' || $node->status !== LifecycleStatus::Active
            || ! is_string($token) || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
            throw $this->unavailable();
        }
        $port = 3774;
        if ($group->project->slug === 'orbit') {
            $host = array_find($this->drivers->localHosts(), fn (array $host): bool => $host['node_id'] === $node->id);
            $port = $sandbox->spec['pi_port'] ?? null;
            if ($sandbox->provider !== 'incus' || ($sandbox->spec['host_id'] ?? null) !== $node->id
                || $host === null || ($sandbox->spec['project'] ?? null) !== $host['project']
                || ! is_int($port) || $port < 20000 || $port > 60999) {
                throw $this->unavailable();
            }
        } elseif (! in_array($sandbox->provider, ['incus', 'upcloud'], true) || $sandbox->node_id !== $node->id) {
            throw $this->unavailable();
        }
        if ($group->project->slug !== 'orbit') {
            try {
                $this->identity->assertReady($sandbox, $node);
                if ($sandbox->pi_ready_at === null || $sandbox->model_key === null || $sandbox->model_key_registered_at === null || $sandbox->model_key_revoked_at !== null) {
                    throw $this->unavailable();
                }
            } catch (Throwable) {
                throw $this->unavailable();
            }
        }
        $address = $node->wireguard_ip;
        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw $this->unavailable();
        }
        $address = str_contains($address, ':') ? '['.$address.']' : $address;

        return new PiEndpoint('http://'.$address.':'.$port, $token, $sandbox->model_key === null ? [] : [$sandbox->model_key]);
    }

    private function unavailable(): AgentDriverException
    {
        return new AgentDriverException('The original sandbox Pi server is unavailable.');
    }
}
