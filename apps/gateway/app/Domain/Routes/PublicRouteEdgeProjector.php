<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Models\Route;

interface PublicRouteEdgeProjector
{
    public function artifact(Route $route): IngressSite;

    public function privateOverride(Route $route): PublicRoutePrivateOverride;

    public function prepareIngressCertificate(Route $route): void;

    public function verifyPublicEdge(Route $route): void;

    public function activatePublicHandler(Route $route): void;

    public function prepareIngressFirewall(Route $route): void;

    public function rollbackPublicEdge(Route $route): void;

    public function removePublicEdge(Route $route): void;
}
