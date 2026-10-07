<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\SandboxHostOperation;
use App\Infrastructure\Nodes\NodeAgentFootprint;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

/** The host's agent accepts only the closed sandbox protocol on standard input. */
final readonly class IncusSandboxHost
{
    public function __construct(private SshExecutor $ssh, private SshKeyProvider $keys, private KnownHostsStore $knownHosts) {}

    /**
     * @param  array<string, mixed>|null  $spec
     * @return array<string, mixed>
     */
    public function execute(Node $host, SandboxHostOperation $operation, string $project, string $sandboxId, int $budget, ?array $spec = null): array
    {
        if (! $host->exists || $host->status !== LifecycleStatus::Active || $host->platform !== 'linux'
            || ! is_string($host->wireguard_ip) || filter_var($host->wireguard_ip, FILTER_VALIDATE_IP) === false
            || ! Str::isUuid($sandboxId) || strtolower($sandboxId) !== $sandboxId
            || preg_match('/\Aorbit-(?:task-sandboxes|sandbox-proof-[a-z0-9]+)\z/D', $project) !== 1
            || $budget < 1 || $budget > 64 || ($operation === SandboxHostOperation::Provision) !== ($spec !== null)) {
            throw new ResourceOperationException('compute.invalid_host_request', 'The sandbox host request is invalid.', 409);
        }
        $request = json_encode([
            'operation' => $operation->value, 'project' => $project, 'sandbox_id' => $sandboxId,
            'budget' => $budget, ...($spec === null ? [] : ['spec' => $spec]),
        ], JSON_THROW_ON_ERROR);
        if (strlen($request) > 1024 * 1024) {
            throw new ResourceOperationException('compute.invalid_host_request', 'The sandbox host request is too large.', 409);
        }
        try {
            $result = $this->ssh->execute(new SshConnection(
                host: $host->wireguard_ip, user: $host->user, port: 22,
                identityFile: $this->keys->privateKeyPath(), knownHostsFile: $this->knownHosts->path(),
                commandTimeout: 900,
            ), new RemoteCommand(
                [NodeAgentFootprint::BinaryPath, 'sandbox'],
                protectedInput: ProtectedInput::fromString($request), maxOutputBytes: 65536, timeout: 900,
            ));
        } catch (Throwable) {
            throw new ResourceOperationException('compute.host_unavailable', 'The sandbox host did not return a result.', 502);
        }
        if (! $result->succeeded() || $result->truncated) {
            throw new ResourceOperationException('compute.host_refused', 'The sandbox host refused the operation; inspect sandbox ownership and capacity.', 502);
        }
        try {
            $data = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = null;
        }
        if (! is_array($data) || array_is_list($data) || isset($data['error'])) {
            throw new ResourceOperationException('compute.invalid_host_response', 'The sandbox host returned an invalid result.', 502);
        }
        if ($operation === SandboxHostOperation::Capacity) {
            if (! is_int($data['available'] ?? null) || ! is_int($data['used'] ?? null)
                || $data['used'] < 0 || ($data['budget'] ?? null) !== $budget
                || $data['available'] !== max(0, $budget - $data['used'])) {
                throw new ResourceOperationException('compute.invalid_host_response', 'The sandbox host returned invalid capacity.', 502);
            }

            return ['available' => $data['available'], 'used' => $data['used'], 'budget' => $budget];
        }
        $name = 'ot-'.substr(hash('sha256', $sandboxId), 0, 10);
        if (($data['name'] ?? null) !== $name || ! in_array($data['power'] ?? null, ['running', 'stopped', 'destroyed'], true)
            || ! is_array($data['instances'] ?? null) || ! array_is_list($data['instances']) || count($data['instances']) > 5) {
            throw new ResourceOperationException('compute.invalid_host_response', 'The sandbox host returned invalid ownership or power.', 502);
        }
        $names = [];
        foreach ($data['instances'] as $instance) {
            if (! is_array($instance) || ! is_string($instance['name'] ?? null)
                || ! in_array($instance['name'], array_map(fn (string $role): string => $name.'-'.$role, ['operator', 'gateway', 'app-dev', 'app-prod', 'app-prod-2']), true)
                || ! in_array($instance['state'] ?? null, ['running', 'stopped', 'starting', 'stopping', 'error', 'frozen'], true)
                || in_array($instance['name'], $names, true)) {
                throw new ResourceOperationException('compute.invalid_host_response', 'The sandbox host returned an invalid guest.', 502);
            }
            $names[] = $instance['name'];
        }
        if (($data['power'] === 'destroyed') !== ($names === [])) {
            throw new ResourceOperationException('compute.invalid_host_response', 'The sandbox host returned inconsistent power.', 502);
        }

        return ['name' => $name, 'power' => $data['power'], 'instances' => $data['instances']];
    }
}
