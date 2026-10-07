<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Compute\ComputeException;
use App\Infrastructure\Compute\SandboxFleetIdentity;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use Throwable;

/** Publish one pinned Linux x64 executable to an enrolled, owned guest. */
final readonly class SandboxPiArtifact
{
    private const int MaxBytes = 268435456;

    public function __construct(private TaskWorkspaceExecutor $guest, private SandboxFleetIdentity $identity) {}

    public function prepare(Instance $workspace): void
    {
        try {
            $sandbox = $workspace->taskSandbox?->fresh();
            if ($sandbox === null || $sandbox->node_id !== $workspace->node_id) {
                throw new ComputeException('compute.pi_unavailable', 'The Pi artifact has no owned guest.');
            }
            $this->identity->assertReady($sandbox, $workspace->node);
            [$path, $digest, $size] = $this->descriptor();
            $program = file_get_contents(resource_path('compute/guest-pi-artifact.py'));
            if (! is_string($program)) {
                throw new ComputeException('compute.pi_unavailable', 'The Pi artifact program is unavailable.');
            }
            $header = json_encode(['sandbox_id' => $sandbox->id, 'sha256' => $digest, 'size' => $size], JSON_THROW_ON_ERROR)."\n";
            $result = $this->guest->execute($workspace, new RemoteCommand(['sudo', '-n', 'python3', '-I', '-c', $program],
                protectedInput: ProtectedInput::fromFile($path, $header, self::MaxBytes), timeout: 180, maxOutputBytes: 8192), 'sandbox-pi-artifact', 'tasks.pi_setup_failed');
            $data = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
            if ($result->truncated || ! is_array($data) || ($data['sandbox_id'] ?? null) !== $sandbox->id || ($data['sha256'] ?? null) !== $digest) {
                throw new ComputeException('compute.pi_unavailable', 'The Pi artifact was not confirmed.');
            }
        } catch (Throwable) {
            throw new ComputeException('compute.pi_unavailable', 'The pinned Pi artifact could not be installed in its owned guest.');
        }
    }

    public function assertConfigured(): void
    {
        $this->descriptor();
    }

    /** @return array{string, string, int} */
    private function descriptor(): array
    {
        $path = config('compute.pi.artifact_path');
        $digest = config('compute.pi.artifact_sha256');
        $details = is_string($path) ? lstat($path) : false;
        if (! is_string($path) || ! is_string($digest) || preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || $details === false || ($details['mode'] & 0o170000) !== 0o100000 || ($details['mode'] & 0o022) !== 0
            || $details['uid'] !== posix_geteuid() || $details['nlink'] !== 1
            || $details['size'] < 64 || $details['size'] > self::MaxBytes || hash_file('sha256', $path) !== $digest) {
            throw new ComputeException('compute.pi_unavailable', 'Configure a safe pinned Pi artifact on the Gateway.');
        }

        return [$path, $digest, $details['size']];
    }
}
