<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\VmObservation;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\TaskVm;
use JsonException;

/**
 * Runs task VMs on an Incus host Node with `sudo -n incus --project <project> …` over SSH, as the
 * host's managed user. `incus-host.sh` prepared the project, the bridge with its ACL, the default
 * profile and the image. The provider never creates a network: it pins the VM's profile NIC to the
 * host's configured bridge. This class is the one place that validates Incus output.
 */
final readonly class IncusTaskVmProvider implements TaskVmProvider
{
    private const string FingerprintPattern = '/\A256 (SHA256:[A-Za-z0-9+\/]{43}) .* \(ED25519\)\n?\z/D';

    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private TaskVmSettings $settings,
    ) {}

    public function create(TaskVm $vm, string $userData): void
    {
        if ($this->observe($vm) instanceof VmObservation) {
            return;
        }

        $host = $this->settings->host($vm->host_node_id);
        // `incus launch` reads instance config as YAML from a stdin that is not a terminal. JSON is YAML.
        $result = $this->incus($vm, [
            'launch', $host->image, $vm->name, '--vm',
            '--config', "limits.cpu={$host->cpus}", '--config', "limits.memory={$host->memory}",
            '--device', "root,size={$host->disk}", '--device', "eth0,network={$host->network}",
        ], json_encode(['config' => ['cloud-init.user-data' => $userData]], JSON_THROW_ON_ERROR));

        // A launch whose answer was lost still created the VM.
        if (! $result->succeeded() && ! $this->observe($vm) instanceof VmObservation) {
            throw $this->failed($vm, 'launch', $result);
        }
    }

    public function observe(TaskVm $vm): ?VmObservation
    {
        $result = $this->incus($vm, ['list', $vm->name, '--format', 'json']);
        if (! $result->succeeded()) {
            throw $this->failed($vm, 'list', $result);
        }

        $instances = $this->json($vm, $result);
        if (! array_is_list($instances)) {
            throw $this->invalid($vm, 'the instance list is not a JSON list');
        }
        // The name argument is a prefix filter, so only an exact name counts.
        $matches = array_values(array_filter($instances, static fn (mixed $instance): bool => is_array($instance) && ($instance['name'] ?? null) === $vm->name));
        if ($matches === []) {
            return null;
        }

        $instance = $matches[0];
        $status = $instance['status'] ?? null;
        if (count($matches) !== 1 || ($instance['type'] ?? null) !== 'virtual-machine' || ! in_array($status, ['Running', 'Stopped'], true)) {
            throw $this->invalid($vm, 'the instance is not one virtual machine that is running or stopped');
        }

        return new VmObservation($status === 'Running', $this->address($vm, $instance['state']['network'] ?? null));
    }

    public function bootstrapReady(TaskVm $vm): bool
    {
        $result = $this->incus($vm, ['exec', $vm->name, '--', 'cloud-init', 'status', '--format=json']);
        // Until the guest agent runs, `incus exec` fails and prints nothing on stdout.
        if (! $result->succeeded() && trim($result->stdout) === '') {
            return false;
        }

        // `cloud-init status` exits 1 or 2 after errors, but still prints its JSON.
        $status = $this->json($vm, $result);
        $errors = $status['errors'] ?? null;
        if (! is_string($status['status'] ?? null) || ! is_array($errors) || ! array_is_list($errors)) {
            throw $this->invalid($vm, 'the cloud-init status has no status or errors');
        }
        if ($status['status'] === 'error') {
            throw new TaskVmException('task_vm.bootstrap_failed', "Cloud-init failed on task VM [{$vm->name}]: ".mb_substr(json_encode($errors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 0, 2000), 502);
        }

        return $status['status'] === 'done' && $errors === [];
    }

    public function sshHostFingerprint(TaskVm $vm): string
    {
        $result = $this->incus($vm, ['exec', $vm->name, '--', 'ssh-keygen', '-l', '-f', '/etc/ssh/ssh_host_ed25519_key.pub']);
        if (! $result->succeeded()) {
            throw $this->failed($vm, 'exec ssh-keygen', $result);
        }
        if (preg_match(self::FingerprintPattern, $result->stdout, $match) !== 1) {
            throw $this->invalid($vm, 'the host key fingerprint is not an ed25519 SHA256 fingerprint');
        }

        return $match[1];
    }

    public function destroy(TaskVm $vm): void
    {
        $result = $this->incus($vm, ['delete', $vm->name, '--force']);

        if (! $result->succeeded() && $this->observe($vm) instanceof VmObservation) {
            throw $this->failed($vm, 'delete', $result);
        }
    }

    /** The guest's one IPv4 address on the bridge, or null before it has one. */
    private function address(TaskVm $vm, mixed $network): ?string
    {
        $host = $this->settings->host($vm->host_node_id);
        $bridge = Ipv4Subnet::from($host->cidr);
        $found = [];
        foreach (is_array($network) ? $network : [] as $interface) {
            foreach (is_array($interface) && is_array($interface['addresses'] ?? null) ? $interface['addresses'] : [] as $address) {
                if (is_array($address) && ($address['family'] ?? null) === 'inet' && is_string($address['address'] ?? null) && $bridge->contains($address['address'])) {
                    $found[] = $address['address'];
                }
            }
        }

        if (count($found) > 1 || ($found !== [] && ! $host->isGuestAddress($found[0]))) {
            throw $this->invalid($vm, 'the VM needs exactly one guest IPv4 address in ['.$host->cidr.']');
        }

        return $found[0] ?? null;
    }

    /** @param  list<string>  $arguments */
    private function incus(TaskVm $vm, array $arguments, ?string $input = null): CommandResult
    {
        $project = $this->settings->host($vm->host_node_id)->project;
        $node = $vm->hostNode;

        return $this->ssh->execute(
            new SshConnection(
                host: $node->wireguard_ip ?? throw new TaskVmException('task_vm.unknown_host', "Host Node [{$node->name}] has no WireGuard address."),
                user: $node->user,
                port: 22,
                identityFile: $this->keys->privateKeyPath(),
                knownHostsFile: $this->knownHosts->path(),
            ),
            new RemoteCommand(['sudo', '-n', 'incus', '--project', $project, ...$arguments], $input, maxOutputBytes: 1_048_576),
        );
    }

    /** @return array<mixed> */
    private function json(TaskVm $vm, CommandResult $result): array
    {
        try {
            $value = $result->truncated ? null : json_decode($result->stdout, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $value = null;
        }

        return is_array($value) ? $value : throw $this->invalid($vm, 'the output is not complete JSON');
    }

    private function invalid(TaskVm $vm, string $reason): TaskVmException
    {
        return new TaskVmException('task_vm.invalid_host_output', "Incus on the host of task VM [{$vm->name}] returned invalid output: {$reason}.", 502);
    }

    private function failed(TaskVm $vm, string $operation, CommandResult $result): TaskVmException
    {
        $detail = implode("\n", array_slice(explode("\n", trim($result->stderr)), -5));

        return new TaskVmException('task_vm.host_command_failed', "`incus {$operation}` for task VM [{$vm->name}] failed with exit code [{$result->exitCode}].".($detail === '' ? '' : "\n{$detail}"), 502);
    }
}
