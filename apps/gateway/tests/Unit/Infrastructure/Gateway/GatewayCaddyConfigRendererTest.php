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
    $assets = strpos($configuration, 'handle /assets/* {');
    $web = strpos($configuration, "    handle {\n");

    expect($configuration)
        ->toContain(
            'gateway.orbit, 10.44.0.2 {',
            'bind 10.44.0.2',
            '@gateway path /api/* /mcp /mcp/* /up /.well-known/*',
            'root * /home/orbit/orbit/apps/gateway/public',
            'php_fastcgi unix//run/php/orbit-gateway.sock',
            'uri /index.php',
            "root /home/orbit/orbit/apps/gateway/public\n                resolve_root_symlink\n                split .php\n",
            'env REQUEST_URI /api/v1/metrics/grafana/authorize',
            'env REMOTE_ADDR {remote_host}',
            'reverse_proxy https://10.44.0.2 {',
            'header_up Host metrics.orbit',
            'tls_server_name metrics.orbit',
            'tls_trusted_ca_certs '.GatewayCaddyConfigRenderer::ROOT_CA_PATH,
            'root * /home/orbit/web/current',
            'header @asset Cache-Control "public, max-age=31536000, immutable"',
            'header @missing Cache-Control "no-cache"',
            'header Cache-Control "no-cache"',
            'try_files {path} /index.html',
        )
        ->not->toContain('reverse_proxy http://')
        ->not->toContain('SCRIPT_FILENAME')
        ->and($gateway)->toBeInt()->toBeLessThan($grafana)
        ->and($grafana)->toBeLessThan($assets)
        ->and($assets)->toBeLessThan($web);

    $adapted = caddy_adapt($configuration);

    expect($adapted->succeeded())->toBeTrue($adapted->stderr)
        ->and($adapted->stdout)
        ->toContain('"/api/*","/mcp","/mcp/*","/up","/.well-known/*"')
        ->toContain('"strip_path_prefix":"/grafana"')
        ->toContain('"try_files":["{http.request.uri.path}","/index.html"]');
});

it('caches only existing assets as immutable and never answers a missing asset with the app', function (): void {
    $adapted = caddy_adapt(new GatewayCaddyConfigRenderer()->render(
        'gateway.orbit',
        '10.44.0.2',
        '/home/orbit/orbit/apps/gateway',
        '/home/orbit/web',
    ));
    expect($adapted->succeeded())->toBeTrue($adapted->stderr);

    /** @var array{apps: array{http: array{servers: array{srv0: array{routes: list<array{handle: list<array{routes: list<array<string, mixed>>}>}>}}}}} $json */
    $json = json_decode($adapted->stdout, true, flags: JSON_THROW_ON_ERROR);
    $routes = $json['apps']['http']['servers']['srv0']['routes'][0]['handle'][0]['routes'];
    $assets = array_values(array_filter(
        $routes,
        fn (array $route): bool => ($route['match'] ?? null) === [['path' => ['/assets/*']]],
    ));

    expect($assets)->toHaveCount(1);

    // An empty Caddy object decodes to an empty PHP array, so the bare `file` matcher reads `"file":[]`.
    $assetRoutes = json_encode($assets[0], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $immutable = '{"handle":[{"handler":"headers","response":{"set":{"Cache-Control":["public, max-age=31536000, immutable"]}}}],"match":[{"file":[]}]}';
    $missing = '{"handle":[{"handler":"headers","response":{"set":{"Cache-Control":["no-cache"]}}}],"match":[{"not":[{"file":[]}]}]}';

    expect($assetRoutes)->toContain($immutable);
    expect($assetRoutes)->toContain($missing);
    expect($assetRoutes)->toContain('"handler":"file_server"');
    expect($assetRoutes)->not->toContain('try_files');
    expect($assetRoutes)->not->toContain('index.html');
});
