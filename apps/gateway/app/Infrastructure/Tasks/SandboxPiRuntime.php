<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxState;
use App\Domain\Tasks\TaskExecutionLock;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use App\Models\TaskSandbox;
use Throwable;

/** Prepare group credentials and confirm Pi and model authentication before admission. */
final readonly class SandboxPiRuntime
{
    public function __construct(private TaskWorkspaceExecutor $guest, private TaskExecutionLock $groups) {}

    public function prepare(Instance $workspace): void
    {
        $groupId = $workspace->taskSandbox?->group_id;
        if ($groupId === null) {
            throw new ComputeException('compute.pi_unavailable', 'The sandbox runtime has no owning task group.');
        }
        $this->groups->synchronized($groupId, fn () => $this->prepareOwned($workspace));
    }

    private function prepareOwned(Instance $workspace): void
    {
        $sandbox = $workspace->taskSandbox?->fresh();
        if ($sandbox !== null) {
            $sandbox->pi_ready_at = null;
            $sandbox->save();
        }
        if ($sandbox === null || $sandbox->state !== SandboxState::Running || $sandbox->desired_power !== 'running'
            || $sandbox->pi_token === null || $sandbox->model_key === null || $sandbox->model_key_registered_at === null
            || $sandbox->model_key_revoked_at !== null) {
            throw new ComputeException('compute.pi_unavailable', 'The sandbox runtime needs running compute and registered credentials.');
        }
        $program = file_get_contents(resource_path('compute/guest-pi-runtime.py'));
        if (! is_string($program)) {
            throw new ComputeException('compute.pi_unavailable', 'The sandbox runtime program is unavailable.');
        }
        try {
            $relay = $this->relay($sandbox);
            $ingress = $this->ingress($sandbox, $workspace);
            if ($ingress !== null) {
                if ($relay['model_relay_address'] !== $ingress['bridge']) {
                    throw new ComputeException('compute.pi_unavailable', 'The sandbox Pi network has no matching model relay.');
                }
                $networkProgram = file_get_contents(resource_path('compute/guest-pi-ingress.py'));
                if (! is_string($networkProgram)) {
                    throw new ComputeException('compute.pi_unavailable', 'The sandbox Pi network program is unavailable.');
                }
                $network = $this->guest->execute($workspace, new RemoteCommand(['sudo', '-n', 'python3', '-I', '-c',
                    "SOURCE = '".base64_encode($networkProgram)."'\n".$networkProgram],
                    protectedInput: ProtectedInput::fromString(json_encode($ingress, JSON_THROW_ON_ERROR)), timeout: 90, maxOutputBytes: 8192),
                    'sandbox-pi-network', 'tasks.pi_setup_failed');
                $networkData = json_decode($network->stdout, true, flags: JSON_THROW_ON_ERROR);
                if ($network->truncated || ! is_array($networkData) || ($networkData['sandbox_id'] ?? null) !== $sandbox->id || ($networkData['ready'] ?? null) !== true) {
                    throw new ComputeException('compute.pi_unavailable', 'The sandbox Pi network did not confirm readiness.');
                }
            }
            $request = ['sandbox_id' => $sandbox->id, 'checkout' => $workspace->checkout_path, 'pi_token' => $sandbox->pi_token,
                'model_key' => $sandbox->model_key, 'models' => config('compute.pi.models', []), 'pi_ingress' => $ingress, ...$relay];
            $result = $this->guest->execute($workspace, new RemoteCommand(['sudo', '-n', 'python3', '-I', '-c', $program],
                protectedInput: ProtectedInput::fromString(json_encode($request, JSON_THROW_ON_ERROR)), timeout: 90, maxOutputBytes: 8192),
                'sandbox-pi', 'tasks.pi_setup_failed');
            $data = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
            if ($result->truncated || ! is_array($data) || ($data['sandbox_id'] ?? null) !== $sandbox->id || ($data['ready'] ?? null) !== true) {
                throw new ComputeException('compute.pi_unavailable', 'The sandbox runtime did not confirm readiness.');
            }
            $sandbox->pi_ready_at = now();
            $sandbox->save();
        } catch (Throwable) {
            // The guest can echo either key, including in malformed output. Do not retain its exception.
            throw new ComputeException('compute.pi_unavailable', 'The sandbox runtime did not confirm readiness.');
        }
    }

    /** @return array{sandbox_id: string, address: string, bridge: string, gateway: string}|null */
    private function ingress(TaskSandbox $sandbox, Instance $workspace): ?array
    {
        if ($sandbox->provider !== 'incus') {
            return null;
        }
        $spec = $sandbox->spec;
        if (! array_key_exists('pi_host', $spec) && ! array_key_exists('pi_port', $spec) && ! array_key_exists('gateway_address', $spec)) {
            return null;
        }
        $subnet = $spec['subnet'] ?? null;
        $gateway = $spec['gateway_address'] ?? null;
        if ($workspace->project->slug !== 'orbit' || ! is_string($subnet)
            || preg_match('/\A10\.233\.([0-9]{1,3})\.0\/24\z/D', $subnet, $match) !== 1 || (int) $match[1] > 255
            || ! is_string($gateway) || filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || ! str_starts_with($gateway, '10.44.')
            || ($spec['pi_host'] ?? null) !== $workspace->node->wireguard_ip
            || ! is_int($spec['pi_port'] ?? null) || $spec['pi_port'] < 20000 || $spec['pi_port'] > 60999) {
            throw new ComputeException('compute.pi_unavailable', 'The sandbox Pi network reservation is unavailable.');
        }

        return ['sandbox_id' => $sandbox->id, 'address' => '10.233.'.$match[1].'.10', 'bridge' => '10.233.'.$match[1].'.1', 'gateway' => $gateway];
    }

    /** @return array{model_relay_address: ?string, model_relay_kind: string, model_relay_port: int} */
    private function relay(TaskSandbox $sandbox): array
    {
        if (! isset($sandbox->spec['model_proxy_origin'])) {
            return ['model_relay_address' => null, 'model_relay_kind' => 'incus', 'model_relay_port' => 8317];
        }
        $subnet = $sandbox->spec['subnet'] ?? null;
        if ($sandbox->provider !== 'incus' || ! is_string($subnet)
            || preg_match('/\A10\.233\.([0-9]{1,3})\.0\/24\z/D', $subnet, $match) !== 1 || (int) $match[1] > 255
            || $sandbox->spec['model_proxy_origin'] !== $sandbox->model_proxy_origin) {
            throw new ComputeException('compute.model_proxy_unconfirmed', 'The model relay reservation is unavailable.');
        }

        return ['model_relay_address' => '10.233.'.$match[1].'.1', 'model_relay_kind' => 'incus', 'model_relay_port' => 8317];
    }
}
