<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\AppInstances\AppInstanceDeploymentLayoutRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe(AppInstanceDeploymentLayoutRequest::class, function (): void {
    it('posts the explicit deployment-layout operation and maps the AppInstance response', function (): void {
        $mock = new MockClient([
            AppInstanceDeploymentLayoutRequest::class => MockResponse::make(
                deployment_layout_envelope(),
            ),
        ]);
        $connector = deployment_layout_connector($mock);
        $request = new AppInstanceDeploymentLayoutRequest(17, '/home/orbit-app-17/app.sqlite');

        $response = $connector->send($request)->dto();
        $pending = $mock->getLastPendingRequest();

        expect($request->getMethod())
            ->toBe(Method::POST)
            ->and($request->resolveEndpoint())
            ->toBe('/api/v1/instances/17/deployment-layout')
            ->and($request->body()->all())
            ->toBe(['sqlite_source_path' => '/home/orbit-app-17/app.sqlite'])
            ->and((string) $pending?->createPsrRequest()->getBody())
            ->toBe('{"sqlite_source_path":"\\/home\\/orbit-app-17\\/app.sqlite"}')
            ->and($pending?->headers()->get('Content-Type'))
            ->toBe('application/json')
            ->and($response)
            ->toBeInstanceOf(AppInstanceResponse::class)
            ->and($response->id)
            ->toBe(17)
            ->and($response->sourceLayout)
            ->toBe('release')
            ->and($response->requestId)
            ->toBe(deployment_layout_request_id());
    });

    it('preserves omission separately from every supplied string', function (string $path): void {
        expect(new AppInstanceDeploymentLayoutRequest(17)->body()->all())
            ->toBeEmpty()
            ->and(new AppInstanceDeploymentLayoutRequest(17, $path)->body()->all())
            ->toBe(['sqlite_source_path' => $path]);
    })->with([
        'empty' => [''],
        'relative' => ['database/app.sqlite'],
        'absolute' => ['/home/orbit-app-17/database/app.sqlite'],
    ]);

    it('preserves the bounded structured Gateway error', function (): void {
        $mock = new MockClient([
            AppInstanceDeploymentLayoutRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'instance.deployment_layout_conflict',
                    'message' => 'The deployment layout conflicts with existing content.',
                    'details' => ['checkpoint' => 'preflight'],
                ],
            ], 409, ['X-Orbit-Request-Id' => deployment_layout_request_id()]),
        ]);

        try {
            deployment_layout_connector($mock)->send(new AppInstanceDeploymentLayoutRequest(17));
            $this->fail('Expected a GatewayApiException.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())
                ->toBe('instance.deployment_layout_conflict')
                ->and($exception->getMessage())
                ->toBe('The deployment layout conflicts with existing content.')
                ->and($exception->details())
                ->toBe(['checkpoint' => 'preflight'])
                ->and($exception->requestId())
                ->toBe(deployment_layout_request_id());
        }
    });
});

function deployment_layout_connector(MockClient $mock): GatewayConnector
{
    $connector = new GatewayConnector('https://gateway.test');
    $connector->withMockClient($mock);

    return $connector;
}

/** @return array<string, mixed> */
function deployment_layout_envelope(): array
{
    return [
        'data' => [
            'id' => 17,
            'app_id' => 3,
            'node_id' => 4,
            'name' => 'main',
            'environment' => 'production',
            'source_layout' => 'release',
            'checkout_path' => '/home/orbit-app-17/releases/retained',
            'production_user' => 'orbit-app-17',
            'production_home' => '/home/orbit-app-17',
            'root' => null,
            'effective_root' => '/home/orbit-app-17/current/public',
            'selected_branch' => 'main',
            'branch_override' => null,
            'migration_required' => false,
            'starting_commit' => str_repeat('a', 40),
            'detached' => false,
            'status' => 'active',
            'route' => null,
            'hostname' => 'app.example.test',
            'url' => 'https://app.example.test',
            'removal' => null,
        ],
        'meta' => ['request_id' => deployment_layout_request_id()],
    ];
}

function deployment_layout_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
