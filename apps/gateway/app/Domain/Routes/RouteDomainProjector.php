<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Models\Instance;
use App\Models\Route;

interface RouteDomainProjector
{
    public function prepareWorkloadCertificate(Instance $instance, Route $current, Route $candidate): void;

    public function prepareWorkloadCaddy(Instance $instance, Route $current, Route $candidate): void;

    public function prepareRouterCertificate(Instance $instance, Route $current, Route $candidate): void;

    public function prepareFirewallPolicy(Instance $instance, Route $candidate): void;

    public function verifyWorkload(Instance $instance, Route $candidate): void;

    public function prepareRouterCaddy(Instance $instance, Route $current, Route $candidate): void;

    public function prepareIngressCertificate(Route $candidate): void;

    public function prepareIngressFirewall(Route $candidate): void;

    public function verifyPublicEdge(Route $candidate): void;

    public function activatePublicHandler(Route $candidate): void;

    public function publishDns(Route $current, Route $candidate): void;

    /**
     * Runs after cutover and before the `cleanup` step is stored: issues the live certificates of
     * the Route the Node now serves, so the builds that follow can name them.
     */
    public function prepareCleanup(Instance $instance, Route $route): void;

    /**
     * Runs after the `cleanup` step is stored, so `$route` is the Route the Node now serves, never
     * the retiring one. It builds the Nodes and then removes the staging and old certificates.
     */
    public function cleanup(Instance $instance, Route $route): void;

    public function rollbackDns(Route $route): void;

    public function rollbackCaddy(Instance $instance, Route $route): void;

    public function rollbackCertificates(Instance $instance, Route $route): void;

    public function rollbackPublicEdge(Route $route): void;
}
