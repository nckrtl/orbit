<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Domain\AgentView\AgentViewConverger;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Gateway\GatewayWebConverger;
use App\Domain\Hibernation\RuntimeHibernatorConverger;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Models\Node;

final readonly class NativeGatewayWebConverger implements GatewayWebConverger
{
    public function __construct(
        private GatewayCertificateIssuer $certificates,
        private GatewayFpmConfigRenderer $fpmRenderer,
        private ProtectedFileWriter $files,
        private GatewayCheckoutAccessConverger $checkout,
        private GatewayWebDirectoryConverger $webDirectory,
        private NativeGatewayCertificatePublisher $certificatePublisher,
        private NativeGatewayFpmConverger $fpm,
        private NodeCaddyBuilds $builds,
        private NativeGatewayCaddyInstaller $caddyInstaller,
        private string $orbitHome,
        private string $checkoutPath,
        private RuntimeHibernatorConverger $hibernator,
        private AgentViewConverger $agentView,
    ) {}

    /**
     * Installs Caddy first: the checkout, web directory, and certificate steps grant the `caddy`
     * group access, and the build validates with the installed release. The Gateway site renders
     * from the Gateway's Node and configuration, so the certificate comes before the Node Caddy build.
     */
    public function converge(Node $node, string $hostname, string $wireguardIp): void
    {
        $this->checkout->validate();
        $this->caddyInstaller->install();
        $this->checkout->converge();
        $this->webDirectory->converge();
        $certificate = $this->certificates->issue($hostname, $wireguardIp);
        $generatedFpmPool = rtrim(string: $this->orbitHome, characters: '/').'/generated/gateway/php-fpm-pool.conf';
        $this->files->put(
            $generatedFpmPool,
            $this->fpmRenderer->renderPool($this->checkoutPath, $this->orbitHome),
            0o644,
        );
        $this->certificatePublisher->publish($certificate);
        $this->fpm->converge($generatedFpmPool);
        $this->build($node);
        $this->hibernator->converge();
        $this->agentView->converge();
    }

    private function build(Node $node): void
    {
        try {
            $this->builds->build($node);
        } catch (NodeCaddyBuildException $exception) {
            [$step, $errorCode] = match ($exception->stage) {
                'render', 'validate' => ['gateway-caddy-validate', 'gateway.caddy_config_invalid'],
                'reload' => ['gateway-caddy-reload', 'gateway.caddy_start_failed'],
                default => ['gateway-caddy-publish', 'gateway.caddy_config_install_failed'],
            };

            throw new NodeProvisioningException(
                step: $step,
                errorCode: $errorCode,
                message: $exception->getMessage(),
                previous: $exception,
                result: $exception->result(),
            );
        }
    }
}
