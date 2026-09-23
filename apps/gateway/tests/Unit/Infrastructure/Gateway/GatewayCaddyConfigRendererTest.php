<?php

declare(strict_types=1);

use App\Infrastructure\Gateway\GatewayCaddyConfigRenderer;

it('routes Gateway paths to Laravel, Grafana through Metrics authorization, and the rest to the web release', function (): void {
    $configuration = new GatewayCaddyConfigRenderer()->render(
        'gateway.orbit',
        '10.44.0.2',
        '/home/orbit/orbit/apps/gateway',
        '/home/orbit/web',
    );

    $gateway = strpos($configuration, 'handle @gateway {');
    $grafana = strpos($configuration, 'handle_path /grafana/* {');
    $web = strpos($configuration, "    handle {\n");

    expect($configuration)
        ->toContain(
            'gateway.orbit, 10.44.0.2 {',
            'bind 10.44.0.2',
            '@gateway path /api/* /mcp /mcp/* /up /.well-known/*',
            'root * /home/orbit/orbit/apps/gateway/public',
            'php_fastcgi unix//run/php/orbit-gateway.sock',
            'uri /api/v1/metrics/grafana/authorize',
            'env REMOTE_ADDR {remote_host}',
            'reverse_proxy https://10.44.0.2 {',
            'header_up Host metrics.orbit',
            'tls_server_name metrics.orbit',
            'tls_trusted_ca_certs '.GatewayCaddyConfigRenderer::ROOT_CA_PATH,
            'root * /home/orbit/web/current',
            'header @immutable Cache-Control "public, max-age=31536000, immutable"',
            'header @revalidate Cache-Control "no-cache"',
            'try_files {path} /index.html',
        )
        ->not->toContain('reverse_proxy http://')
        ->and($gateway)->toBeInt()->toBeLessThan($grafana)
        ->and($grafana)->toBeLessThan($web);

    $adapted = caddy_adapt($configuration);

    expect($adapted->succeeded())->toBeTrue($adapted->stderr)
        ->and($adapted->stdout)
        ->toContain('"/api/*","/mcp","/mcp/*","/up","/.well-known/*"')
        ->toContain('"strip_path_prefix":"/grafana"')
        ->toContain('"try_files":["{http.request.uri.path}","/index.html"]');
});
