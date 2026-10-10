<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;

beforeEach(function (): void {
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24');
    config()->set('task_vms.wireguard_range', '10.44.0.128/25');
    config()->set('task_vms.model_proxy_origin', 'http://10.44.0.3:8317');
    $this->ssh = new AppDevFakeSshExecutor([new CommandResult(0, "{\"ok\":true}\n", 'Rule added', 1, false)]);
    app()->instance(SshExecutor::class, $this->ssh);
});

describe('task-vms:prepare-host', function (): void {
    it('runs the host script as root with arguments from the host settings', function (): void {
        $beast = task_vm_fleet_node('beast', '10.44.0.7', [RoleName::AppDev]);
        config()->set('task_vms.incus.hosts', [[
            'node_id' => $beast->id,
            'cidr' => '10.252.0.0/24',
            'pool' => 'orbit-e2e',
            'max_vms' => 4,
        ]]);

        $this->artisan('task-vms:prepare-host', ['node' => 'beast'])
            ->expectsOutputToContain('Node [beast] is ready for task VMs on bridge [orbittask0]. Build its base image with task-vms:build-image.')
            ->assertSuccessful();

        $command = $this->ssh->commands[0];
        expect($this->ssh->connections[0]->host)->toBe('10.44.0.7')
            ->and($this->ssh->connections[0]->user)->toBe('nckrtl')
            ->and($command->arguments)->toBe([
                'sudo', '-n', 'bash', '-s', '--', 'orbit-tasks', 'orbittask0', '10.252.0.0/24', 'orbit-e2e', 'ubuntu-26.04-vm',
            ])
            ->and($command->input)->toBe(file_get_contents(resource_path('task-vms/incus-host.sh')));
    });

    it('passes the ZFS dataset that creates a missing pool', function (): void {
        $beast = task_vm_fleet_node('beast', '10.44.0.7', [RoleName::AppDev]);
        config()->set('task_vms.incus.hosts', [['node_id' => $beast->id, 'cidr' => '10.252.0.0/24', 'max_vms' => 4, 'zfs_dataset' => 'fast/orbit-tasks']]);

        $this->artisan('task-vms:prepare-host', ['node' => 'beast'])->assertSuccessful();

        expect($this->ssh->commands[0]->arguments)->toBe([
            'sudo', '-n', 'bash', '-s', '--', 'orbit-tasks', 'orbittask0', '10.252.0.0/24', 'orbit-tasks', 'ubuntu-26.04-vm', 'fast/orbit-tasks',
        ]);
    });

    it('refuses invalid host settings before it connects', function (array $host, string $message): void {
        $beast = task_vm_fleet_node('beast', '10.44.0.7', [RoleName::AppDev]);
        config()->set('task_vms.incus.hosts', [['node_id' => $beast->id, 'cidr' => '10.252.0.0/24', 'max_vms' => 4, ...$host]]);

        $this->artisan('task-vms:prepare-host', ['node' => (string) $beast->id])
            ->expectsOutputToContain($message)
            ->assertFailed();

        expect($this->ssh->commands)->toBe([]);
    })->with([
        'network outside the reserved prefix' => [['network' => 'incusbr0'], '[task_vm.invalid_config] The task VM config is invalid: incus.hosts.0.network is invalid.'],
        'cidr overlapping the fleet' => [['cidr' => '10.44.0.0/16'], 'incus.hosts.0.cidr must not overlap the VPN subnet [10.44.0.0/24].'],
    ]);

    it('refuses a Node that is not a configured task VM host', function (): void {
        task_vm_fleet_node('shark', '10.44.0.11', [RoleName::AppDev]);

        $this->artisan('task-vms:prepare-host', ['node' => 'shark'])
            ->expectsOutputToContain('[task_vm.unknown_host]')
            ->assertFailed();

        expect($this->ssh->commands)->toBe([]);
    });

    it('fails when the script does not confirm', function (): void {
        $beast = task_vm_fleet_node('beast', '10.44.0.7', [RoleName::AppDev]);
        config()->set('task_vms.incus.hosts', [['node_id' => $beast->id, 'cidr' => '10.252.0.0/24', 'max_vms' => 4]]);
        $this->ssh = new AppDevFakeSshExecutor([new CommandResult(1, '', "incus-host: ufw is not installed\n", 1, false)]);
        app()->instance(SshExecutor::class, $this->ssh);

        $this->artisan('task-vms:prepare-host', ['node' => 'beast'])
            ->expectsOutputToContain("[task_vm.setup_failed] [incus-host.sh] failed on Node [beast] with exit code [1].\nincus-host: ufw is not installed")
            ->assertFailed();
    });
});

