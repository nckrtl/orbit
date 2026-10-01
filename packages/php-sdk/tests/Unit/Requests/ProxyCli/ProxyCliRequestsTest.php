<?php

declare(strict_types=1);

use Orbit\Sdk\Requests\ProxyCli\ListProxyCliModelsRequest;
use Orbit\Sdk\Requests\ProxyCli\ListProxyCliProvidersRequest;
use Orbit\Sdk\Requests\ProxyCli\SetupProxyCliRequest;
use Orbit\Sdk\Requests\ProxyCli\ShowProxyCliProviderRequest;
use Orbit\Sdk\Requests\ProxyCli\ShowProxyCliStatusRequest;
use Orbit\Sdk\Requests\ProxyCli\TeardownProxyCliRequest;
use Orbit\Sdk\Requests\ProxyCli\UpdateProxyCliAccountRequest;
use Saloon\Enums\Method;

it('exposes proxycli read and account routes', function (string $class, Method $method, string $path): void {
    $request = match ($class) {
        SetupProxyCliRequest::class => new $class(7, 'valkey', 'http://127.0.0.1:8317', 'management-key'),
        ShowProxyCliProviderRequest::class => new $class('codex'),
        UpdateProxyCliAccountRequest::class => new $class('plus.json', true),
        default => new $class,
    };

    expect($request->getMethod())->toBe($method)->and($request->resolveEndpoint())->toBe($path);
})->with([
    [SetupProxyCliRequest::class, Method::POST, '/api/v1/proxycli'],
    [TeardownProxyCliRequest::class, Method::DELETE, '/api/v1/proxycli'],
    [ShowProxyCliStatusRequest::class, Method::GET, '/api/v1/proxycli'],
    [ListProxyCliProvidersRequest::class, Method::GET, '/api/v1/proxycli/providers'],
    [ListProxyCliModelsRequest::class, Method::GET, '/api/v1/proxycli/models'],
    [ShowProxyCliProviderRequest::class, Method::GET, '/api/v1/proxycli/providers/codex'],
    [UpdateProxyCliAccountRequest::class, Method::PATCH, '/api/v1/proxycli/accounts/plus.json'],
]);

it('sends setup and account payloads without extra keys', function (): void {
    expect(new SetupProxyCliRequest(7, 'valkey', 'http://127.0.0.1:8317', 'management-key')->body()->all())
        ->toBe([
            'node_id' => 7,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->and(new UpdateProxyCliAccountRequest('plus.json', false)->body()->all())
        ->toBe(['disabled' => false]);
});
