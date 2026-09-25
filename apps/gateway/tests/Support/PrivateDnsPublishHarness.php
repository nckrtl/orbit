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
        $this->files->put($this->root.'/state-listener-confirms', '1');
        $failTest = $this->root.'/state-fail-test';
        $failRestart = $this->root.'/state-fail-restart';
        $failListener = $this->root.'/state-fail-listener';
        $failBind = $this->root.'/state-fail-bind';
        $active = $this->root.'/state-active';
        $listenerActive = $this->root.'/state-listener-active';
        $listenerConfirms = $this->root.'/state-listener-confirms';
        $socketActive = $this->root.'/state-socket-active';
        $stale = $this->root.'/state-stale';
        $failListenerRestart = $this->root.'/state-fail-listener-restart';
        $catalog = $this->root.'/var/lib/orbit/private-dns/catalog.json';
        $serviceLog = $this->root.'/systemctl.log';
        $ssLog = $this->root.'/ss.log';
        $this->writeShim('dnsmasq', <<<BASH
            #!/bin/bash
            set -euo pipefail
            if [ -f '{$failTest}' ]; then
                echo 'dnsmasq: failed test' >&2
                exit 1
            fi
            exit 0
            BASH);
        // A listener started from a release loads the catalog and confirms it; a stale one does not.
        $this->writeShim('systemctl', <<<BASH
            #!/bin/bash
            set -euo pipefail
            printf '%s\n' "\$*" >> '{$serviceLog}'
            confirm() {
                rm -f '{$stale}'
                if [ -f '{$listenerConfirms}' ] && [ -f '{$catalog}' ]; then
                    sha256sum -- '{$catalog}' | cut -d ' ' -f 1 > '{$catalog}.loaded'
                fi
            }
            unit="\${*: -1}"
            case "\$1" in
                is-active)
                    case "\$unit" in
                        orbit-private-dns.service) state='{$listenerActive}' ;;
                        orbit-private-dns.socket) state='{$socketActive}' ;;
                        *) state='{$active}' ;;
                    esac
                    [ -f "\$state" ] && exit 0
                    exit 3
                    ;;
                show)
                    if [ -f '{$listenerActive}' ]; then
                        printf '%s\n' '4242'
                    else
                        printf '%s\n' '0'
                    fi
                    exit 0
                    ;;
            esac
            if [ "\$unit" = 'orbit-private-dns.socket' ]; then
                case "\$1" in
                    enable) [ "\${2:-}" = '--now' ] && touch '{$socketActive}' ;;
                    start|restart) touch '{$socketActive}' ;;
                    stop|disable) rm -f '{$socketActive}' ;;
                esac
                exit 0
            fi
            if [ "\$unit" = 'orbit-private-dns.service' ]; then
                case "\$1" in
                    restart)
                        if [ -f '{$failListenerRestart}' ]; then
                            echo 'orbit-private-dns failed to restart' >&2
                            rm -f '{$listenerActive}'
                            exit 1
                        fi
                        touch '{$listenerActive}'
                        confirm
                        ;;
                    start)
                        if [ -f '{$failListener}' ]; then
                            echo 'orbit-private-dns failed to start' >&2
                            exit 1
                        fi
                        touch '{$listenerActive}'
                        confirm
                        ;;
                    enable)
                        if [ "\${2:-}" = '--now' ]; then
                            touch '{$listenerActive}'
                            confirm
                        fi
                        ;;
                    stop) rm -f '{$listenerActive}' ;;
                    disable) [ "\${2:-}" = '--now' ] && rm -f '{$listenerActive}' ;;
                esac
                exit 0
            fi
            if [ "\$1" = 'restart' ]; then
                if [ -f '{$failRestart}' ]; then
                    echo 'dnsmasq failed to restart' >&2
                    exit 1
                fi
                touch '{$active}'
            fi
            exit 0
            BASH);
        $this->writeShim('ss', <<<BASH
            #!/bin/bash
            set -euo pipefail
            printf '%s\n' "\$*" >> '{$ssLog}'
            if [ -f '{$failBind}' ]; then
                exit 0
            fi
            if [ -f '{$listenerActive}' ]; then
                printf '%s\n' 'UNCONN 0 0 10.44.0.1:53 0.0.0.0:* users:(("php8.5",pid=4242,fd=5))'
            fi
            exit 0
            BASH);
        // A waiting publication gives the listener time to load the catalog. A confirming listener does so here.
        $this->writeShim('sleep', <<<BASH
            #!/bin/bash
            set -euo pipefail
            if [ -f '{$listenerConfirms}' ] && [ ! -f '{$stale}' ] && [ -f '{$listenerActive}' ] && [ -f '{$catalog}' ]; then
                sha256sum -- '{$catalog}' | cut -d ' ' -f 1 > '{$catalog}.loaded'
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
            phpBinary: PHP_BINARY,
            unitDirectory: $this->root.'/etc/systemd/system',
        );
    }

    public function confDirectory(): string
    {
        return $this->root.'/etc/dnsmasq.d';
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

    /**
     * The running listener stops loading the catalog, like one whose code never rereads it. A restart replaces it.
     */
    public function staleListener(): void
    {
        file_put_contents($this->root.'/state-stale', '1');
    }

    /**
     * The listener never writes a confirmation, like one that predates it.
     */
    public function listenerNeverConfirms(): void
    {
        @unlink($this->root.'/state-listener-confirms');
    }

    public function markSocketActive(): void
    {
        file_put_contents($this->root.'/state-socket-active', '1');
    }

    public function socketPath(): string
    {
        return $this->root.'/etc/systemd/system/orbit-private-dns.socket';
    }

    public function releasesPath(): string
    {
        return $this->root.'/var/lib/orbit/private-dns/releases';
    }

    public function failListenerRestart(): void
    {
        file_put_contents($this->root.'/state-fail-listener-restart', '1');
    }

    public function loadedPath(): string
    {
        return $this->catalogPath().'.loaded';
    }

    public function putLoaded(string $contents): void
    {
        $this->files->put($this->loadedPath(), $contents);
    }

    public function failListenerStart(): void
    {
        file_put_contents($this->root.'/state-fail-listener', '1');
    }

    public function failListenerBind(): void
    {
        file_put_contents($this->root.'/state-fail-bind', '1');
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
        return $this->linesFrom($this->root.'/systemctl.log');
    }

    /**
     * @return list<string>
     */
    public function socketProbes(): array
    {
        return $this->linesFrom($this->root.'/ss.log');
    }

    /**
     * @return list<string>
     */
    public function confDirectoryEntries(): array
    {
        $entries = scandir($this->confDirectory());
        if ($entries === false) {
            return [];
        }

        return array_values(array_filter(
            $entries,
            static fn (string $name): bool => $name !== '.' && $name !== '..',
        ));
    }

    /**
     * @return list<string>
     */
    public function confDirectoryListenAddressFiles(): array
    {
        $files = [];
        foreach ($this->confDirectoryEntries() as $name) {
            $contents = (string) file_get_contents($this->confDirectory().'/'.$name);
            if (str_contains($contents, 'listen-address=')) {
                $files[] = $name;
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function linesFrom(string $path): array
    {
        $log = @file_get_contents($path);
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
