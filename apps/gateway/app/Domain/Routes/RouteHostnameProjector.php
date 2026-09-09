<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Models\AppInstance;
use App\Models\Route;

/** @mago-expect lint:too-many-methods Each method names one ordered hostname convergence boundary. */
interface RouteHostnameProjector
{
    public function prepareWorkloadCertificate(AppInstance $appInstance, Route $current, Route $candidate): void;

    public function prepareWorkloadCaddy(AppInstance $appInstance, Route $current, Route $candidate): void;

    public function prepareRouterCertificate(AppInstance $appInstance, Route $current, Route $candidate): void;

    public function prepareFirewallPolicy(AppInstance $appInstance, Route $candidate): void;

    public function verifyWorkload(AppInstance $appInstance, Route $candidate): void;

    public function prepareRouterCaddy(AppInstance $appInstance, Route $current, Route $candidate): void;

    public function publishDns(Route $current, Route $candidate): void;

    public function cleanup(AppInstance $appInstance, Route $route): void;

    public function rollbackDns(Route $route): void;

    public function rollbackCaddy(AppInstance $appInstance, Route $route): void;

    public function rollbackCertificates(AppInstance $appInstance, Route $route): void;
}
