<?php

declare(strict_types=1);

use Orbit\Sdk\Requests\ProxyCli\DisableProxyCliRequest;
use Orbit\Sdk\Requests\ProxyCli\EnableProxyCliRequest;
use Orbit\Sdk\Requests\ProxyCli\ListProxyCliProvidersRequest;
use Orbit\Sdk\Requests\ProxyCli\ShowProxyCliProviderRequest;
use Orbit\Sdk\Requests\ProxyCli\ShowProxyCliStatusRequest;
use Orbit\Sdk\Requests\ProxyCli\UpdateProxyCliAccountRequest;
use Saloon\Enums\Method;

it('exposes the six proxycli routes', function (string $class, Method $method, string $path): void {
    $request = match ($class) {
        EnableProxyCliRequest::class => new $class(7, 'valkey', 'http://127.0.0.1:8317', 'management-key'),
        ShowProxyCliProviderRequest::class => new $class('codex'),
        UpdateProxyCliAccountRequest::class => new $class('plus.json', true),
        default => new $class,
    };

    expect($request->getMethod())->toBe($method)->and($request->resolveEndpoint())->toBe($path);
})->with([
    [EnableProxyCliRequest::class, Method::POST, '/api/v1/proxycli'],
    [DisableProxyCliRequest::class, Method::DELETE, '/api/v1/proxycli'],
    [ShowProxyCliStatusRequest::class, Method::GET, '/api/v1/proxycli'],
    [ListProxyCliProvidersRequest::class, Method::GET, '/api/v1/proxycli/providers'],
    [ShowProxyCliProviderRequest::class, Method::GET, '/api/v1/proxycli/providers/codex'],
    [UpdateProxyCliAccountRequest::class, Method::PATCH, '/api/v1/proxycli/accounts/plus.json'],
]);

it('sends enable and account update payloads without extra keys', function (): void {
    expect(new EnableProxyCliRequest(7, 'valkey', 'http://127.0.0.1:8317', 'management-key')->body()->all())
        ->toBe([
            'node_id' => 7,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->and(new UpdateProxyCliAccountRequest('plus.json', false)->body()->all())
        ->toBe(['disabled' => false]);
});
