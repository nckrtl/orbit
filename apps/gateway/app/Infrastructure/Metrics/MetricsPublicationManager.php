<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Metrics\MetricsPublicationManager as PublicationManager;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use InvalidArgumentException;
use Throwable;

final readonly class MetricsPublicationManager implements PublicationManager
{
    /** @mago-expect lint:excessive-parameter-list Each dependency owns one step of the atomic Metrics publication boundary. */
    public function __construct(
        private GatewayCertificateIssuer $certificates,
        private MetricsCertificatePublisher $certificatePublisher,
        private MetricsCaddyPublisher $caddy,
        private MetricsPublicationSshExecutor $firewall,
        private PrivateDnsManager $dns,
        private MetricsPublicationRenderer $renderer = new MetricsPublicationRenderer,
    ) {}

    public function converge(Node $gateway, Node $metrics): void
    {
        $gatewayAddress = $this->address($gateway);
        $metricsAddress = $this->address($metrics);
        $certificateChanged = false;
        $firewallChanged = false;
        $caddyChanged = false;

        try {
            $certificate = $this->certificates->issue('metrics.orbit', $gatewayAddress);
            $certificateChanged = $this->certificatePublisher->publish($certificate);
            $firewallChanged = $this->firewall->converge($metrics, $gatewayAddress);
            $caddyChanged = $this->caddy->publish($this->renderer->caddy($metricsAddress, $gatewayAddress));
            $this->dns->converge($metrics);
        } catch (Throwable $exception) {
            try {
                if ($caddyChanged) {
                    $this->caddy->remove();
                }

                if ($firewallChanged) {
                    $this->firewall->remove($metrics, $gatewayAddress);
                }

                if ($certificateChanged) {
                    $this->certificatePublisher->remove();
                }
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

    private function address(Node $node): string
    {
        $address = $node->wireguard_address;
        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('Metrics publication requires valid WireGuard IPv4 addresses.');
        }

        return $address;
    }
}
