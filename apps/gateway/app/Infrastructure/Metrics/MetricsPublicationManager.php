<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Metrics\MetricsPublicationManager as PublicationManager;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use InvalidArgumentException;
use Throwable;

final readonly class MetricsPublicationManager implements PublicationManager
{
    public function __construct(
        private GatewayCertificateIssuer $certificates,
        private MetricsCertificatePublisher $certificatePublisher,
        private MetricsCaddyPublisher $caddy,
        private MetricsPublicationSshExecutor $firewall,
        private PrivateDnsManager $dns,
        private MetricsPublicationRenderer $renderer = new MetricsPublicationRenderer,
        private ?DevelopmentProjectionOperationLock $projection = null,
    ) {}

    public function converge(Node $gateway, Node $metrics): void
    {
        $this->owner()->run(fn () => $this->convergeOwned($gateway, $metrics));
    }

    private function convergeOwned(Node $gateway, Node $metrics): void
    {
        $gatewayAddress = $this->address($gateway);
        $metricsAddress = $this->address($metrics);
        $certificateReceipt = MetricsPublicationReceipt::unchanged();
        $firewallChanged = false;
        $caddyReceipt = MetricsPublicationReceipt::unchanged();

        try {
            $certificate = $this->certificates->issue('metrics.orbit', $gatewayAddress);
            $certificateReceipt = $this->certificatePublisher->publish($certificate);
            $firewallChanged = $this->firewall->converge($metrics, $gatewayAddress);
            $caddyReceipt = $this->caddy->publish($this->renderer->caddy($metricsAddress, $gatewayAddress));
            $this->dns->converge($metrics);
        } catch (Throwable $exception) {
            try {
                $this->caddy->restore($caddyReceipt);

                if ($firewallChanged) {
                    $this->firewall->remove($metrics, $gatewayAddress);
                }

                $this->certificatePublisher->restore($certificateReceipt);
            } catch (Throwable) {
                throw new ResourceOperationException(
                    'metrics.publication_rollback_failed',
                    'Metrics publication rollback did not complete.',
                    502,
                );
            }

            throw $exception;
        }
    }

    public function remove(Node $gateway, Node $metrics): void
    {
        $this->owner()->run(fn () => $this->removeOwned($gateway, $metrics));
    }

    private function removeOwned(Node $gateway, Node $metrics): void
    {
        $gatewayAddress = $this->address($gateway);
        $this->address($metrics);
        $this->dns->converge();
        $this->caddy->remove();
        $this->firewall->remove($metrics, $gatewayAddress);
        $this->certificatePublisher->remove();
    }

    public function abandon(Node $metrics): void
    {
        $this->firewall->abandon($metrics);
    }

    public function retract(Node $metrics): void
    {
        $this->owner()->run(fn () => $this->retractOwned($metrics));
    }

    private function retractOwned(Node $metrics): void
    {
        $this->address($metrics);
        $this->dns->converge();
        $this->caddy->remove();
        $this->certificatePublisher->remove();
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
