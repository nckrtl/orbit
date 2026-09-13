<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AppDev\AppDevDnsConfigRenderer;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\Processes\NativeProcessRunner;
use Illuminate\Filesystem\Filesystem;

final class PrivateDnsPublishHarness
{
    private string $root;

    private Filesystem $files;

    public function __construct()
    {
        $this->root = sys_get_temp_dir().'/orbit-private-dns-'.bin2hex(random_bytes(8));
        $this->files = new Filesystem;
        $this->files->makeDirectory($this->root.'/bin', 0755, true);
        $this->files->makeDirectory($this->root.'/etc/dnsmasq.d', 0755, true);
        $this->files->makeDirectory($this->root.'/run/lock', 0755, true);
        $this->files->makeDirectory($this->root.'/var/lib/orbit/private-dns', 0755, true);
        $this->files->makeDirectory($this->root.'/etc/systemd/system', 0755, true);
        $this->files->put($this->root.'/etc/dnsmasq.conf', "conf-dir={$this->root}/etc/dnsmasq.d\n");
        $failTest = $this->root.'/state-fail-test';
        $failRestart = $this->root.'/state-fail-restart';
        $failListener = $this->root.'/state-fail-listener';
        $active = $this->root.'/state-active';
        $listenerActive = $this->root.'/state-listener-active';
        $serviceLog = $this->root.'/systemctl.log';
        $this->writeShim('dnsmasq', <<<BASH
            #!/bin/bash
            set -euo pipefail
            if [ -f '{$failTest}' ]; then
                echo 'dnsmasq: failed test' >&2
                exit 1
            fi
            exit 0
            BASH);
        $this->writeShim('systemctl', <<<BASH
            #!/bin/bash
            set -euo pipefail
            printf '%s\n' "\$*" >> '{$serviceLog}'
            if [ "\${1:-}" = 'is-active' ]; then
                if [ "\${3:-}" = 'orbit-private-dns.service' ]; then
                    if [ -f '{$listenerActive}' ]; then
                        exit 0
                    fi
                    exit 3
                fi
                if [ -f '{$active}' ]; then
                    exit 0
                fi
                exit 3
            fi
            if [ "\${1:-}" = 'restart' ]; then
                if [ -f '{$failRestart}' ]; then
                    echo 'dnsmasq failed to restart' >&2
                    exit 1
                fi
                touch '{$active}'
                exit 0
            fi
            if [ "\${1:-}" = 'enable' ]; then
                if [ -f '{$failListener}' ]; then
                    echo 'orbit-private-dns failed to start' >&2
                    exit 1
                fi
                touch '{$listenerActive}'
                exit 0
            fi
            exit 0
            BASH);
        $this->writeShim('systemd-analyze', <<<'BASH'
            #!/bin/bash
            set -euo pipefail
            exit 0
            BASH);
    }

    public function manager(): DnsmasqPrivateDnsManager
    {
        return new DnsmasqPrivateDnsManager(
            processes: new NativeProcessRunner,
            renderer: new AppDevDnsConfigRenderer(new AppDevSiteRepository),
            recordsDirectory: $this->root.'/etc/dnsmasq.d',
            dnsmasqConf: $this->root.'/etc/dnsmasq.conf',
            catalogDirectory: $this->root.'/var/lib/orbit/private-dns',
            lockPath: $this->root.'/run/lock/orbit-dnsmasq.lock',
            shell: ['bash', '-seu'],
            preserveRootOwnership: false,
            executablePath: $this->root.'/bin',
        );
    }

    public function listenerManager(): DnsmasqPrivateDnsManager
    {
        return new DnsmasqPrivateDnsManager(
            processes: new NativeProcessRunner,
            renderer: new AppDevDnsConfigRenderer(new AppDevSiteRepository),
            recordsDirectory: $this->root.'/etc/dnsmasq.d',
            dnsmasqConf: $this->root.'/etc/dnsmasq.conf',
            catalogDirectory: $this->root.'/var/lib/orbit/private-dns',
            lockPath: $this->root.'/run/lock/orbit-dnsmasq.lock',
            shell: ['bash', '-seu'],
            preserveRootOwnership: false,
            executablePath: $this->root.'/bin',
            activateListener: true,
            listenAddress: '10.44.0.1',
            checkoutPath: $this->root.'/gateway',
            phpBinary: PHP_BINARY,
            unitDirectory: $this->root.'/etc/systemd/system',
            orbitHome: $this->root.'/orbit-home',
        );
    }

    public function recordsPath(): string
    {
        return $this->root.'/etc/dnsmasq.d/orbit-records.conf';
    }

    public function vpnFragmentPath(): string
    {
        return $this->root.'/etc/dnsmasq.d/orbit-vpn.conf';
    }

    public function unitPath(): string
    {
        return $this->root.'/etc/systemd/system/orbit-private-dns.service';
    }

    public function catalogPath(): string
    {
        return $this->root.'/var/lib/orbit/private-dns/catalog.json';
    }

    public function putRecords(string $contents): void
    {
        $this->files->put($this->recordsPath(), $contents);
    }

    public function putCatalog(string $contents): void
    {
        $this->files->put($this->catalogPath(), $contents);
    }

    public function putVpnFragment(string $contents): void
    {
        $this->files->put($this->vpnFragmentPath(), $contents);
    }

    public function markActive(): void
    {
        file_put_contents($this->root.'/state-active', '1');
    }

    public function markListenerActive(): void
    {
        file_put_contents($this->root.'/state-listener-active', '1');
    }

    public function failListenerStart(): void
    {
        file_put_contents($this->root.'/state-fail-listener', '1');
    }

    public function clearServiceLog(): void
    {
        file_put_contents($this->root.'/systemctl.log', '');
    }

    public function failValidation(): void
    {
        file_put_contents($this->root.'/state-fail-test', '1');
    }

    public function failRestart(): void
    {
        file_put_contents($this->root.'/state-fail-restart', '1');
    }

    public function clearRestartFailure(): void
    {
        @unlink($this->root.'/state-fail-restart');
    }

    /**
     * @return list<string>
     */
    public function serviceCalls(): array
    {
        $log = @file_get_contents($this->root.'/systemctl.log');
        if (! is_string($log) || $log === '') {
            return [];
        }

        return array_values(array_filter(explode("\n", trim($log))));
    }

    public function cleanup(): void
    {
        $this->files->deleteDirectory($this->root);
    }

    private function writeShim(string $name, string $contents): void
    {
        $path = $this->root.'/bin/'.$name;
        $this->files->put($path, $contents);
        chmod($path, 0755);
    }
}
