<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\WireGuard\VpnSettings;
use PHPUnit\Framework\Assert;

/** @param array<string, mixed> $overrides */
function enable_task_vms(array $overrides = []): void
{
    config(['task_vms' => array_replace_recursive([
        'enabled' => true,
        'dev_cluster_id' => 4,
        'wireguard_range' => '10.44.0.128/25',
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

/** @param array<string, mixed> $values */
function task_vms_off(array $values = []): void
{
    config(['task_vms' => array_replace_recursive([
        'enabled' => false,
        'dev_cluster_id' => null,
        'wireguard_range' => '10.44.0.128/25',
        'model_proxy_origin' => null,
        'pi' => ['artifact_path' => null, 'artifact_sha256' => null, 'models' => []],
        'incus' => ['hosts' => []],
    ], $values)]);
}

function expect_invalid_task_vm_config(string $reason): void
{
    try {
        TaskVmSettings::fromConfig();
        Assert::fail('The invalid config was accepted.');
    } catch (TaskVmException $exception) {
        expect($exception->errorCode)->toBe('task_vm.invalid_config')
            ->and($exception->getMessage())->toContain($reason);
    }
}

describe('defaults', function (): void {
    it('keeps task VMs off with the reserved range and nothing else set', function (): void {
        $config = config_without_env('task_vms.php', [
            'ORBIT_TASK_VMS_ENABLED', 'ORBIT_TASK_VMS_DEV_CLUSTER_ID', 'ORBIT_TASK_VMS_WIREGUARD_RANGE', 'ORBIT_TASK_VMS_MODEL_PROXY_ORIGIN',
            'ORBIT_TASK_VMS_PI_ARTIFACT_PATH', 'ORBIT_TASK_VMS_PI_ARTIFACT_SHA256', 'ORBIT_TASK_VMS_PI_MODELS', 'ORBIT_TASK_VMS_INCUS_HOSTS',
        ]);
        config(['task_vms' => $config]);

        $settings = TaskVmSettings::fromConfig();

        expect($config['enabled'])->toBeFalse()
            ->and($settings)->toEqual(new TaskVmSettings(false, null, '10.44.0.128/25', [], null, null, null, []));
    });

    it('stays inert on a fleet whose VPN subnet does not hold the default range', function (): void {
        app(VpnSettings::class)->configure(subnet: '10.43.0.0/24');
        task_vms_off();

        expect(TaskVmSettings::fromConfig()->wireguardRange)->toBe('10.44.0.128/25');
    });

    it('validates the reserved range even while nothing else is set', function (): void {
        task_vms_off(['wireguard_range' => '10.44.0.129/25']);

        expect_invalid_task_vm_config('wireguard_range must be an IPv4 network');
    });
});

describe('off but configured', function (): void {
    it('reads hosts, the Cluster and the origin while task VMs are off', function (): void {
        task_vms_off([
            'dev_cluster_id' => '4',
            'model_proxy_origin' => 'http://10.44.0.3:8317/',
            'incus' => ['hosts' => [['node_id' => 7, 'cidr' => '10.251.77.0/24', 'max_vms' => 4]]],
        ]);

        $settings = TaskVmSettings::fromConfig();

        expect($settings->enabled)->toBeFalse()
            ->and($settings->devClusterId)->toBe(4)
            ->and($settings->modelProxyOrigin)->toBe('http://10.44.0.3:8317')
            ->and($settings->host(7)->bridgeAddress())->toBe('10.251.77.1');
    });

    it('gives Caddy the range once the Cluster, the origin, or a host is set, but not for the range or Pi alone', function (array $values, ?string $range): void {
        task_vms_off($values);

        expect(TaskVmSettings::caddyGuardRange())->toBe($range);
    })->with([
        'nothing set' => [[], null],
        'range and Pi only' => [['wireguard_range' => '10.44.0.192/26', 'pi' => ['models' => ['proxy/coder-large']]], null],
        'invalid range alone' => [['wireguard_range' => 'not a range'], null],
        'invalid Pi artifact alone' => [['pi' => ['artifact_path' => '/home/orbit/pi']], null],
        'enabled' => [['enabled' => true], '10.44.0.128/25'],
        'Cluster' => [['dev_cluster_id' => 4], '10.44.0.128/25'],
        'origin' => [['model_proxy_origin' => 'http://10.44.0.3:8317'], '10.44.0.128/25'],
        'host' => [task_vm_hosts([]), '10.44.0.128/25'],
        'malformed hosts JSON' => [['incus' => ['hosts' => null]], '10.44.0.128/25'],
        'invalid values beside the range' => [['dev_cluster_id' => '4x', 'model_proxy_origin' => 'http://10.44.0.3:8317/v1', 'pi' => ['artifact_path' => '/home/orbit/pi']], '10.44.0.128/25'],
        'range outside the VPN subnet' => [['dev_cluster_id' => 4, 'wireguard_range' => '10.45.0.128/25'], '10.45.0.128/25'],
    ]);

    it('refuses an invalid range for Caddy once task VMs are configured', function (): void {
        task_vms_off(['dev_cluster_id' => 4, 'wireguard_range' => '10.44.0.129/25']);

        try {
            TaskVmSettings::caddyGuardRange();
            Assert::fail('The invalid range was accepted.');
        } catch (TaskVmException $exception) {
            expect($exception->errorCode)->toBe('task_vm.invalid_config')
                ->and($exception->getMessage())->toContain('wireguard_range must be an IPv4 network');
        }
    });

    it('treats empty values as unset', function (): void {
        task_vms_off(['dev_cluster_id' => '', 'model_proxy_origin' => '', 'pi' => ['artifact_path' => '', 'artifact_sha256' => '']]);

        expect(TaskVmSettings::fromConfig())->toEqual(new TaskVmSettings(false, null, '10.44.0.128/25', [], null, null, null, []));
    });

    it('refuses an invalid value that is set while task VMs are off', function (array $values, string $reason): void {
        task_vms_off($values);

        expect_invalid_task_vm_config($reason);
    })->with([
        'malformed hosts JSON' => [['incus' => ['hosts' => null]], 'incus.hosts must be a JSON list'],
        'hosts not a list' => [['incus' => ['hosts' => 'not json']], 'incus.hosts must be a JSON list'],
        'bad host' => [task_vm_hosts(['max_vms' => 0]), 'incus.hosts.0.max_vms'],
        'bad Cluster' => [['dev_cluster_id' => '4x'], 'dev_cluster_id must be a Cluster id'],
        'bad origin' => [['model_proxy_origin' => 'http://10.44.0.3:8317/v1'], 'model_proxy_origin'],
        'malformed models JSON' => [['pi' => ['models' => null]], 'pi.models'],
        'artifact without digest' => [['pi' => ['artifact_path' => '/home/orbit/pi']], 'must be set together'],
        'range outside the VPN subnet' => [['wireguard_range' => '10.45.0.128/25', 'dev_cluster_id' => 4], 'inside the VPN subnet [10.44.0.0/24]'],
        'range as large as the VPN subnet' => [['wireguard_range' => '10.44.0.0/24', 'model_proxy_origin' => 'http://10.44.0.3:8317'], 'inside the VPN subnet'],
        'bridge overlapping the VPN subnet' => [task_vm_hosts(['cidr' => '10.44.0.0/16']), 'must not overlap the VPN subnet [10.44.0.0/24]'],
    ]);
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
            ->and($settings->hosts)->toEqual([new TaskVmHost(7, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', 4, 2, '4GiB', '20GiB', 'orbit-tasks', null)])
            ->and($settings->host(7)->bridgeAddress())->toBe('10.251.77.1');
    });

    it('reads the ZFS dataset that creates the pool', function (): void {
        enable_task_vms(task_vm_hosts(['pool' => 'orbit-p2', 'zfs_dataset' => 'fast/orbit-tasks']));

        expect(TaskVmSettings::fromConfig()->host(7))
            ->pool->toBe('orbit-p2')
            ->zfsDataset->toBe('fast/orbit-tasks');
    });

    it('checks the range against the configured VPN subnet', function (): void {
        app(VpnSettings::class)->configure(subnet: '10.43.0.0/24');
        enable_task_vms(['wireguard_range' => '10.43.0.192/26']);

        expect(TaskVmSettings::fromConfig()->wireguardRange)->toBe('10.43.0.192/26');
    });

    it('validates once through the container singleton', function (): void {
        enable_task_vms();
        $first = app(TaskVmSettings::class);
        config(['task_vms.dev_cluster_id' => 0]);

        expect(app(TaskVmSettings::class))->toBe($first);
    });

    it('needs at least one host', function (): void {
        enable_task_vms();
        config(['task_vms.incus.hosts' => []]);

        expect_invalid_task_vm_config('incus.hosts needs at least one host');
    });

    it('refuses a host that is not configured', function (): void {
        enable_task_vms();

        expect(fn (): TaskVmHost => TaskVmSettings::fromConfig()->host(8))
            ->toThrow(TaskVmException::class, 'Node [8] is not a task VM host.');
    });

    it('refuses invalid config with task_vm.invalid_config', function (array $overrides, string $reason): void {
        enable_task_vms($overrides);

        expect_invalid_task_vm_config($reason);
    })->with([
        'missing Cluster' => [['dev_cluster_id' => null], 'dev_cluster_id is required'],
        'zero Cluster' => [['dev_cluster_id' => 0], 'dev_cluster_id must be a Cluster id'],
        'public reserved range' => [['wireguard_range' => '8.8.0.0/20'], 'wireguard_range must be a private'],
        'unknown host key' => [task_vm_hosts(['bridge' => 'orbittask0']), 'incus.hosts.0 has unknown keys'],
        'missing host Node' => [task_vm_hosts(['node_id' => '7']), 'incus.hosts.0.node_id'],
        'missing capacity' => [task_vm_hosts(['max_vms' => 0]), 'incus.hosts.0.max_vms'],
        'bridge address instead of network' => [task_vm_hosts(['cidr' => '10.251.77.1/24']), 'incus.hosts.0.cidr must be an IPv4 network'],
        'public bridge' => [task_vm_hosts(['cidr' => '203.0.113.0/24']), 'incus.hosts.0.cidr must be a private'],
        'bridge in reserved range' => [task_vm_hosts(['cidr' => '10.44.0.128/26']), 'must not overlap the VPN subnet'],
        'bridge outside the reserved prefix' => [task_vm_hosts(['network' => 'incusbr0']), 'incus.hosts.0.network'],
        'bad memory' => [task_vm_hosts(['memory' => '4G']), 'incus.hosts.0.memory'],
        'image key, since every VM launches the base image' => [task_vm_hosts(['image' => 'ubuntu-26.04-vm']), 'incus.hosts.0 has unknown keys'],
        'whole ZFS pool as dataset' => [task_vm_hosts(['zfs_dataset' => 'fast']), 'incus.hosts.0.zfs_dataset'],
        'ZFS dataset with a space' => [task_vm_hosts(['zfs_dataset' => 'fast/orbit tasks']), 'incus.hosts.0.zfs_dataset'],
        'ZFS dataset as an option' => [task_vm_hosts(['zfs_dataset' => '-o/x']), 'incus.hosts.0.zfs_dataset'],
        'duplicate host' => [['incus' => ['hosts' => [
            ['node_id' => 7, 'cidr' => '10.251.77.0/24', 'max_vms' => 4],
            ['node_id' => 7, 'cidr' => '10.251.78.0/24', 'max_vms' => 4],
        ]]], 'incus.hosts.1.node_id is listed twice'],
        'origin with a path' => [['model_proxy_origin' => 'http://10.44.0.3:8317/v1'], 'model_proxy_origin'],
        'origin with credentials' => [['model_proxy_origin' => 'http://key@10.44.0.3:8317'], 'model_proxy_origin'],
        'missing origin' => [['model_proxy_origin' => null], 'model_proxy_origin is required'],
        'artifact without digest' => [['pi' => ['artifact_sha256' => null]], 'must be set together'],
        'relative artifact path' => [['pi' => ['artifact_path' => 'pi-linux-x64']], 'pi.artifact_path'],
        'uppercase digest' => [['pi' => ['artifact_sha256' => str_repeat('A', 64)]], 'pi.artifact_sha256'],
        'model with spaces' => [['pi' => ['models' => ['coder large']]], 'pi.models'],
        'models not a list' => [['pi' => ['models' => null]], 'pi.models'],
    ]);
});

describe('hosts', function (): void {
    it('treats only non-bridge usable addresses as guest addresses', function (string $address, bool $guest): void {
        $host = new TaskVmHost(7, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', 4, 2, '4GiB', '20GiB');

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
