<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxState;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use Throwable;

/** Install only sandbox credentials; the model relay and binary belong to image/host preparation. */
final readonly class SandboxPiRuntime
{
    public function __construct(private TaskWorkspaceExecutor $guest) {}

    public function prepare(Instance $workspace): void
    {
        $sandbox = $workspace->taskSandbox?->fresh();
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
            $request = ['sandbox_id' => $sandbox->id, 'checkout' => $workspace->checkout_path, 'pi_token' => $sandbox->pi_token,
                'model_key' => $sandbox->model_key, 'models' => config('compute.pi.models', [])];
            $result = $this->guest->execute($workspace, new RemoteCommand(['sudo', '-n', 'python3', '-I', '-c', $program],
                protectedInput: ProtectedInput::fromString(json_encode($request, JSON_THROW_ON_ERROR)), timeout: 90, maxOutputBytes: 8192),
                'sandbox-pi', 'tasks.pi_setup_failed');
            $data = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
            if ($result->truncated || ! is_array($data) || ($data['sandbox_id'] ?? null) !== $sandbox->id || ($data['ready'] ?? null) !== true) {
                throw new ComputeException('compute.pi_unavailable', 'The sandbox runtime did not confirm readiness.');
            }
        } catch (Throwable) {
            // The guest can echo either key, including in malformed output. Do not retain its exception.
            throw new ComputeException('compute.pi_unavailable', 'The sandbox runtime did not confirm readiness.');
        }
    }
}
