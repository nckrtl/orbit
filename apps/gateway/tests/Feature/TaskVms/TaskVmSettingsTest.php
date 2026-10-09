<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;

/** @param array<string, mixed> $overrides */
function enable_task_vms(array $overrides = []): void
{
    config(['task_vms' => array_replace_recursive([
        'enabled' => true,
        'dev_cluster_id' => 4,
        'wireguard_range' => '10.44.64.0/20',
        'model_proxy_origin' => 'http://10.44.0.3:8317',
        'pi' => [
            'artifact_path' => '/home/orbit/.orbit/pi/pi-linux-x64',
            'artifact_sha256' => str_repeat('a', 64),
            'models' => ['proxy/coder-large', 'proxy/coder-large'],
        ],
        'incus' => ['hosts' => [['node_id' => 7, 'cidr' => '10.251.77.0/24', 'max_vms' => 4]]],
    ], $overrides)]);
}

/** @param array<string, mixed> $host */
function task_vm_hosts(array $host): array
{
    return ['incus' => ['hosts' => [[...['node_id' => 7, 'cidr' => '10.251.77.0/24', 'max_vms' => 4], ...$host]]]];
}

describe('defaults', function (): void {
    it('keeps task VMs off with the reserved range and no hosts', function (): void {
        $config = config_without_env('task_vms.php', [
            'ORBIT_TASK_VMS_ENABLED', 'ORBIT_TASK_VMS_WIREGUARD_RANGE', 'ORBIT_TASK_VMS_INCUS_HOSTS', 'ORBIT_TASK_VMS_PI_MODELS',
        ]);
        config(['task_vms' => $config]);

        $settings = TaskVmSettings::fromConfig();

        expect($config['enabled'])->toBeFalse()
            ->and($settings->enabled)->toBeFalse()
            ->and($settings->wireguardRange)->toBe('10.44.64.0/20')
            ->and($settings->hosts)->toBe([]);
    });

    it('does not validate the rest of the config while task VMs are off', function (): void {
        config(['task_vms' => ['enabled' => false, 'wireguard_range' => '10.44.64.0/20', 'incus' => ['hosts' => 'not json']]]);

        expect(TaskVmSettings::fromConfig()->enabled)->toBeFalse();
    });

    it('validates the reserved range even while task VMs are off', function (): void {
        config(['task_vms' => ['enabled' => false, 'wireguard_range' => '10.44.64.1/20']]);

        expect(fn (): TaskVmSettings => TaskVmSettings::fromConfig())
            ->toThrow(TaskVmException::class, 'wireguard_range must be an IPv4 network');
    });
});

describe('enabled', function (): void {
    it('reads a valid config with host defaults', function (): void {
        enable_task_vms();

        $settings = TaskVmSettings::fromConfig();

        expect($settings->enabled)->toBeTrue()
            ->and($settings->devClusterId)->toBe(4)
            ->and($settings->modelProxyOrigin)->toBe('http://10.44.0.3:8317')
            ->and($settings->piArtifactSha256)->toBe(str_repeat('a', 64))
            ->and($settings->piModels)->toBe(['proxy/coder-large'])
            ->and($settings->hosts)->toEqual([new TaskVmHost(7, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', 'ubuntu-26.04-vm', 4, 2, '4GiB', '20GiB', 'default')])
            ->and($settings->host(7)->bridgeAddress())->toBe('10.251.77.1');
    });

    it('validates once through the container singleton', function (): void {
        enable_task_vms();
        $first = app(TaskVmSettings::class);
        config(['task_vms.dev_cluster_id' => 0]);

        expect(app(TaskVmSettings::class))->toBe($first);
    });

    it('refuses a host that is not configured', function (): void {
        enable_task_vms();

        expect(fn (): TaskVmHost => TaskVmSettings::fromConfig()->host(8))
            ->toThrow(TaskVmException::class, 'Node [8] is not a task VM host.');
    });

    it('refuses invalid config with task_vm.invalid_config', function (array $overrides, string $reason): void {
        enable_task_vms($overrides);

        try {
            TaskVmSettings::fromConfig();
            $this->fail('The invalid config was accepted.');
        } catch (TaskVmException $exception) {
            expect($exception->errorCode)->toBe('task_vm.invalid_config')
                ->and($exception->getMessage())->toContain($reason);
        }
    })->with([
        'missing Cluster' => [['dev_cluster_id' => 0], 'dev_cluster_id'],
        'public reserved range' => [['wireguard_range' => '8.8.0.0/20'], 'wireguard_range must be a private'],
        'no hosts' => [['incus' => ['hosts' => null]], 'incus.hosts must be a non-empty JSON list'],
        'unknown host key' => [task_vm_hosts(['bridge' => 'orbittask0']), 'incus.hosts.0 has unknown keys'],
        'missing host Node' => [task_vm_hosts(['node_id' => '7']), 'incus.hosts.0.node_id'],
        'missing capacity' => [task_vm_hosts(['max_vms' => 0]), 'incus.hosts.0.max_vms'],
        'bridge address instead of network' => [task_vm_hosts(['cidr' => '10.251.77.1/24']), 'incus.hosts.0.cidr must be an IPv4 network'],
        'public bridge' => [task_vm_hosts(['cidr' => '203.0.113.0/24']), 'incus.hosts.0.cidr must be a private'],
        'bridge in reserved range' => [task_vm_hosts(['cidr' => '10.44.65.0/24']), 'must not overlap wireguard_range'],
        'bridge outside the reserved prefix' => [task_vm_hosts(['network' => 'incusbr0']), 'incus.hosts.0.network'],
        'bad memory' => [task_vm_hosts(['memory' => '4G']), 'incus.hosts.0.memory'],
        'duplicate host' => [['incus' => ['hosts' => [
            ['node_id' => 7, 'cidr' => '10.251.77.0/24', 'max_vms' => 4],
            ['node_id' => 7, 'cidr' => '10.251.78.0/24', 'max_vms' => 4],
        ]]], 'incus.hosts.1.node_id is listed twice'],
        'origin with a path' => [['model_proxy_origin' => 'http://10.44.0.3:8317/v1'], 'model_proxy_origin'],
        'origin with credentials' => [['model_proxy_origin' => 'http://key@10.44.0.3:8317'], 'model_proxy_origin'],
        'missing origin' => [['model_proxy_origin' => null], 'model_proxy_origin'],
        'artifact without digest' => [['pi' => ['artifact_sha256' => null]], 'must be set together'],
        'relative artifact path' => [['pi' => ['artifact_path' => 'pi-linux-x64']], 'pi.artifact_path'],
        'uppercase digest' => [['pi' => ['artifact_sha256' => str_repeat('A', 64)]], 'pi.artifact_sha256'],
        'model with spaces' => [['pi' => ['models' => ['coder large']]], 'pi.models'],
        'models not a list' => [['pi' => ['models' => null]], 'pi.models'],
    ]);
});

describe('hosts', function (): void {
    it('treats only non-bridge usable addresses as guest addresses', function (string $address, bool $guest): void {
        $host = new TaskVmHost(7, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', 'ubuntu-26.04-vm', 4, 2, '4GiB', '20GiB');

        expect($host->isGuestAddress($address))->toBe($guest);
    })->with([
        'guest' => ['10.251.77.32', true],
        'bridge' => ['10.251.77.1', false],
        'network' => ['10.251.77.0', false],
        'broadcast' => ['10.251.77.255', false],
        'outside' => ['10.251.78.32', false],
        'not an address' => ['10.251.77.032', false],
    ]);
});
