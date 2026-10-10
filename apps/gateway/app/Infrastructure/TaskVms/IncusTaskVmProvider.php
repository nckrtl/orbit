<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\VmObservation;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Infrastructure\Processes\CommandResult;
use App\Models\TaskVm;

/**
 * Runs task VMs on an Incus host Node with `sudo -n incus --project <project> …` over SSH, as the
 * host's managed user. `incus-host.sh` prepared the project, the bridge with its ACL and the default
 * profile, and `IncusTaskVmImageBuilder` the base image that every VM launches. The provider never
 * creates a network: it pins the VM's profile NIC to the host's configured bridge with port isolation.
 * This class validates the instance output of Incus. Every VM name follows `--`, so no name can be
 * read as an option.
 */
final readonly class IncusTaskVmProvider implements TaskVmProvider
{
    private const string FingerprintPattern = '/\A256 (SHA256:[A-Za-z0-9+\/]{43}) .* \(ED25519\)\n?\z/D';

    public function __construct(
        private IncusHost $incus,
        private TaskVmSettings $settings,
    ) {}

    public function create(TaskVm $vm, string $userData): void
    {
        $observation = $this->observe($vm);
        if ($observation instanceof VmObservation) {
            // A stopped VM is one whose launch created it but did not start it.
            $this->start($vm, $observation);

            return;
        }

        $host = $this->settings->host($vm->host_node_id);
        $result = $this->incus->launch($host, $vm->hostNode, $vm->name, TaskVmHost::BaseImage, $userData);

        // Only a launch whose answer was lost and whose VM runs counts: a VM that did not start keeps the launch error.
        if (! $result->succeeded() && $this->observe($vm)?->running !== true) {
            // A missing base image is a setup step, not a launch problem, and the stock image would never enroll.
            if ($this->incus->aliasTarget($host, $vm->hostNode, TaskVmHost::BaseImage) === null) {
                throw new TaskVmException('task_vm.base_image_missing', "The Incus project [{$host->project}] on the host of task VM [{$vm->name}] has no base image [".TaskVmHost::BaseImage.']. Build it with task-vms:build-image.');
            }

            throw $this->failed($vm, 'launch', $result);
        }
    }

    /** @phpstan-impure */
    public function observe(TaskVm $vm): ?VmObservation
    {
        $result = $this->incus($vm, ['list', '--format', 'json', '--', $vm->name]);
        if (! $result->succeeded()) {
            throw $this->failed($vm, 'list', $result);
        }

        $instances = IncusHost::json("task VM [{$vm->name}]", $result);
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

        $state = $instance['state'] ?? null;

        return new VmObservation($status === 'Running', $this->address($vm, is_array($state) ? $state['network'] ?? null : null));
    }

    public function bootstrapReady(TaskVm $vm): bool
    {
        $result = $this->incus($vm, ['exec', '--', $vm->name, 'cloud-init', 'status', '--format=json']);
        // Until the guest agent runs, `incus exec` fails and prints nothing on stdout.
        if (! $result->succeeded() && trim($result->stdout) === '') {
            return false;
        }

        // `cloud-init status` exits 1 or 2 after errors, but still prints its JSON.
        $status = IncusHost::json("task VM [{$vm->name}]", $result);
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
        $result = $this->incus($vm, ['exec', '--', $vm->name, 'ssh-keygen', '-l', '-f', '/etc/ssh/ssh_host_ed25519_key.pub']);
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
        $result = $this->incus($vm, ['delete', '--force', '--', $vm->name]);

        if (! $result->succeeded() && $this->observe($vm) instanceof VmObservation) {
            throw $this->failed($vm, 'delete', $result);
        }
    }

    private function start(TaskVm $vm, VmObservation $observation): void
    {
        if ($observation->running) {
            return;
        }

        $result = $this->incus($vm, ['start', '--', $vm->name]);
        // A guest reboot shows the VM stopped for a moment, so a VM that runs now counts as started.
        if (! $result->succeeded() && $this->observe($vm)?->running !== true) {
            throw $this->failed($vm, 'start', $result);
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
    private function incus(TaskVm $vm, array $arguments): CommandResult
    {
        return $this->incus->run($this->settings->host($vm->host_node_id), $vm->hostNode, $arguments);
    }

    private function invalid(TaskVm $vm, string $reason): TaskVmException
    {
        return IncusHost::invalid("task VM [{$vm->name}]", $reason);
    }

    private function failed(TaskVm $vm, string $operation, CommandResult $result): TaskVmException
    {
        return IncusHost::failed("task VM [{$vm->name}]", $operation, $result);
    }
}
