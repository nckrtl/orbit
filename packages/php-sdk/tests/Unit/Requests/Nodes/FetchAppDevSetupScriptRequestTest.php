<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\FetchAppDevSetupScriptRequest;
use Orbit\Sdk\Responses\Nodes\AppDevSetupScriptResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe(FetchAppDevSetupScriptRequest::class, function (): void {
    it('posts only local Mac facts and maps the private script response', function (): void {
        expect(class_exists(FetchAppDevSetupScriptRequest::class))->toBeTrue();

        $mockClient = new MockClient([
            FetchAppDevSetupScriptRequest::class => MockResponse::make([
                'data' => [
                    'role' => 'app-dev',
                    'summary' => 'Install the protected app-dev prerequisites.',
                    'script' => fetch_app_dev_script(),
                ],
                'meta' => ['request_id' => fetch_app_dev_request_id()],
            ]),
        ]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);
        $request = new FetchAppDevSetupScriptRequest(
            platform: 'darwin',
            architecture: 'arm64',
            username: 'nckrtl',
            homeDirectory: '/Users/nckrtl',
        );

        $response = $connector->send($request)->dto();

        expect($request->getMethod())
            ->toBe(Method::POST)
            ->and($request->resolveEndpoint())
            ->toBe('/api/v1/node-role-setups/app-dev/script')
            ->and($request->body()->all())
            ->toBe([
                'platform' => 'darwin',
                'architecture' => 'arm64',
                'username' => 'nckrtl',
                'home_directory' => '/Users/nckrtl',
            ])
            ->and($response)
            ->toBeInstanceOf(AppDevSetupScriptResponse::class)
            ->and($response->script())
            ->toBe(fetch_app_dev_script())
            ->and($response->requestId)
            ->toBe(fetch_app_dev_request_id());
    });

    it('preserves every explicit local fact for Gateway validation', function (): void {
        $request = new FetchAppDevSetupScriptRequest('', '', '', '');

        expect($request->body()->all())->toBe([
            'platform' => '',
            'architecture' => '',
            'username' => '',
            'home_directory' => '',
        ]);
    });
});

function fetch_app_dev_script(): string
{
    return "#!/bin/bash\nset -euo pipefail\n";
}

function fetch_app_dev_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
