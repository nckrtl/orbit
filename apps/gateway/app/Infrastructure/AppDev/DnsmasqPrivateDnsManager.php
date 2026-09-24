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
        private string $checkoutPath = '',
        private string $phpBinary = '/usr/bin/php8.5',
        private string $unitDirectory = '/etc/systemd/system',
        private string $vpnFragmentFile = 'orbit-vpn.conf',
        private string $backendAddress = VpnDnsmasqBackendListen::Address,
        private int $listenPort = 53,
        private ?string $orbitHome = null,
        private ?VpnSettings $vpnSettings = null,
        private PrivateDnsListenerUnitRenderer $units = new PrivateDnsListenerUnitRenderer,
        private ?SshExecutor $ssh = null,
        private ?SshKeyProvider $keys = null,
        private ?KnownHostsStore $knownHosts = null,
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
        $listener = $remote instanceof Node
            ? $this->recordsOnlyActivation($this->resolvedListenAddress())
            : $this->listenerPublication();
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

    private function listenerPublication(): string
    {
        $listen = $this->resolvedListenAddress();
        if (! $this->activateListener || $listen === null) {
            return $this->recordsOnlyActivation($listen);
        }

        $unit = $this->units->render(
            phpBinary: $this->phpBinary,
            artisan: $this->checkout().'/artisan',
            listenAddress: $listen,
            port: $this->listenPort,
            catalogPath: $this->catalogDirectory.'/'.$this->catalogFile,
            upstream: $this->backendAddress.':53',
            orbitHome: $this->home(),
            workingDirectory: $this->checkout(),
        );
        $unitEncoded = base64_encode($unit);
        $helpers = $this->listenerHelpers($listen);
        $unitDirectory = $this->unitDirectory;
        $unitName = $this->units->name();
        $unitPath = $this->units->path($unitDirectory);
        $vpnManaged = $this->recordsDirectory.'/'.$this->vpnFragmentFile;
        $ownership = $this->preserveRootOwnership ? '-o root -g root ' : '';
        $recordsFile = $this->recordsFile;
        $transformEncoded = base64_encode($this->vpnBackendTransformPython());

        return <<<BASH
            vpn_managed={$vpnManaged}
            unit_managed={$unitPath}
            unit_directory={$unitDirectory}
            unit_candidate={$unitDirectory}/.{$unitName}.\$\$.candidate
            vpn_candidate={$this->recordsDirectory}/.{$this->vpnFragmentFile}.\$\$.candidate
            unit_backup=\$(mktemp "\$validation/{$unitName}.backup.XXXXXX")
            vpn_backup=\$(mktemp "\$validation/{$this->vpnFragmentFile}.backup.XXXXXX")
            had_unit=0
            had_vpn=0
            trap 'rm -rf -- "\$validation"; rm -f -- "\$candidate" "\$backup" "\$catalog_candidate" "\$catalog_backup" "\$unit_candidate" "\$vpn_candidate" "\$unit_backup" "\$vpn_backup"' EXIT
            if [ -f "\$unit_managed" ]; then
                cp --preserve=mode,ownership -- "\$unit_managed" "\$unit_backup"
                had_unit=1
            fi
            if [ -f "\$vpn_managed" ]; then
                cp --preserve=mode,ownership -- "\$vpn_managed" "\$vpn_backup"
                had_vpn=1
                printf '%s' '{$transformEncoded}' | base64 --decode > "\$validation/transform-vpn.py"
                python3 "\$validation/transform-vpn.py" "\$vpn_managed" "\$validation/fragments/{$this->vpnFragmentFile}"
            fi
            printf '%s' '{$unitEncoded}' | base64 --decode > "\$validation/{$unitName}"
            systemd-analyze verify "\$validation/{$unitName}"
            sed "s#{$this->recordsDirectory}#\$validation/fragments#g" {$this->dnsmasqConf} > "\$validation/dnsmasq.conf"
            dnsmasq --test --conf-file="\$validation/dnsmasq.conf"
            vpn_changed=0
            unit_changed=1
            if [ -f "\$vpn_managed" ]; then
                vpn_changed=1
                if cmp -s -- "\$validation/fragments/{$this->vpnFragmentFile}" "\$vpn_managed"; then
                    vpn_changed=0
                fi
            fi
            if [ -f "\$unit_managed" ] && cmp -s -- "\$validation/{$unitName}" "\$unit_managed"; then
                unit_changed=0
            fi
            {$helpers}
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
                systemctl daemon-reload || true
                systemctl restart dnsmasq || true
                if [ "\$had_unit" = 1 ]; then
                    systemctl enable --now {$unitName} || true
                fi
            }
            if [ "\$records_changed" = 0 ] && [ "\$catalog_changed" = 0 ] && [ "\$vpn_changed" = 0 ] && [ "\$unit_changed" = 0 ]; then
                if systemctl is-active --quiet dnsmasq && systemctl is-active --quiet {$unitName} && php_owns_vpn_dns && listener_confirms_catalog; then
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
            if [ "\$unit_changed" = 1 ]; then
                install -d -m 0755 -- "\$unit_directory"
                install {$ownership}-m 0644 -- "\$validation/{$unitName}" "\$unit_candidate"
                mv -fT -- "\$unit_candidate" "\$unit_managed"
                systemctl daemon-reload
            fi
            # A listener that starts here loads the current catalog. One that already runs must confirm it.
            listener_started=0
            if ! listener_running; then
                listener_started=1
            fi
            if [ "\$unit_changed" = 1 ] || [ "\$listener_started" = 1 ]; then
                if ! systemctl enable --now {$unitName}; then
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
            if [ "\$listener_started" = 0 ] && ! listener_confirms_catalog; then
                if ! restart_listener; then
                    restore_listener
                    exit 1
                fi
            fi
            BASH;
    }

    /**
     * Shell functions that find the listener and confirm the catalog it serves. The listener writes the digest of
     * each catalog it loads next to the catalog. A listener whose code predates that confirmation never writes it,
     * so it counts as current while the catalog stays unchanged and is restarted when the catalog changes.
     */
    private function listenerHelpers(?string $listen): string
    {
        $unitName = $this->units->name();
        $loaded = FilePrivateDnsCatalogStore::loadedPath($this->catalogDirectory.'/'.$this->catalogFile);
        $listenAddress = $listen ?? '';

        return <<<BASH
            catalog_loaded={$loaded}
            listen_addr={$listenAddress}
            listen_port={$this->listenPort}
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
            listener_confirms_catalog() {
                local expected loaded n=0
                expected=\$(sha256sum -- "\$catalog_managed" | cut -d ' ' -f 1)
                while :; do
                    loaded=\$(cat -- "\$catalog_loaded" 2>/dev/null || true)
                    if [ "\$loaded" = "\$expected" ]; then
                        return 0
                    fi
                    if [ "\$catalog_changed" = 0 ] && [ -z "\$loaded" ]; then
                        return 0
                    fi
                    if [ "\$n" -ge 50 ]; then
                        return 1
                    fi
                    sleep 0.1
                    n=\$((n + 1))
                done
            }
            restart_listener() {
                systemctl restart {$unitName} || return 1
                if [ -n "\$listen_addr" ]; then
                    wait_until_php_owns_vpn_dns || return 1
                fi
                systemctl is-active --quiet {$unitName}
            }
            confirm_listener_catalog() {
                if ! systemctl is-active --quiet {$unitName}; then
                    return 0
                fi
                listener_confirms_catalog || restart_listener
            }
            BASH;
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

    /**
     * Publishes records and the catalog without managing the listener unit. A running listener must still confirm
     * the published catalog, or it is restarted so it serves that catalog.
     */
    private function recordsOnlyActivation(?string $listen): string
    {
        $ownership = $this->preserveRootOwnership ? '-o root -g root ' : '';
        $recordsFile = $this->recordsFile;
        $helpers = $this->listenerHelpers($listen);

        return <<<BASH
            {$helpers}
            if [ "\$records_changed" = 0 ] && [ "\$catalog_changed" = 0 ]; then
                if systemctl is-active --quiet dnsmasq; then
                    confirm_listener_catalog || exit 1
                    exit 0
                fi
                systemctl restart dnsmasq
                confirm_listener_catalog || exit 1
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
                confirm_listener_catalog || exit 1
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
            confirm_listener_catalog || exit 1
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
     * The Node that holds a singleton role. `node:role:add --converge` marks the assignment
     * provisioning while it runs, so a converging holder counts; an active holder wins when both exist.
     */
    private function roleHolder(RoleName $role): ?Node
    {
        return Node::query()
            ->where('status', LifecycleStatus::Active)
            ->whereNotNull('wireguard_ip')
            ->whereHas(
                'roles',
                static fn ($query) => $query
                    ->where('role', $role)
                    ->where('status', LifecycleStatus::Active),
            )
            ->first()
            ?? Node::query()
                ->where('status', LifecycleStatus::Active)
                ->whereNotNull('wireguard_ip')
                ->whereHas(
                    'roles',
                    static fn ($query) => $query
                        ->where('role', $role)
                        ->where('status', LifecycleStatus::Provisioning),
                )
                ->first();
    }

    private function checkout(): string
    {
        $checkout = $this->checkoutPath !== ''
            ? $this->checkoutPath
            : rtrim((string) config('orbit.gateway_checkout'), '/');

        return $checkout !== '' ? $checkout : base_path();
    }

    private function home(): string
    {
        if (is_string($this->orbitHome) && $this->orbitHome !== '') {
            return $this->orbitHome;
        }

        return rtrim((string) config('orbit.home'), '/');
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
