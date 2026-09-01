<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Metrics\MetricsCredentialRuntime;
use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Metrics\MetricsPublicationManager;
use App\Domain\Metrics\MetricsRoleManager;
use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Domain\Metrics\MetricsStatusReader;
use App\Infrastructure\Metrics\MetricsRuntimeHost;
use App\Infrastructure\Nodes\Roles\MetricsRoleBaseline;

it('resolves every Metrics production boundary and role baseline', function (): void {
    expect([
        app(MetricsCredentialManager::class),
        app(MetricsCredentialRuntime::class),
        app(MetricsRuntimeHost::class),
        app(MetricsRuntimeLifecycle::class),
        app(MetricsExporterLifecycle::class),
        app(MetricsExporterProjection::class),
        app(MetricsPublicationManager::class),
        app(MetricsRoleManager::class),
        app(MetricsStatusReader::class),
        app(MetricsRoleBaseline::class),
    ])->each->toBeObject();
});
