<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Models\AppInstance;
use App\Models\Route;

interface RouteDomainProjector
{
    public function prepareWorkloadCertificate(AppInstance $appInstance, Route $current, Route $candidate): void;

    public function prepareWorkloadCaddy(AppInstance $appInstance, Route $current, Route $candidate): void;

    public function prepareRouterCertificate(AppInstance $appInstance, Route $current, Route $candidate): void;

    public function prepareFirewallPolicy(AppInstance $appInstance, Route $candidate): void;

    public function verifyWorkload(AppInstance $appInstance, Route $candidate): void;

    public function prepareRouterCaddy(AppInstance $appInstance, Route $current, Route $candidate): void;

    public function prepareIngressCertificate(Route $candidate): void;

    public function stageIngressCaddy(Route $candidate): void;

    public function prepareIngressFirewall(Route $candidate): void;

    public function verifyPublicEdge(Route $candidate): void;

    public function activatePublicHandler(Route $candidate): void;

    public function publishDns(Route $current, Route $candidate): void;

    public function cleanup(AppInstance $appInstance, Route $route): void;

    public function rollbackDns(Route $route): void;

    public function rollbackCaddy(AppInstance $appInstance, Route $route): void;

    public function rollbackCertificates(AppInstance $appInstance, Route $route): void;

    public function rollbackPublicEdge(Route $route): void;
}
