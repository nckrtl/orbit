<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Ssh\HostKey;
use App\Models\Instance;
use App\Models\Node;

final class IncusRuntimeWorkspace
{
    public static function key(): HostKey
    {
        $value = 'AAAAC3NzaC1lZDI1NTE5AAAAIHdUmJNAeflz28V7EadKJL3DLqnMqS6JyEQJmpCPNG5T';

        return new HostKey('ssh-ed25519', $value, 'SHA256:'.rtrim(base64_encode(hash('sha256', base64_decode($value), true)), '='));
    }

    public static function create(): Instance
    {
        $workspace = UpCloudRuntimeWorkspace::create();
        $sandbox = $workspace->taskSandbox;
        $host = Node::query()->create(['name' => 'compute', 'public_ssh_host' => '93.184.216.40', 'wireguard_ip' => '10.44.0.20', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'status' => 'active']);
        $spec = ['host_id' => $host->id, 'project' => 'orbit-sandbox-proof-318a36c8', 'pool' => 'proof', 'project_slug' => 'dlf',
            'images' => ['operator' => str_repeat('a', 64)], 'subnet' => '10.233.201.0/24', 'blocked_networks' => ['192.168.0.0/16'],
            'project_bootstrap' => ['ssh_host' => $host->wireguard_ip, 'ssh_port' => 24201, 'gateway_address' => '10.44.0.2',
                'wireguard_address' => '93.184.216.35', 'wireguard_port' => 51820]];
        $key = self::key();
        $name = 'ot-'.substr(hash('sha256', $sandbox->id), 0, 10);
        $workspace->node->update(['name' => $name, 'public_ssh_host' => $host->wireguard_ip, 'public_ssh_port' => 24201,
            'ssh_host_key_type' => $key->type, 'ssh_host_key' => $key->value, 'ssh_host_fingerprint' => $key->fingerprint]);
        $sandbox->forceFill(['name' => $name, 'provider' => 'incus', 'server_id' => null, 'disk_id' => null, 'public_address' => null,
            'spec' => $spec, 'enrollment' => [...$sandbox->enrollment, 'public_address' => $host->wireguard_ip, 'ssh_port' => 24201,
                'incus_spec' => $spec, 'ssh_key_type' => $key->type, 'ssh_key' => $key->value, 'ssh_fingerprint' => $key->fingerprint]])->save();
        config(['compute.incus.enabled' => true, 'compute.incus.enrollment_enabled' => true, 'compute.incus.project_workspaces_enabled' => true,
            'compute.incus.dev_cluster_id' => $workspace->node->cluster_id, 'compute.incus.model_address' => '10.44.0.3', 'compute.incus.model_port' => 8317,
            'compute.incus.hosts' => [['node_id' => $host->id, 'project' => $spec['project'], 'pool' => 'proof', 'max_vms' => 9,
                'blocked_networks' => $spec['blocked_networks'], 'gateway_address' => '10.44.0.2', 'project_images' => ['dlf' => str_repeat('a', 64)],
                'project_bootstrap' => ['wireguard_address' => '93.184.216.35', 'wireguard_port' => 51820]]]]);

        return $workspace->fresh();
    }
}
