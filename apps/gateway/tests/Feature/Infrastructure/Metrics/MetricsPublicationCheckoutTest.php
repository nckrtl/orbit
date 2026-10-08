<?php

declare(strict_types=1);

use App\Infrastructure\Metrics\MetricsPublicationRenderer;

it('renders the running Gateway with the stable application path, not the running release', function (): void {
    $base = sys_get_temp_dir().'/orbit-metrics-checkout-'.bin2hex(random_bytes(4));
    mkdir($base.'/releases/0123456789ab/apps/gateway', 0700, true);
    symlink('releases/0123456789ab', $base.'/orbit');
    config(['orbit.gateway_checkout' => $base.'/orbit/apps/gateway']);

    try {
        $configuration = MetricsPublicationRenderer::forGateway()->caddy('10.44.0.2', '10.44.0.1');
    } finally {
        exec('rm -rf '.escapeshellarg($base));
    }

    expect($configuration)->toContain('root '.$base.'/orbit/apps/gateway/public')
        ->and($configuration)->not->toContain('releases/0123456789ab');
});
