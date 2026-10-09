<?php

declare(strict_types=1);

namespace App\Infrastructure\Compute;

use App\Actions\Nodes\ProvisionNodeAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Compute\ComputeException;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Tasks\IncusSandboxHost;
use App\Models\Node;
use App\Models\TaskSandbox;
use Throwable;

/** Pin the guest key through its owned Incus host, never through an SSH scan. */
final readonly class IncusSandboxNodeBootstrap
{
    public function __construct(private IncusSandboxHost $transport, private TaskSandboxDrivers $drivers,
        private SandboxFleetIdentity $identity, private KnownHostsStore $hosts, private ProvisionNodeAction $nodes) {}

    public function identity(TaskSandbox $sandbox): HostKey
    {
        $bootstrap = $this->identity->incusBootstrap($sandbox);
        $hostId = $sandbox->spec['host_id'];
        $host = is_int($hostId) ? Node::query()->find($hostId) : null;
        $settings = array_find($this->drivers->localHosts(), fn (array $candidate): bool => $candidate['node_id'] === $hostId);
        if (! $host instanceof Node || $settings === null || $settings['project'] !== $sandbox->spec['project'] || $host->wireguard_ip !== $bootstrap['ssh_host']) {
            throw new ComputeException('compute.host_unconfigured', 'Restore the recorded Project host before enrollment.');
        }
        try {
            return $sandbox->enrollment === null
                ? $this->transport->projectIdentity($host, $sandbox, $settings['max_vms'])
                : $this->transport->projectFleetIdentity($host, $sandbox, $settings['max_vms']);
        } catch (Throwable) {
            throw new ComputeException('compute.bootstrap_not_ready', 'The owned Project guest identity could not be confirmed.');
        }
    }

    public function prepare(TaskSandbox $sandbox, Node $node): void
    {
        $this->identity->assertOwned($sandbox, $node);
        $key = $this->identity($sandbox);
        if (! is_string($node->wireguard_ip) || $node->ssh_host_key_type !== $key->type || $node->ssh_host_key !== $key->value || $node->ssh_host_fingerprint !== $key->fingerprint) {
            throw new ComputeException('compute.ownership_mismatch', 'The recorded Project SSH identity changed.');
        }
        $this->hosts->put($node->public_ssh_host, $node->public_ssh_port, $key);
        $this->hosts->put($node->wireguard_ip, 22, $key);
    }

    public function enroll(TaskSandbox $sandbox, Node $node): Node
    {
        return $this->nodes->executeSandbox(new ProvisionNodeData(name: $node->name, publicSshHost: $node->public_ssh_host,
            roles: [RoleName::AppDev], publicSshPort: $node->public_ssh_port, user: 'orbit', orbitUser: 'orbit', wireguardIp: $node->wireguard_ip,
            expectedSshHostFingerprint: $node->ssh_host_fingerprint, architecture: 'x86_64', clusterId: $node->cluster_id, clusterProvided: true), $sandbox);
    }
}
