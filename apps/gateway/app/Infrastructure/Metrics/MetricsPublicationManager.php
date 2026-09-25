<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Metrics\MetricsGatewayResolver;
use App\Domain\Metrics\MetricsPublicationManager as PublicationManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\CaddySiteRoles;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Models\Node;
use InvalidArgumentException;

/**
 * Publishes `metrics.orbit` on the Gateway. The Metrics role row is the stored state: while it converges
 * or is active, a Node Caddy build of the Gateway renders the site (ADR 0141). The certificate comes
 * before the build, and the build withdraws the site before its certificate goes.
 */
final readonly class MetricsPublicationManager implements PublicationManager
{
    public function __construct(
        private GatewayCertificateIssuer $certificates,
        private MetricsCertificatePublisher $certificatePublisher,
        private NodeCaddyBuilds $builds,
        private MetricsPublicationSshExecutor $firewall,
        private PrivateDnsManager $dns,
        private ?DevelopmentProjectionOperationLock $projection = null,
        private ?MetricsGatewayResolver $gateways = null,
        private ?CaddySiteCertificates $siteCertificates = null,
    ) {}

    /**
     * A failure keeps the published certificate: a failed convergence still renders the site, and every
     * later build of the Gateway validates the certificate it names.
     */
    public function converge(Node $gateway, Node $metrics): void
    {
        $this->owner()->run(function () use ($gateway, $metrics): void {
            $gatewayAddress = $this->address($gateway);
            $this->address($metrics);
            $certificate = $this->certificates->issue('metrics.orbit', $gatewayAddress);
            $this->certificatePublisher->publish($certificate);
            $this->certificateRecords()->record($gateway->id, CaddySiteCertificates::Metrics);
            $this->firewall->converge($metrics, $gatewayAddress);
            $this->build($gateway);
            $this->dns->converge($metrics);
        });
    }

    /**
     * The Metrics role is already `removing`, or it moved to another Node, so the build withdraws the site
     * or points it at the new Node. The certificate goes only when no Metrics site renders any more.
     */
    public function remove(Node $gateway, Node $metrics): void
    {
        $this->owner()->run(function () use ($gateway, $metrics): void {
            $gatewayAddress = $this->address($gateway);
            $this->address($metrics);
            $this->dns->converge();
            $this->build($gateway);
            $this->firewall->remove($metrics, $gatewayAddress);
            $this->removeUnusedCertificate($gateway);
        });
    }

    public function abandon(Node $metrics): void
    {
        $this->firewall->abandon($metrics);
    }

    public function retract(Node $metrics): void
    {
        $this->owner()->run(function () use ($metrics): void {
            $this->address($metrics);
            $this->dns->converge();
            $gateway = ($this->gateways ?? new MetricsGatewayResolver)->resolve();
            $this->build($gateway);
            $this->removeUnusedCertificate($gateway);
        });
    }

    /** Every build of the Gateway validates the certificate a rendered Metrics site names. */
    private function removeUnusedCertificate(Node $gateway): void
    {
        if (CaddySiteRoles::serving(RoleName::Metrics) === []) {
            $this->certificatePublisher->remove();
            $this->certificateRecords()->forget($gateway->id, CaddySiteCertificates::Metrics);
        }
    }

    private function certificateRecords(): CaddySiteCertificates
    {
        return $this->siteCertificates ?? new CaddySiteCertificates;
    }

    private function build(Node $gateway): void
    {
        try {
            $this->builds->build($gateway);
        } catch (NodeCaddyBuildException $exception) {
            throw new ResourceOperationException(
                'metrics.caddy_publication_failed',
                $exception->getMessage(),
                502,
                $exception,
                $exception->details(),
            );
        }
    }

    private function address(Node $node): string
    {
        $address = $node->wireguard_ip;
        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('Metrics publication requires valid WireGuard IPv4 addresses.');
        }

        return $address;
    }

    private function owner(): DevelopmentProjectionOperationLock
    {
        return $this->projection ?? app(DevelopmentProjectionOperationLock::class);
    }
}
