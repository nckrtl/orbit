<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use App\Domain\Analytics\AnalyticsHostname;
use App\Domain\Analytics\AnalyticsPublicationManager;
use App\Domain\Analytics\PlausibleProcess;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

final readonly class NativeAnalyticsPublicationManager implements AnalyticsPublicationManager
{
    public function __construct(
        private GatewayCertificateIssuer $certificates,
        private AnalyticsCertificatePublisher $certificatePublisher,
        private AnalyticsCaddyPublisher $caddy,
        private AnalyticsCaddySiteRenderer $site,
        private PrivateDnsManager $dns,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function converge(Node $node): void
    {
        $address = $this->address($node);
        $certificate = $this->certificates->issue(AnalyticsHostname::Value, $address);
        $certificatePem = $this->read($certificate->certificatePath);
        $privateKeyPem = $this->read($certificate->privateKeyPath);

        $certificateResult = $this->ssh->execute(
            $this->connection($node, $address),
            $this->certificatePublisher->command($certificatePem, $privateKeyPem),
        );

        if (! $certificateResult->succeeded()) {
            throw new NodeRoleOperationException(
                'analytics-certificate',
                'node_role.convergence_failed',
                'analytics.certificate_publication_failed',
                "Analytics certificate publication failed on node [{$node->name}].",
                $certificateResult,
            );
        }

        $configuration = $this->site->render($address);
        $caddyResult = $this->ssh->execute(
            $this->connection($node, $address),
            $this->caddy->command($configuration, (string) PlausibleProcess::PORT, $address),
        );

        if (! $caddyResult->succeeded()) {
            throw new NodeRoleOperationException(
                'analytics-caddy',
                'node_role.convergence_failed',
                'analytics.caddy_publication_failed',
                "Analytics Caddy publication failed on node [{$node->name}].",
                $caddyResult,
            );
        }

        $this->dns->converge($node);
    }

    public function remove(Node $node): void
    {
        $address = $this->address($node);

        $this->ssh->execute($this->connection($node, $address), $this->caddy->removeCommand());
        $this->ssh->execute($this->connection($node, $address), $this->certificatePublisher->removeCommand());
        $this->dns->converge();
    }

    public function removeUnreachable(Node $node): void
    {
        $this->dns->converge();
    }

    private function connection(Node $node, string $address): SshConnection
    {
        return new SshConnection(
            $address,
            $node->user,
            22,
            $this->keys->privateKeyPath(),
            $this->knownHosts->path(),
        );
    }

    private function address(Node $node): string
    {
        $address = $node->wireguard_ip;

        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new NodeRoleOperationException(
                'analytics-publication',
                'node_role.convergence_failed',
                'analytics.publication_address_invalid',
                "Node [{$node->name}] has no valid WireGuard IPv4 address.",
            );
        }

        return $address;
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new NodeRoleOperationException(
                'analytics-certificate',
                'node_role.convergence_failed',
                'analytics.certificate_read_failed',
                "Could not read the issued analytics certificate at [{$path}].",
            );
        }

        return $contents;
    }
}
