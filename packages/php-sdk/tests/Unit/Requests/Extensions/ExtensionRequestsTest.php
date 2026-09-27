<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Extensions\DisableExtensionRequest;
use Orbit\Sdk\Requests\Extensions\EnableExtensionRequest;
use Orbit\Sdk\Requests\Extensions\ListExtensionsRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('lists extension switches', function (): void {
    $connector = new GatewayConnector('https://gateway.orbit', caPemPath: '/tmp/root.pem');
    MockClient::global([ListExtensionsRequest::class => MockResponse::make([
        'data' => ['tasks' => false, 'proxycli' => true], 'meta' => ['request_id' => 'extensions-id'],
    ])]);

    $response = $connector->send(new ListExtensionsRequest)->dto();

    expect($response->extensions)->toBe(['tasks' => false, 'proxycli' => true]);
});

it('enables and disables named Gateway extensions', function (string $request, string $path, Method $method): void {
    $request = new $request('tasks');

    expect($request->resolveEndpoint())->toBe($path)
        ->and($request->getMethod())->toBe($method);
})->with([
    'enable' => [EnableExtensionRequest::class, '/api/v1/extensions/tasks/enable', Method::POST],
    'disable' => [DisableExtensionRequest::class, '/api/v1/extensions/tasks/disable', Method::POST],
]);
