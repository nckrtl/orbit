<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Models\Route;

interface RouteRemovalProjector
{
    public function cleanupDns(Route $route): void;

    public function cleanupCertificates(Route $route): void;

    public function cleanupCaddy(Route $route): void;

    public function cleanupFirewall(Route $route): void;
}
