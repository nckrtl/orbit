<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\SandboxHostOperation;
use App\Infrastructure\Nodes\NodeAgentFootprint;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Support\ValidatedData;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

/** The host's agent accepts only the closed sandbox protocol on standard input. */
final readonly class IncusSandboxHost
{
    public function __construct(private SshExecutor $ssh, private SshKeyProvider $keys, private KnownHostsStore $knownHosts) {}

    /**
     * @param  array<string, mixed>|null  $spec
     * @param  array<string, mixed>|null  $guest
     * @return array<string, mixed>
     */
    public function execute(Node $host, SandboxHostOperation $operation, string $project, string $sandboxId, int $budget, ?array $spec = null, ?array $guest = null): array
    {
        if (! $host->exists || $host->status !== LifecycleStatus::Active || $host->platform !== 'linux'
            || ! is_string($host->wireguard_ip) || filter_var($host->wireguard_ip, FILTER_VALIDATE_IP) === false
            || ! Str::isUuid($sandboxId) || strtolower($sandboxId) !== $sandboxId
            || preg_match('/\Aorbit-(?:task-sandboxes|sandbox-proof-[a-z0-9]+)\z/D', $project) !== 1
            || $budget < 1 || $budget > 64 || ($operation === SandboxHostOperation::Provision) !== ($spec !== null)
            || ($operation === SandboxHostOperation::GuestCommand) !== ($guest !== null)) {
            throw new ResourceOperationException('compute.invalid_host_request', 'The sandbox host request is invalid.', 409);
        }
        if ($guest !== null) {
            $guest = $this->validateGuest($guest);
        }
        $request = json_encode([
            'operation' => $operation->value, 'project' => $project, 'sandbox_id' => $sandboxId,
            'budget' => $budget, ...($spec === null ? [] : ['spec' => $spec]), ...($guest === null ? [] : ['guest' => $guest]),
        ], JSON_THROW_ON_ERROR);
        if (strlen($request) > 1024 * 1024) {
            throw new ResourceOperationException('compute.invalid_host_request', 'The sandbox host request is too large.', 409);
        }
        $timeout = $guest === null ? 900 : $guest['timeout'] + 30;
        try {
            $result = $this->ssh->execute(new SshConnection(
                host: $host->wireguard_ip, user: $host->user, port: 22,
                identityFile: $this->keys->privateKeyPath(), knownHostsFile: $this->knownHosts->path(),
                commandTimeout: $timeout,
            ), new RemoteCommand(
                [NodeAgentFootprint::BinaryPath, 'sandbox'],
                protectedInput: ProtectedInput::fromString($request), maxOutputBytes: $guest === null ? 65536 : 12 * 1024 * 1024, timeout: $timeout,
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
        if ($guest !== null) {
            return $this->guestResponse(ValidatedData::object($data), $guest, $name);
        }
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

    public function executeGuest(Node $host, string $project, string $sandboxId, int $budget, RemoteCommand $command, string $role = 'operator'): CommandResult
    {
        if ($command->output !== null || $command->cancelled !== null || ($command->input !== null && $command->protectedInput !== null)) {
            throw new ResourceOperationException('compute.invalid_guest_request', 'The guest command options are unsupported.', 409);
        }
        $input = $command->protectedInput === null ? ($command->input ?? '') : stream_get_contents($command->protectedInput->stream(), 512 * 1024 + 1);
        if ($input === false || strlen($input) > 512 * 1024) {
            throw new ResourceOperationException('compute.invalid_guest_request', 'The guest command input is too large.', 409);
        }
        $guest = [
            'role' => $role, 'argv' => $command->arguments, 'stdin' => base64_encode($input),
            'timeout' => (int) ceil($command->timeout ?? 900), 'max_output' => $command->maxOutputBytes ?? 65536,
        ];
        $data = $this->execute($host, SandboxHostOperation::GuestCommand, $project, $sandboxId, $budget, guest: $guest);

        return new CommandResult(ValidatedData::integer($data['exit_code']), base64_decode(ValidatedData::string($data['stdout'])), base64_decode(ValidatedData::string($data['stderr'])), ValidatedData::integer($data['duration_ms']), $data['truncated'] === true);
    }

    /**
     * @param  array<string, mixed>  $guest
     * @return array{role: string, argv: list<string>, stdin: string, timeout: int, max_output: int}
     */
    private function validateGuest(array $guest): array
    {
        if (array_diff(array_keys($guest), ['role', 'argv', 'stdin', 'timeout', 'max_output']) !== []
            || ! is_string($guest['role'] ?? null) || ! in_array($guest['role'], ['operator', 'gateway', 'app-dev', 'app-prod', 'app-prod-2'], true)
            || ! is_array($guest['argv'] ?? null) || ! array_is_list($guest['argv']) || count($guest['argv']) < 1 || count($guest['argv']) > 128
            || ! is_string($guest['stdin'] ?? null) || ! is_int($guest['timeout'] ?? null) || $guest['timeout'] < 1 || $guest['timeout'] > 900
            || ! is_int($guest['max_output'] ?? null) || $guest['max_output'] < 1 || $guest['max_output'] > 8 * 1024 * 1024) {
            throw new ResourceOperationException('compute.invalid_guest_request', 'The guest command is invalid.', 409);
        }
        $arguments = [];
        foreach ($guest['argv'] as $argument) {
            if (! is_string($argument) || str_contains($argument, "\0") || strlen($argument) > 65536) {
                throw new ResourceOperationException('compute.invalid_guest_request', 'The guest command is invalid.', 409);
            }
            $arguments[] = $argument;
        }
        $input = base64_decode($guest['stdin'], true);
        if ($arguments[0] === '' || $input === false || strlen($input) > 512 * 1024) {
            throw new ResourceOperationException('compute.invalid_guest_request', 'The guest command is invalid.', 409);
        }

        return ['role' => $guest['role'], 'argv' => $arguments, 'stdin' => $guest['stdin'], 'timeout' => $guest['timeout'], 'max_output' => $guest['max_output']];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $guest
     * @return array{exit_code: int, stdout: string, stderr: string, duration_ms: int, truncated: bool}
     */
    private function guestResponse(array $data, array $guest, string $name): array
    {
        if (($data['name'] ?? null) !== $name || ($data['role'] ?? null) !== $guest['role']
            || ! is_int($data['exit_code'] ?? null) || $data['exit_code'] < -255 || $data['exit_code'] > 255
            || ! is_int($data['duration_ms'] ?? null) || $data['duration_ms'] < 0
            || ! is_bool($data['truncated'] ?? null) || ! is_bool($data['timed_out'] ?? null)
            || ! is_string($data['stdout'] ?? null) || ! is_string($data['stderr'] ?? null)
            || ($stdout = base64_decode($data['stdout'], true)) === false || ($stderr = base64_decode($data['stderr'], true)) === false
            || strlen($stdout) + strlen($stderr) > $guest['max_output'] || ($data['timed_out'] && $data['exit_code'] !== 124)) {
            throw new ResourceOperationException('compute.invalid_guest_response', 'The sandbox guest returned an invalid result.', 502);
        }

        return ['exit_code' => $data['exit_code'], 'stdout' => $data['stdout'], 'stderr' => $data['stderr'], 'duration_ms' => $data['duration_ms'], 'truncated' => $data['truncated']];
    }
}
