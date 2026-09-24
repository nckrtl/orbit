<?php

declare(strict_types=1);

use App\Domain\AppInstances\ProductionPhpRuntimeIdentity;
use App\Infrastructure\AppInstances\ProductionPhpRuntimeConfigRenderer;
use App\Infrastructure\Caddy\CaddyGlobalOptions;
use App\Infrastructure\Metrics\ServiceMetricsConfigRenderer;
use App\Infrastructure\Metrics\ServiceMetricsDashboardRenderer;

it('exposes only metrics on the private Caddy listener', function (): void {
    $config = new ServiceMetricsConfigRenderer()->caddy('10.44.0.3', '10.44.0.2');
    expect($config)->toContain('http://10.44.0.3:9103', 'bind 10.44.0.3', '@scraper remote_ip 10.44.0.2', 'metrics /metrics', 'respond 403')->not->toContain('admin ', 'reverse_proxy');
});

it('refuses an invalid scrape address', function (string $address): void {
    expect(fn () => new ServiceMetricsConfigRenderer()->caddy($address, '10.44.0.2'))->toThrow(InvalidArgumentException::class);
})->with(['', "10.44.0.3\nadmin :2019", '0.0.0.0/0', 'example.test']);

it('adds isolated FPM status without changing tuning or master identity', function (): void {
    $identity = new ProductionPhpRuntimeIdentity('orbit-app-1', '/home/orbit-app-1', '8.5', 'orbit-orbit-app-1-php8.5-fpm.service', 'orbit-orbit-app-1', '/run/php/orbit-app-1.sock', '/home/orbit-app-1/public');
    $renderer = new ProductionPhpRuntimeConfigRenderer;
    $off = $renderer->render($identity);
    $on = $renderer->render($identity, true);
    expect($on->pool)->toContain('pm.status_path = /orbit-fpm-status', 'pm.status_listen = /run/php/orbit-app-1.sock.status');
    expect($off->pool)->not->toContain('pm.status_');
    expect([$on->main, $on->localDefaults, $on->masterIni, $on->unit])->toBe([$off->main, $off->localDefaults, $off->masterIni, $off->unit]);
});

it('uses rate histograms and does not replace missing cache data with zero', function (): void {
    $renderer = new ServiceMetricsDashboardRenderer;
    $caddy = json_decode($renderer->render('caddy'), true, flags: JSON_THROW_ON_ERROR);
    $fpm = json_decode($renderer->render('fpm'), true, flags: JSON_THROW_ON_ERROR);
    expect($caddy['panels'][3]['targets'][0]['expr'])->toContain('histogram_quantile(0.95', 'rate(', 'handler="subroute"');
    expect($fpm['panels'][7]['targets'][0]['expr'])->toContain('phpfpm_opcache_enabled', '== 1')->not->toContain('or vector(0)');
});

it('leaves per-host collection to the Orbit global options block', function (): void {
    $fragment = new ServiceMetricsConfigRenderer()->caddy('10.44.0.3', '10.44.0.2');
    $adapted = caddy_adapt(CaddyGlobalOptions::render().$fragment);

    expect($fragment)->toStartWith(ServiceMetricsConfigRenderer::Marker."\nhttp://10.44.0.3:9103 {")
        ->not->toContain('per_host')
        ->and($adapted->succeeded())->toBeTrue()
        ->and($adapted->stdout)->toContain('"metrics":{"per_host":true}');
});