describe('task-vms:prepare-hub', function (): void {
    beforeEach(function (): void {
        $this->hub = task_vm_fleet_node('vpn', '10.44.0.1', [RoleName::Vpn], user: 'orbit');
        task_vm_fleet_node('gateway', '10.44.0.2', [RoleName::Gateway]);
        task_vm_fleet_node('services', '10.44.0.3', [RoleName::WebSocket, RoleName::AppDev]);
        $cluster = Cluster::query()->create(['name' => 'development']);
        $router = task_vm_fleet_node('beast', '10.44.0.7', [RoleName::AppDev], cluster: $cluster);
        NodeRole::query()->create([
            'node_id' => $router->id,
            'cluster_id' => $cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Active,
        ]);
        config()->set('task_vms.dev_cluster_id', $cluster->id);
    });

    it('runs the hub script on the vpn Node with endpoints derived from the fleet', function (): void {
        $this->artisan('task-vms:prepare-hub')
            ->expectsOutputToContain('Hub [vpn] filters task VM range [10.44.0.128/25].')
            ->assertSuccessful();

        expect($this->ssh->connections[0]->host)->toBe('10.44.0.1')
            ->and($this->ssh->connections[0]->user)->toBe('orbit')
            ->and($this->ssh->commands[0]->arguments)->toBe([
                'sudo', '-n', 'bash', '-s', '--', '10.44.0.128/25', '10.44.0.1', '10.44.0.2', '3774', '10.44.0.3:443', '10.44.0.3:8317', '10.44.0.7',
            ])
            ->and($this->ssh->commands[0]->input)->toBe(file_get_contents(resource_path('task-vms/hub.sh')));
    });

    it('passes the configured VPN DNS address, Pi port, and default origin port', function (): void {
        app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', dnsServer: '10.44.0.53');
        config()->set('orbit.pi.port', 4774);
        config()->set('task_vms.model_proxy_origin', 'https://10.44.0.3');

        $this->artisan('task-vms:prepare-hub')->assertSuccessful();

        expect(array_slice($this->ssh->commands[0]->arguments, 5, 6))
            ->toBe(['10.44.0.128/25', '10.44.0.53', '10.44.0.2', '4774', '10.44.0.3:443', '10.44.0.3:443']);
    });

    it('allows task VM Nodes inside the range', function (): void {
        task_vm_fleet_node('tvm-12', '10.44.0.130', [RoleName::AppDev]);

        $this->artisan('task-vms:prepare-hub')->assertSuccessful();
    });

    it('refuses a range or fleet that the filter could break', function (Closure $arrange, string $message): void {
        $arrange();

        $this->artisan('task-vms:prepare-hub')
            ->expectsOutputToContain($message)
            ->assertFailed();

        expect($this->ssh->commands)->toBe([]);
    })->with([
        'range outside the WireGuard subnet' => [
            fn () => config()->set('task_vms.wireguard_range', '10.45.0.0/25'),
            '[task_vm.invalid_config] The task VM config is invalid: wireguard_range must be a smaller network inside the VPN subnet [10.44.0.0/24].',
        ],
        'range that covers the whole subnet' => [
            fn () => config()->set('task_vms.wireguard_range', '10.44.0.0/23'),
            'wireguard_range must be a smaller network inside the VPN subnet',
        ],
        'fleet Node inside the range' => [
            fn () => task_vm_fleet_node('laptop', '10.44.0.200', []),
            '[task_vm.range_in_use] Node [laptop] holds [10.44.0.200] inside [10.44.0.128/25].',
        ],
        'model proxy inside the range' => [
            fn () => config()->set('task_vms.model_proxy_origin', 'http://10.44.0.140:8317'),
            '[task_vm.invalid_config] The task VM config is invalid: model_proxy_origin [http://10.44.0.140:8317] must name an IPv4 address outside [10.44.0.128/25].',
        ],
        'model proxy by name' => [
            fn () => config()->set('task_vms.model_proxy_origin', 'http://models.orbit:8317'),
            '[task_vm.invalid_config] The task VM config is invalid: model_proxy_origin [http://models.orbit:8317] must name an IPv4 address',
        ],
        'no model proxy' => [
            fn () => config()->set('task_vms.model_proxy_origin', null),
            '[task_vm.invalid_config] The task VM config is invalid: model_proxy_origin is not set.',
        ],
        'no dev Cluster' => [
            fn () => config()->set('task_vms.dev_cluster_id', null),
            '[task_vm.invalid_config] The task VM config is invalid: dev_cluster_id is not set.',
        ],
        'missing dev Cluster' => [
            fn () => config()->set('task_vms.dev_cluster_id', 999),
            '[task_vm.fleet_unavailable] Cluster [999] has no active router Node.',
        ],
        'second websocket Node' => [
            fn () => task_vm_fleet_node('reverb2', '10.44.0.20', [RoleName::WebSocket]),
            '[task_vm.fleet_unavailable] Expected exactly one active [websocket] Node, found [2].',
        ],
    ]);
});

