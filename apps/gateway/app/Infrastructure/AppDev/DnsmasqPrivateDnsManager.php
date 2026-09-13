<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Clusters\ClusterState;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;
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
    ) {}

    public function converge(?Node $pendingNode = null): void
    {
        $this->owner()->run(fn () => $this->publish($pendingNode, null, null));
    }

    public function convergeRoute(Route $route): void
    {
        $this->owner()->run(fn () => $this->publish(null, $route, null));
    }

    public function convergeUnavailableRoute(Route $route, AppInstance $appInstance): void
    {
        $this->owner()->run(fn () => $this->publish(null, $route, $appInstance));
    }

    public function convergeHostnameChange(Route $candidate): void
    {
        $this->owner()->run(fn () => $this->publish(null, null, null, $candidate));
    }

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     */
    public function convergeSelection(array $nodeOverrides = [], array $clusterOverrides = []): void
    {
        $this->owner()->run(fn () => $this->publish(null, null, null, null, $nodeOverrides, $clusterOverrides));
    }

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     */
    private function publish(
        ?Node $pendingNode,
        ?Route $pendingRoute,
        ?AppInstance $unavailableInstance,
        ?Route $additionalRoute = null,
        array $nodeOverrides = [],
        array $clusterOverrides = [],
    ): void {
        $configuration = $this->renderer->render(
            $pendingNode,
            $pendingRoute,
            $unavailableInstance,
            $additionalRoute,
            $nodeOverrides,
            $clusterOverrides,
        );
        $catalog = $this->publication(
            $pendingNode,
            $pendingRoute,
            $unavailableInstance,
            $additionalRoute,
            $nodeOverrides,
            $clusterOverrides,
        );
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
        $result = $this->processes->run(new ProcessInvocation(
            arguments: $this->shell,
            timeout: 60.0,
            input: <<<BASH
                {$pathExport}managed={$recordsDirectory}/{$recordsFile}
                candidate={$recordsDirectory}/.orbit-records.\$\$.candidate
                catalog_managed={$catalogDirectory}/{$catalogFile}
                catalog_candidate={$catalogDirectory}/.orbit-catalog.\$\$.candidate
                catalog_directory={$catalogDirectory}
                install -d -m 0755 -- "\$catalog_directory" {$recordsDirectory}
                validation=\$(mktemp -d)
                backup=\$(mktemp {$recordsDirectory}/.orbit-records.backup.XXXXXX)
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
                BASH,
        ));

        if (! $result->succeeded()) {
            throw new RuntimeConvergenceException(
                step: 'private-dns',
                errorCode: 'app-dev.dns_config_failed',
                message: 'Could not converge Orbit private DNS records.',
                result: $result,
            );
        }
    }

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     */
    private function publication(
        ?Node $pendingNode,
        ?Route $pendingRoute,
        ?AppInstance $unavailableInstance,
        ?Route $additionalRoute,
        array $nodeOverrides,
        array $clusterOverrides,
    ): string {
        try {
            return json_encode([
                'requesters' => $this->renderer->registeredRequesters(),
                ...$this->renderer->catalog(
                    $pendingNode,
                    $pendingRoute,
                    $unavailableInstance,
                    $additionalRoute,
                    $nodeOverrides,
                    $clusterOverrides,
                )->toPublished(),
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
