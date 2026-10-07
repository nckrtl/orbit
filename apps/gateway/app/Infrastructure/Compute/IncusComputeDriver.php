<?php

declare(strict_types=1);

namespace App\Infrastructure\Compute;

use App\Domain\Compute\ComputeDriver;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxState;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\SandboxHostOperation;
use App\Infrastructure\Tasks\IncusSandboxHost;
use App\Models\Node;
use App\Models\TaskSandbox;
use Illuminate\Support\Str;

/** One driver instance represents one host and its VM budget, not a group slot. */
final readonly class IncusComputeDriver implements ComputeDriver
{
    public function __construct(
        private Node $host,
        private string $project,
        private int $budget,
        private IncusSandboxHost $transport,
        private ComputeLocks $locks,
    ) {}

    public function capacity(): int
    {
        $result = $this->transport->execute($this->host, SandboxHostOperation::Capacity, $this->project, '00000000-0000-4000-8000-000000000000', $this->budget);

        return is_int($result['available']) ? $result['available'] : 0;
    }

    public function provision(TaskSandbox $sandbox): TaskSandbox
    {
        return $this->operate($sandbox, SandboxHostOperation::Provision);
    }

    public function observe(TaskSandbox $sandbox): TaskSandbox
    {
        return $this->operate($sandbox, SandboxHostOperation::Observe);
    }

    public function sealNetwork(TaskSandbox $sandbox): TaskSandbox
    {
        // Incus has no metadata bootstrap exception. Provision verifies the external policy.
        return $this->provision($sandbox);
    }

    public function park(TaskSandbox $sandbox): TaskSandbox
    {
        return $this->operate($sandbox, SandboxHostOperation::Park);
    }

    public function resume(TaskSandbox $sandbox): TaskSandbox
    {
        return $this->operate($sandbox, SandboxHostOperation::Resume);
    }

    public function destroy(TaskSandbox $sandbox): TaskSandbox
    {
        return $this->operate($sandbox, SandboxHostOperation::Destroy);
    }

    private function operate(TaskSandbox $sandbox, SandboxHostOperation $operation): TaskSandbox
    {
        return $this->locks->incus($this->host->id, function () use ($sandbox, $operation): TaskSandbox {
            if (! $sandbox->exists || ! Str::isUuid($sandbox->id)) {
                throw $this->ownership();
            }
            $sandbox->refresh();
            if ($sandbox->provider !== 'incus' || $sandbox->name !== 'ot-'.substr(hash('sha256', $sandbox->id), 0, 10)
                || ($sandbox->spec['host_id'] ?? null) !== $this->host->id || ($sandbox->spec['project'] ?? null) !== $this->project) {
                throw $this->ownership();
            }
            if ($operation === SandboxHostOperation::Destroy && $sandbox->model_key !== null) {
                throw new ComputeException('compute.model_key_attached', 'Revoke the sandbox model key before destroying its compute.');
            }
            if ($sandbox->state === SandboxState::Destroyed) {
                if (in_array($operation, [SandboxHostOperation::Destroy, SandboxHostOperation::Observe], true)) {
                    return $sandbox;
                }
                throw new ComputeException('compute.ended', 'This sandbox has already been destroyed.');
            }
            if ($sandbox->desired_power === 'destroyed' && $operation !== SandboxHostOperation::Destroy) {
                throw new ComputeException('compute.ended', 'This sandbox is being destroyed.');
            }
            if ($operation === SandboxHostOperation::Destroy && $sandbox->node_id !== null) {
                throw new ComputeException('compute.node_attached', 'Remove the sandbox Node from the fleet before destroying its VM.');
            }
            $spec = $sandbox->spec;
            unset($spec['host_id'], $spec['project']);
            if ($operation !== SandboxHostOperation::Observe) {
                $sandbox->update([
                    'state' => match ($operation) {
                        SandboxHostOperation::Provision => SandboxState::Creating,
                        SandboxHostOperation::Park => SandboxState::Stopping,
                        SandboxHostOperation::Resume => SandboxState::Starting,
                        SandboxHostOperation::Destroy => SandboxState::Destroying,
                        default => throw $this->ownership(),
                    },
                    'desired_power' => match ($operation) {
                        SandboxHostOperation::Park => 'stopped',
                        SandboxHostOperation::Destroy => 'destroyed',
                        default => 'running',
                    },
                    'create_attempted_at' => $operation === SandboxHostOperation::Provision ? ($sandbox->create_attempted_at ?? now()) : $sandbox->create_attempted_at,
                ]);
            }
            try {
                $result = $this->transport->execute($this->host, $operation, $this->project, $sandbox->id, $this->budget, $operation === SandboxHostOperation::Provision ? $spec : null);
            } catch (ResourceOperationException) {
                $sandbox->update(['state' => $operation === SandboxHostOperation::Destroy ? SandboxState::Destroying : SandboxState::Uncertain, 'error_code' => 'compute.host_operation_failed']);
                throw new ComputeException('compute.host_operation_failed', 'The Incus sandbox operation is unresolved; its ownership is retained for retry.');
            }
            $state = match ($result['power']) {
                'running' => SandboxState::Running,
                'stopped' => SandboxState::Stopped,
                default => $operation === SandboxHostOperation::Destroy ? SandboxState::Destroyed : SandboxState::Uncertain,
            };
            $instances = $result['instances'];
            $images = $spec['images'] ?? [];
            if ($state === SandboxState::Running) {
                $expected = is_array($images) ? array_map(fn (int|string $role): string => $sandbox->name.'-'.$role, array_keys($images)) : [];
                $running = [];
                foreach (is_array($instances) ? $instances : [] as $instance) {
                    if (is_array($instance) && ($instance['state'] ?? null) === 'running' && is_string($instance['name'] ?? null)) {
                        $running[] = $instance['name'];
                    }
                }
                sort($expected);
                sort($running);
                if ($expected === [] || $expected !== $running) {
                    $state = SandboxState::Creating;
                }
            }
            $sandbox->update([
                'state' => $state,
                'error_code' => $state === SandboxState::Uncertain ? 'compute.missing_resources' : null,
                ...($state === SandboxState::Destroyed ? ['destroyed_at' => now()] : []),
                ...($operation === SandboxHostOperation::Provision && $state === SandboxState::Running ? ['network_policy' => 'sealed', 'firewall_configured_at' => now()] : []),
            ]);

            return $sandbox->refresh();
        });
    }

    private function ownership(): ComputeException
    {
        return new ComputeException('compute.ownership_mismatch', 'The Incus resource does not match the recorded sandbox ownership.');
    }
}