describe('task VM setup scripts', function (): void {
    it('are valid bash', function (string $script): void {
        $syntax = new Process(['bash', '-n', resource_path('task-vms/'.$script)]);
        $syntax->run();

        expect($syntax->getExitCode())->toBe(0, $syntax->getErrorOutput());
    })->with(['incus-host.sh', 'hub.sh', 'base-image-clean.sh']);

    it('pass shellcheck when it is installed', function (): void {
        $finder = new ExecutableFinder;
        $shellcheck = $finder->find('shellcheck');

        if ($shellcheck === null) {
            $this->markTestSkipped('shellcheck is not installed.');
        }

        $check = new Process([
            $shellcheck,
            resource_path('task-vms/incus-host.sh'),
            resource_path('task-vms/hub.sh'),
            resource_path('task-vms/base-image-clean.sh'),
        ]);
        $check->run();

        expect($check->getExitCode())->toBe(0, $check->getOutput());
    });

    it('reject invalid arguments before they change anything', function (string $script, array $arguments, string $message): void {
        $bash = new ExecutableFinder()->find('bash') ?? '/bin/bash';
        $process = new Process([$bash, '-s', '--', ...$arguments]);
        $process->setInput(file_get_contents(resource_path('task-vms/'.$script)));
        $process->setEnv(['PATH' => '/nonexistent']);
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getOutput())->toBe('')
            ->and($process->getErrorOutput())->toContain($message);
    })->with([
        'host network outside the prefix' => ['incus-host.sh', ['orbit-tasks', 'incusbr0', '10.252.0.0/24', 'default', 'img'], 'invalid network [incusbr0]'],
        'host cidr not a network' => ['incus-host.sh', ['orbit-tasks', 'orbittask0', '10.252.0.1/24', 'default', 'img'], 'not a network address'],
        'host cidr too wide' => ['incus-host.sh', ['orbit-tasks', 'orbittask0', '10.0.0.0/8', 'default', 'img'], 'prefix must be /16 to /28'],
        'host argument count' => ['incus-host.sh', ['orbit-tasks'], 'usage: incus-host.sh'],
        'host ZFS pool as dataset' => ['incus-host.sh', ['orbit-tasks', 'orbittask0', '10.252.0.0/24', 'orbit-tasks', 'img', 'fast'], 'invalid ZFS dataset [fast]'],
        'host ZFS dataset as an option' => ['incus-host.sh', ['orbit-tasks', 'orbittask0', '10.252.0.0/24', 'orbit-tasks', 'img', '-o/x'], 'invalid ZFS dataset [-o/x]'],
        'hub range not a network' => ['hub.sh', ['10.44.0.129/25', '10.44.0.1', '10.44.0.2', '3774', '10.44.0.3:443', '10.44.0.3:8317', '10.44.0.7'], 'range is not a network address'],
        'hub port out of range' => ['hub.sh', ['10.44.0.128/25', '10.44.0.1', '10.44.0.2', '3774', '10.44.0.3:99999', '10.44.0.3:8317', '10.44.0.7'], 'invalid Reverb endpoint'],
        'hub router with rule text' => ['hub.sh', ['10.44.0.128/25', '10.44.0.1', '10.44.0.2', '3774', '10.44.0.3:443', '10.44.0.3:8317', '10.44.0.7 accept'], 'invalid router address'],
        'hub without router' => ['hub.sh', ['10.44.0.128/25', '10.44.0.1', '10.44.0.2', '3774', '10.44.0.3:443', '10.44.0.3:8317'], 'usage: hub.sh'],
        'hub DNS address with rule text' => ['hub.sh', ['10.44.0.128/25', '10.44.0.1 accept', '10.44.0.2', '3774', '10.44.0.3:443', '10.44.0.3:8317', '10.44.0.7'], 'invalid DNS address'],
        'hub Pi port not a number' => ['hub.sh', ['10.44.0.128/25', '10.44.0.1', '10.44.0.2', '22 }', '10.44.0.3:443', '10.44.0.3:8317', '10.44.0.7'], 'invalid Pi port'],
    ]);
});

/** @param list<RoleName> $roles */
function task_vm_fleet_node(string $name, string $wireguardIp, array $roles, string $user = 'nckrtl', ?Cluster $cluster = null): Node
{
    $node = Node::query()->create([
        'cluster_id' => $cluster?->id,
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.'.substr($wireguardIp, strrpos($wireguardIp, '.') + 1),
        'user' => $user,
        'wireguard_ip' => $wireguardIp,
    ]);

    foreach ($roles as $role) {
        NodeRole::query()->create(['node_id' => $node->id, 'role' => $role, 'status' => LifecycleStatus::Active]);
    }

    return $node;
}
