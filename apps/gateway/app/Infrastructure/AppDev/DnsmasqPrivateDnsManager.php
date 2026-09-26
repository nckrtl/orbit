<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use JsonException;

final readonly class DnsmasqPrivateDnsManager implements PrivateDnsManager
{
    /**
     * @param  list<string>  $shell
     */
    public function __construct(
        private ProcessRunner $processes,
        private AppDevDnsConfigRenderer $renderer,
        private ?DevelopmentProjectionOperationLock $projection = null,
        private string $recordsDirectory = '/etc/dnsmasq.d',
        private string $recordsFile = 'orbit-records.conf',
        private string $dnsmasqConf = '/etc/dnsmasq.conf',
        private string $catalogDirectory = '/var/lib/orbit/private-dns',
        private string $catalogFile = 'catalog.json',
        private string $lockPath = '/run/lock/orbit-dnsmasq.lock',
        private array $shell = ['sudo', 'bash', '-seu'],
        private bool $preserveRootOwnership = true,
        private ?string $executablePath = null,
        private bool $activateListener = false,
        private ?string $listenAddress = null,
        private string $phpBinary = '/usr/bin/php8.5',
        private string $unitDirectory = '/etc/systemd/system',
        private string $vpnFragmentFile = 'orbit-vpn.conf',
        private string $backendAddress = VpnDnsmasqBackendListen::Address,
        private int $listenPort = 53,
        private ?VpnSettings $vpnSettings = null,
        private PrivateDnsListenerUnitRenderer $units = new PrivateDnsListenerUnitRenderer,
        private ?SshExecutor $ssh = null,
        private ?SshKeyProvider $keys = null,
        private ?KnownHostsStore $knownHosts = null,
        private ?PrivateDnsListenerRelease $release = null,
    ) {}

    public function converge(?Node $pendingNode = null): void
    {
        $this->owner()->run(fn () => $this->publish($pendingNode));
    }

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     */
    public function convergeSelection(array $nodeOverrides = [], array $clusterOverrides = []): void
    {
        $this->owner()->run(fn () => $this->publish(null, $nodeOverrides, $clusterOverrides));
    }

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     */
    private function publish(
        ?Node $pendingNode,
        array $nodeOverrides = [],
        array $clusterOverrides = [],
    ): void {
        $configuration = $this->renderer->render($pendingNode, $nodeOverrides, $clusterOverrides);
        $catalog = $this->publication($pendingNode, $nodeOverrides, $clusterOverrides);
        $encoded = base64_encode($configuration);
        $catalogEncoded = base64_encode($catalog);
        $recordsDirectory = $this->recordsDirectory;
        $recordsFile = $this->recordsFile;
        $dnsmasqConf = $this->dnsmasqConf;
        $catalogDirectory = $this->catalogDirectory;
        $catalogFile = $this->catalogFile;
        $lockPath = $this->lockPath;
        $ownership = $this->preserveRootOwnership ? '-o root -g root ' : '';
        $pathExport = $this->executablePath === null
            ? ''
            : 'export PATH='.escapeshellarg($this->executablePath).':"$PATH"'."\n";
        $remote = $this->remoteListenerOwner();
        // The same publication runs on the vpn Node when the roles split: the listener release carries its own code.
        $listener = $this->listenerPublication();
        $result = $this->runPublication($remote, <<<BASH
            {$pathExport}managed={$recordsDirectory}/{$recordsFile}
            candidate={$recordsDirectory}/.orbit-records.\$\$.candidate
            catalog_managed={$catalogDirectory}/{$catalogFile}
            catalog_candidate={$catalogDirectory}/.orbit-catalog.\$\$.candidate
            catalog_directory={$catalogDirectory}
            install -d -m 0755 -- "\$catalog_directory" {$recordsDirectory}
            validation=\$(mktemp -d)
            backup=\$(mktemp "\$validation/orbit-records.backup.XXXXXX")
            catalog_backup=\$(mktemp {$catalogDirectory}/.orbit-catalog.backup.XXXXXX)
            had_managed=0
            had_catalog=0
            trap 'rm -rf -- "\$validation"; rm -f -- "\$candidate" "\$backup" "\$catalog_candidate" "\$catalog_backup"' EXIT
            exec 9>{$lockPath}
            flock -w 30 9
            if [ -f "\$managed" ]; then
                cp --preserve=mode,ownership -- "\$managed" "\$backup"
                had_managed=1
            fi
            if [ -f "\$catalog_managed" ]; then
                cp --preserve=mode,ownership -- "\$catalog_managed" "\$catalog_backup"
                had_catalog=1
            fi
            install -d -m 0755 -- "\$validation/fragments"
            cp -a -- {$recordsDirectory}/. "\$validation/fragments/"
            printf '%s' '{$encoded}' | base64 --decode > "\$validation/fragments/{$recordsFile}"
            printf '%s' '{$catalogEncoded}' | base64 --decode > "\$validation/catalog.json"
            python3 -c 'import json,sys; json.load(open(sys.argv[1], encoding="utf-8"))' "\$validation/catalog.json"
            sed "s#{$recordsDirectory}#\$validation/fragments#g" {$dnsmasqConf} > "\$validation/dnsmasq.conf"
            dnsmasq --test --conf-file="\$validation/dnsmasq.conf"
            records_changed=1
            catalog_changed=1
            if [ -f "\$managed" ] && cmp -s -- "\$validation/fragments/{$recordsFile}" "\$managed"; then
                records_changed=0
            fi
            if [ -f "\$catalog_managed" ] && cmp -s -- "\$validation/catalog.json" "\$catalog_managed"; then
                catalog_changed=0
            fi
            {$listener}
            BASH);

        if (! $result->succeeded()) {
            throw new RuntimeConvergenceException(
                step: 'private-dns',
                errorCode: 'app-dev.dns_config_failed',
                message: $remote instanceof Node
                    ? "Could not converge Orbit private DNS records on node [{$remote->name}]."
                    : 'Could not converge Orbit private DNS records.',
                result: $result,
            );
        }
    }

    private function runPublication(?Node $remote, string $script): CommandResult
    {
        if (! $remote instanceof Node) {
            return $this->processes->run(new ProcessInvocation(
                arguments: $this->shell,
                timeout: 60.0,
                input: $script,
            ));
        }

        $address = $remote->wireguard_ip;
        if (! is_string($address) || $address === '' || $this->ssh === null || $this->keys === null || $this->knownHosts === null) {
            throw new RuntimeConvergenceException(
                step: 'private-dns',
                errorCode: 'app-dev.dns_config_failed',
                message: "Could not converge Orbit private DNS records on node [{$remote->name}].",
            );
        }

        return $this->ssh->execute(
            new SshConnection(
                host: $address,
                user: $remote->user,
                port: 22,
                identityFile: $this->keys->privateKeyPath(),
                knownHostsFile: $this->knownHosts->path(),
                commandTimeout: 60.0,
            ),
            new RemoteCommand(
                arguments: $this->shell,
                input: $script,
                timeout: 60.0,
            ),
        );
    }

    private function remoteListenerOwner(): ?Node
    {
        if ($this->ssh === null || $this->keys === null || $this->knownHosts === null) {
            return null;
        }

        $listener = $this->roleHolder(RoleName::Vpn);
        $gateway = $this->roleHolder(RoleName::Gateway);

        if (! $listener instanceof Node || ! $gateway instanceof Node || $listener->is($gateway)) {
            return null;
        }

        return $listener;
    }

    /**
     * Installs the listener release and its units, hands the DNS address to `orbit-private-dns.socket`, and makes
     * sure the running listener serves the published catalog. A restart keeps the sockets open in systemd, so it
     * never stops VPN DNS. See ADR 0149.
     */
    private function listenerPublication(): string
    {
        $listen = $this->resolvedListenAddress();
        if (! $this->activateListener || $listen === null) {
            return $this->recordsOnlyActivation();
        }

        $release = $this->release ?? PrivateDnsListenerRelease::fromGateway();
        $releaseRoot = $this->catalogDirectory.'/releases';
        $releaseId = $release->id();
        $releaseDirectory = $releaseRoot.'/'.$releaseId;
        $catalogPath = $this->catalogDirectory.'/'.$this->catalogFile;
        $unit = $this->units->render(
            phpBinary: $this->phpBinary,
            releaseDirectory: $releaseDirectory,
            listenAddress: $listen,
            port: $this->listenPort,
            catalogPath: $catalogPath,
            upstream: $this->backendAddress.':53',
        );
        $socket = $this->units->renderSocket($listen, $this->listenPort);
        $unitEncoded = base64_encode($unit);
        $socketEncoded = base64_encode($socket);
        $releaseFiles = $this->releaseFiles($release);
        $manifestEncoded = base64_encode($this->releaseManifest($release));
        $php = escapeshellarg($this->phpBinary);
        $loaded = FilePrivateDnsCatalogStore::loadedPath($catalogPath);
        $unitDirectory = $this->unitDirectory;
        $unitName = $this->units->name();
        $socketName = $this->units->socketName();
        $unitPath = $this->units->path($unitDirectory);
        $socketPath = $this->units->socketPath($unitDirectory);
        $vpnManaged = $this->recordsDirectory.'/'.$this->vpnFragmentFile;
        $ownership = $this->preserveRootOwnership ? '-o root -g root ' : '';
        $recordsFile = $this->recordsFile;
        $transformEncoded = base64_encode($this->vpnBackendTransformPython());

        return <<<BASH
            vpn_managed={$vpnManaged}
            unit_managed={$unitPath}
            socket_managed={$socketPath}
            unit_directory={$unitDirectory}
            unit_candidate={$unitDirectory}/.{$unitName}.\$\$.candidate
            socket_candidate={$unitDirectory}/.{$socketName}.\$\$.candidate
            vpn_candidate={$this->recordsDirectory}/.{$this->vpnFragmentFile}.\$\$.candidate
            unit_backup=\$(mktemp "\$validation/{$unitName}.backup.XXXXXX")
            socket_backup=\$(mktemp "\$validation/{$socketName}.backup.XXXXXX")
            vpn_backup=\$(mktemp "\$validation/{$this->vpnFragmentFile}.backup.XXXXXX")
            release_root={$releaseRoot}
            release_id={$releaseId}
            release_dir={$releaseRoot}/{$releaseId}
            release_candidate={$releaseRoot}/.{$releaseId}.\$\$.candidate
            catalog_loaded={$loaded}
            listen_addr={$listen}
            listen_port={$this->listenPort}
            had_unit=0
            had_socket=0
            had_vpn=0
            trap 'rm -rf -- "\$validation" "\$release_candidate" "\$release_candidate.replaced"; rm -f -- "\$candidate" "\$backup" "\$catalog_candidate" "\$catalog_backup" "\$unit_candidate" "\$socket_candidate" "\$vpn_candidate" "\$unit_backup" "\$socket_backup" "\$vpn_backup"' EXIT
            if [ -f "\$unit_managed" ]; then
                cp --preserve=mode,ownership -- "\$unit_managed" "\$unit_backup"
                had_unit=1
            fi
            if [ -f "\$socket_managed" ]; then
                cp --preserve=mode,ownership -- "\$socket_managed" "\$socket_backup"
                had_socket=1
            fi
            if [ -f "\$vpn_managed" ]; then
                cp --preserve=mode,ownership -- "\$vpn_managed" "\$vpn_backup"
                had_vpn=1
                printf '%s' '{$transformEncoded}' | base64 --decode > "\$validation/transform-vpn.py"
                python3 "\$validation/transform-vpn.py" "\$vpn_managed" "\$validation/fragments/{$this->vpnFragmentFile}"
            fi
            # An installed release must match the manifest of its id file by file; anything else is reinstalled.
            printf '%s' '{$manifestEncoded}' | base64 --decode > "\$validation/release.manifest"
            release_installed=0
            if ! cmp -s -- "\$validation/release.manifest" "\$release_dir/.manifest" \
                || ! (cd -- "\$release_dir" && sha256sum -c --quiet .manifest) > /dev/null 2>&1; then
                install -d -m 0755 -- "\$release_root"
                rm -rf -- "\$release_candidate"
                install -d -m 0755 -- "\$release_candidate"
            {$releaseFiles}
                install -m 0644 -- "\$validation/release.manifest" "\$release_candidate/.manifest"
                (cd -- "\$release_candidate" && sha256sum -c --quiet .manifest)
                {$php} "\$release_candidate/serve.php" --self-test
                if [ -e "\$release_dir" ]; then
                    mv -T -- "\$release_dir" "\$release_candidate.replaced"
                fi
                mv -T -- "\$release_candidate" "\$release_dir"
                rm -rf -- "\$release_candidate.replaced"
                release_installed=1
            fi
            printf '%s' '{$unitEncoded}' | base64 --decode > "\$validation/{$unitName}"
            printf '%s' '{$socketEncoded}' | base64 --decode > "\$validation/{$socketName}"
            systemd-analyze verify "\$validation/{$socketName}" "\$validation/{$unitName}"
            sed "s#{$this->recordsDirectory}#\$validation/fragments#g" {$this->dnsmasqConf} > "\$validation/dnsmasq.conf"
            dnsmasq --test --conf-file="\$validation/dnsmasq.conf"
            vpn_changed=0
            unit_changed=1
            socket_changed=1
            if [ -f "\$vpn_managed" ]; then
                vpn_changed=1
                if cmp -s -- "\$validation/fragments/{$this->vpnFragmentFile}" "\$vpn_managed"; then
                    vpn_changed=0
                fi
            fi
            if [ -f "\$unit_managed" ] && cmp -s -- "\$validation/{$unitName}" "\$unit_managed"; then
                unit_changed=0
            fi
            if [ -f "\$socket_managed" ] && cmp -s -- "\$validation/{$socketName}" "\$socket_managed"; then
                socket_changed=0
            fi
            listener_pid() {
                systemctl show -p MainPID --value {$unitName} 2>/dev/null || true
            }
            listener_running() {
                local pid
                pid=\$(listener_pid)
                [ -n "\$pid" ] && [ "\$pid" != 0 ]
            }
            php_owns_vpn_dns() {
                local pid
                pid=\$(listener_pid)
                if [ -z "\$pid" ] || [ "\$pid" = 0 ]; then
                    return 1
                fi
                ss -4 -ulpnH src "\${listen_addr}:\${listen_port}" 2>/dev/null | grep -q "pid=\${pid}," || return 1
                ss -4 -tlnpH src "\${listen_addr}:\${listen_port}" 2>/dev/null | grep -q "pid=\${pid},"
            }
            wait_until_php_owns_vpn_dns() {
                local n=0
                while [ "\$n" -lt 100 ]; do
                    if php_owns_vpn_dns; then
                        return 0
                    fi
                    if ! systemctl is-active --quiet {$unitName}; then
                        return 1
                    fi
                    sleep 0.1
                    n=\$((n + 1))
                done
                return 1
            }
            catalog_digest() {
                sha256sum -- "\$catalog_managed" | cut -d ' ' -f 1
            }
            # A listener rewrites the confirmation each time it loads the catalog. A confirmation that is missing
            # while the catalog is unchanged comes from code that never writes one, so it counts as current.
            listener_confirms_catalog() {
                local expected loaded n=0
                expected=\$(catalog_digest)
                while :; do
                    loaded=\$(cat -- "\$catalog_loaded" 2>/dev/null || true)
                    if [ "\$loaded" = "\$expected" ]; then
                        return 0
                    fi
                    if [ "\$1" = 0 ] && [ -z "\$loaded" ]; then
                        return 0
                    fi
                    if [ "\$n" -ge 50 ]; then
                        return 1
                    fi
                    sleep 0.1
                    n=\$((n + 1))
                done
            }
            # A listener loads the catalog when it starts. One that writes confirmations confirms it within 5 s;
            # one that never writes them leaves the removed confirmation missing, and later counts as current.
            started_listener_confirms() {
                local expected n=0
                expected=\$(catalog_digest)
                while [ "\$n" -lt 50 ]; do
                    if [ "\$(cat -- "\$catalog_loaded" 2>/dev/null || true)" = "\$expected" ]; then
                        return 0
                    fi
                    sleep 0.1
                    n=\$((n + 1))
                done
                [ ! -e "\$catalog_loaded" ]
            }
            # The sockets stay open in orbit-private-dns.socket, so queries wait for the next listener instead of
            # failing. The confirmation is removed first, so only the new listener can write it.
            restart_listener() {
                rm -f -- "\$catalog_loaded"
                systemctl restart {$unitName} || return 1
                wait_until_php_owns_vpn_dns || return 1
                started_listener_confirms
            }
            restore_listener() {
                if [ "\$had_managed" = 1 ]; then
                    install {$ownership}-m 0644 -- "\$backup" "\$managed"
                else
                    rm -f -- "\$managed"
                fi
                if [ "\$had_catalog" = 1 ]; then
                    install {$ownership}-m 0644 -- "\$catalog_backup" "\$catalog_managed"
                else
                    rm -f -- "\$catalog_managed"
                fi
                if [ "\$had_vpn" = 1 ]; then
                    install {$ownership}-m 0644 -- "\$vpn_backup" "\$vpn_managed"
                elif [ -n "\${vpn_managed:-}" ]; then
                    rm -f -- "\$vpn_managed"
                fi
                if [ "\$had_unit" = 1 ]; then
                    install {$ownership}-m 0644 -- "\$unit_backup" "\$unit_managed"
                else
                    rm -f -- "\$unit_managed"
                    systemctl disable --now {$unitName} || true
                fi
                if [ "\$had_socket" = 1 ]; then
                    install {$ownership}-m 0644 -- "\$socket_backup" "\$socket_managed"
                else
                    rm -f -- "\$socket_managed"
                fi
                systemctl daemon-reload || true
                systemctl restart dnsmasq || true
                if [ "\$had_socket" = 0 ]; then
                    systemctl stop {$unitName} || true
                    systemctl disable --now {$socketName} || true
                    if [ "\$had_unit" = 1 ]; then
                        systemctl enable --now {$unitName} || true
                    fi
                elif [ "\$had_unit" = 1 ]; then
                    systemctl restart {$unitName} || true
                fi
            }
            if [ "\$records_changed" = 0 ] && [ "\$catalog_changed" = 0 ] && [ "\$vpn_changed" = 0 ] && [ "\$unit_changed" = 0 ] && [ "\$socket_changed" = 0 ] && [ "\$release_installed" = 0 ]; then
                if systemctl is-active --quiet dnsmasq && systemctl is-active --quiet {$socketName} && systemctl is-active --quiet {$unitName} && php_owns_vpn_dns && listener_confirms_catalog 0; then
                    exit 0
                fi
            fi
            if [ "\$records_changed" = 1 ]; then
                install {$ownership}-m 0644 -- "\$validation/fragments/{$recordsFile}" "\$candidate"
                mv -fT -- "\$candidate" "\$managed"
            fi
            if [ "\$catalog_changed" = 1 ]; then
                install {$ownership}-m 0644 -- "\$validation/catalog.json" "\$catalog_candidate"
                mv -fT -- "\$catalog_candidate" "\$catalog_managed"
            fi
            if [ "\$unit_changed" = 1 ] || [ "\$socket_changed" = 1 ]; then
                install -d -m 0755 -- "\$unit_directory"
                install {$ownership}-m 0644 -- "\$validation/{$unitName}" "\$unit_candidate"
                mv -fT -- "\$unit_candidate" "\$unit_managed"
                install {$ownership}-m 0644 -- "\$validation/{$socketName}" "\$socket_candidate"
                mv -fT -- "\$socket_candidate" "\$socket_managed"
                systemctl daemon-reload
            fi
            listener_started=0
            if ! systemctl is-active --quiet {$socketName} || [ "\$socket_changed" = 1 ]; then
                # The address moves to the socket unit. A listener that binds the address itself must let go first.
                # Enabling first keeps the gap between the stop and the socket bind to milliseconds.
                listener_started=1
                rm -f -- "\$catalog_loaded"
                if ! systemctl enable {$socketName} {$unitName}; then
                    restore_listener
                    exit 1
                fi
                systemctl stop {$unitName} {$socketName} || true
                if ! systemctl start {$socketName} || ! systemctl start {$unitName}; then
                    restore_listener
                    exit 1
                fi
            elif [ "\$unit_changed" = 1 ] || [ "\$release_installed" = 1 ] || ! listener_running; then
                listener_started=1
                rm -f -- "\$catalog_loaded"
                if ! systemctl enable {$unitName} || ! systemctl restart {$unitName}; then
                    restore_listener
                    exit 1
                fi
            fi
            if ! listener_running; then
                restore_listener
                exit 1
            fi
            if [ "\$vpn_changed" = 1 ]; then
                install {$ownership}-m 0644 -- "\$validation/fragments/{$this->vpnFragmentFile}" "\$vpn_candidate"
                mv -fT -- "\$vpn_candidate" "\$vpn_managed"
            fi
            if [ "\$records_changed" = 1 ] || [ "\$vpn_changed" = 1 ] || ! systemctl is-active --quiet dnsmasq; then
                if ! systemctl restart dnsmasq; then
                    restore_listener
                    exit 1
                fi
            fi
            if ! wait_until_php_owns_vpn_dns; then
                restore_listener
                exit 1
            fi
            if [ "\$listener_started" = 1 ]; then
                if ! started_listener_confirms; then
                    restore_listener
                    exit 1
                fi
            elif ! listener_confirms_catalog "\$catalog_changed"; then
                if ! restart_listener; then
                    restore_listener
                    exit 1
                fi
            fi
            for release in "\$release_root"/*/; do
                release=\${release%/}
                if [ -d "\$release" ] && [ "\${release##*/}" != "\$release_id" ] && ! grep -qF -- "\$release/" "\$unit_backup"; then
                    rm -rf -- "\$release"
                fi
            done
            BASH;
    }

    /**
     * `sha256sum -c` input for the release files.
     */
    private function releaseManifest(PrivateDnsListenerRelease $release): string
    {
        $manifest = '';
        foreach ($release->files() as $path => $contents) {
            $manifest .= hash('sha256', $contents).'  '.$path."\n";
        }

        return $manifest;
    }

    /**
     * Shell lines that write each release file into `$release_candidate`.
     */
    private function releaseFiles(PrivateDnsListenerRelease $release): string
    {
        $lines = [];
        $directories = [];

        foreach ($release->files() as $path => $contents) {
            $directory = dirname($path);
            if ($directory !== '.' && ! isset($directories[$directory])) {
                $directories[$directory] = true;
                $lines[] = '    install -d -m 0755 -- "$release_candidate/'.$directory.'"';
            }

            $lines[] = "    printf '%s' '".base64_encode($contents)."' | base64 --decode > \"\$release_candidate/{$path}\"";
        }

        return implode("\n", $lines);
    }

    private function vpnBackendTransformPython(): string
    {
        $address = $this->backendAddress;

        return <<<PYTHON
            from pathlib import Path
            import sys
            src = Path(sys.argv[1]).read_text()
            lines = []
            saw_listen = False
            saw_bind = False
            for line in src.splitlines():
                if line.startswith("interface=") or line == "bind-dynamic":
                    continue
                if line.startswith("listen-address="):
                    lines.append("listen-address={$address}")
                    saw_listen = True
                    continue
                if line == "bind-interfaces":
                    saw_bind = True
                lines.append(line)
            insert = []
            if not saw_listen:
                insert.append("listen-address={$address}")
            if not saw_bind:
                insert.append("bind-interfaces")
            if insert:
                if lines and lines[0].startswith("#"):
                    lines[1:1] = insert
                else:
                    lines = insert + lines
            while lines and lines[-1] == "":
                lines.pop()
            Path(sys.argv[2]).write_text("\\n".join(lines) + "\\n")
            PYTHON;
    }

    private function recordsOnlyActivation(): string
    {
        $ownership = $this->preserveRootOwnership ? '-o root -g root ' : '';
        $recordsFile = $this->recordsFile;

        return <<<BASH
            if [ "\$records_changed" = 0 ] && [ "\$catalog_changed" = 0 ]; then
                if systemctl is-active --quiet dnsmasq; then
                    exit 0
                fi
                systemctl restart dnsmasq
                exit 0
            fi
            if [ "\$records_changed" = 1 ]; then
                install {$ownership}-m 0644 -- "\$validation/fragments/{$recordsFile}" "\$candidate"
                mv -fT -- "\$candidate" "\$managed"
            fi
            if [ "\$catalog_changed" = 1 ]; then
                install {$ownership}-m 0644 -- "\$validation/catalog.json" "\$catalog_candidate"
                mv -fT -- "\$catalog_candidate" "\$catalog_managed"
            fi
            if [ "\$records_changed" = 0 ] && systemctl is-active --quiet dnsmasq; then
                exit 0
            fi
            if ! systemctl restart dnsmasq; then
                if [ "\$had_managed" = 1 ]; then
                    install {$ownership}-m 0644 -- "\$backup" "\$managed"
                else
                    rm -f -- "\$managed"
                fi
                if [ "\$had_catalog" = 1 ]; then
                    install {$ownership}-m 0644 -- "\$catalog_backup" "\$catalog_managed"
                else
                    rm -f -- "\$catalog_managed"
                fi
                systemctl restart dnsmasq || true
                exit 1
            fi
            BASH;
    }

    private function resolvedListenAddress(): ?string
    {
        if (is_string($this->listenAddress) && $this->listenAddress !== '') {
            return DnsAddress::normalize($this->listenAddress) ?? $this->listenAddress;
        }

        $configured = $this->vpnSettings?->dnsServer();
        if (is_string($configured) && $configured !== '') {
            return DnsAddress::normalize($configured) ?? $configured;
        }

        $listener = $this->roleHolder(RoleName::Vpn) ?? $this->roleHolder(RoleName::Gateway);

        if ($listener instanceof Node && is_string($listener->wireguard_ip) && $listener->wireguard_ip !== '') {
            return DnsAddress::normalize($listener->wireguard_ip) ?? $listener->wireguard_ip;
        }

        return null;
    }

    /**
     * The Node that holds a singleton role. An active Node wins. `node:role:add --converge`
     * marks the assignment provisioning while the Node stays active, and `node:add` marks the
     * Node provisioning while the assignment stays active, so both count.
     */
    private function roleHolder(RoleName $role): ?Node
    {
        foreach ([LifecycleStatus::Active, LifecycleStatus::Provisioning] as $nodeStatus) {
            foreach ([LifecycleStatus::Active, LifecycleStatus::Provisioning] as $roleStatus) {
                $holder = Node::query()
                    ->where('status', $nodeStatus)
                    ->whereNotNull('wireguard_ip')
                    ->whereHas(
                        'roles',
                        static fn ($query) => $query
                            ->where('role', $role)
                            ->where('status', $roleStatus),
                    )
                    ->first();

                if ($holder instanceof Node) {
                    return $holder;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     */
    private function publication(
        ?Node $pendingNode,
        array $nodeOverrides,
        array $clusterOverrides,
    ): string {
        try {
            return json_encode([
                'requesters' => $this->renderer->registeredRequesters(),
                ...$this->renderer->catalog($pendingNode, $nodeOverrides, $clusterOverrides)->toPublished(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        } catch (JsonException $exception) {
            throw new RuntimeConvergenceException(
                step: 'private-dns',
                errorCode: 'app-dev.dns_config_failed',
                message: 'Could not encode Orbit private DNS requester catalog.',
                previous: $exception,
            );
        }
    }

    private function owner(): DevelopmentProjectionOperationLock
    {
        return $this->projection ?? app(DevelopmentProjectionOperationLock::class);
    }
}
