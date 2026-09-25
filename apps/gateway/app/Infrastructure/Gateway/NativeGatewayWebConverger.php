<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Domain\AgentView\AgentViewConverger;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Gateway\GatewayWebConverger;
use App\Domain\Hibernation\RuntimeHibernatorConverger;
use App\Infrastructure\Files\ProtectedFileWriter;

final readonly class NativeGatewayWebConverger implements GatewayWebConverger
{
    public function __construct(
        private GatewayCertificateIssuer $certificates,
        private GatewayCaddyConfigRenderer $caddyRenderer,
        private GatewayFpmConfigRenderer $fpmRenderer,
        private ProtectedFileWriter $files,
        private GatewayCheckoutAccessConverger $checkout,
        private GatewayWebDirectoryConverger $webDirectory,
        private NativeGatewayCertificatePublisher $certificatePublisher,
        private NativeGatewayFpmConverger $fpm,
        private NativeGatewayCaddyConverger $caddy,
        private NativeGatewayCaddyInstaller $caddyInstaller,
        private string $orbitHome,
        private string $checkoutPath,
        private string $webRoot,
        private RuntimeHibernatorConverger $hibernator,
        private AgentViewConverger $agentView,
    ) {}

    /**
     * Installs Caddy first: the checkout, web directory, and certificate steps grant the `caddy`
     * group access, and the Caddy step validates with the installed release.
     */
    public function converge(string $hostname, string $wireguardIp): void
    {
        $this->checkout->validate();
        $this->caddyInstaller->install();
        $this->checkout->converge();
        $this->webDirectory->converge();
        $certificate = $this->certificates->issue($hostname, $wireguardIp);
        $generatedDirectory = rtrim(string: $this->orbitHome, characters: '/').'/generated/gateway';
        $generatedFpmPool = $generatedDirectory.'/php-fpm-pool.conf';
        $generatedCaddy = $generatedDirectory.'/Caddyfile';
        $this->files->put(
            $generatedFpmPool,
            $this->fpmRenderer->renderPool($this->checkoutPath, $this->orbitHome),
            0o644,
        );
        $this->files->put(
            $generatedCaddy,
            $this->caddyRenderer->render($hostname, $wireguardIp, $this->checkoutPath, $this->webRoot),
            0o644,
        );
        $this->certificatePublisher->publish($certificate);
        $this->fpm->converge($generatedFpmPool);
        $this->caddy->converge($generatedCaddy);
        $this->hibernator->converge();
        $this->agentView->converge();
    }
}
