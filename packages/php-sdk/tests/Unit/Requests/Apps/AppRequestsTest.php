<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Apps\CreateAppRequest;
use Orbit\Sdk\Requests\Apps\DestroyAppRequest;
use Orbit\Sdk\Requests\Apps\ListAppsRequest;
use Orbit\Sdk\Requests\Apps\ShowAppRequest;
use Orbit\Sdk\Requests\Apps\UpdateAppRequest;
use Orbit\Sdk\Responses\Apps\AppResponse;
use Orbit\Sdk\Responses\Apps\AppsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('app requests', function (): void {
    it('creates an app and omits nullable app fields when they are absent', function (): void {
        $mockClient = new MockClient([
            CreateAppRequest::class => MockResponse::make([
                'data' => app_gateway_data(),
                'meta' => ['request_id' => orbit_request_id()],
            ], 201),
        ]);
        $connector = app_gateway_connector($mockClient);
        $request = new CreateAppRequest(
            slug: 'orbit-docs',
            repositoryUrl: 'git@github.com:nckrtl/orbit-docs.git',
            root: 'public',
        );

        $response = $connector->send($request)->dto();

        expect($request->getMethod())
            ->toBe(Method::POST)
            ->and($request->resolveEndpoint())
            ->toBe('/api/v1/projects')
            ->and($request->body()->all())
            ->toBe([
                'slug' => 'orbit-docs',
                'type' => 'laravel-app',
                'repository_url' => 'git@github.com:nckrtl/orbit-docs.git',
                'root' => 'public',
            ])
            ->and($response)
            ->toBeInstanceOf(AppResponse::class)
            ->and($response->requestId)
            ->toBe(orbit_request_id());
    });

    it('serializes explicit defaults exactly as supplied', function (): void {
        $request = new CreateAppRequest(
            slug: 'orbit-docs',
            repositoryUrl: 'git@github.com:nckrtl/orbit-docs.git',
            root: 'web/public',
            name: 'Orbit Docs',
            defaultBranch: 'stable',
            defaults: ['php_version' => '8.5'],
        );

        expect($request->body()->all())->toBe([
            'name' => 'Orbit Docs',
            'slug' => 'orbit-docs',
            'type' => 'laravel-app',
            'repository_url' => 'git@github.com:nckrtl/orbit-docs.git',
            'default_branch' => 'stable',
            'root' => 'web/public',
            'defaults' => ['php_version' => '8.5'],
        ]);
    });

    it('lists apps through the explicit collection route', function (): void {
        $mockClient = new MockClient([
            ListAppsRequest::class => MockResponse::make([
                'data' => [app_gateway_data()],
                'meta' => ['request_id' => orbit_request_id()],
            ]),
        ]);
        $connector = app_gateway_connector($mockClient);

        $response = $connector->send(new ListAppsRequest)->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::GET)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/projects')
            ->and($response)
            ->toBeInstanceOf(AppsResponse::class)
            ->and($response->apps)
            ->toHaveCount(1)
            ->and($response->apps[0])
            ->toBeInstanceOf(AppResponse::class)
            ->and($response->toArray())
            ->toBe([
                'projects' => [app_public_data()],
                'request_id' => orbit_request_id(),
            ]);
    });

    it('shows an app by numeric ID', function (): void {
        $mockClient = new MockClient([
            ShowAppRequest::class => MockResponse::make([
                'data' => app_gateway_data(),
                'meta' => ['request_id' => orbit_request_id()],
            ]),
        ]);
        $connector = app_gateway_connector($mockClient);

        $response = $connector->send(new ShowAppRequest(3))->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::GET)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/projects/3')
            ->and($response)
            ->toBeInstanceOf(AppResponse::class);
    });

    it('removes an app by numeric ID and returns its deleted snapshot', function (): void {
        $mockClient = new MockClient([
            DestroyAppRequest::class => MockResponse::make([
                'data' => app_gateway_data(),
                'meta' => ['request_id' => orbit_request_id()],
            ]),
        ]);
        $connector = app_gateway_connector($mockClient);

        $response = $connector->send(new DestroyAppRequest(3))->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::DELETE)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/projects/3')
            ->and($response)
            ->toBeInstanceOf(AppResponse::class)
            ->and($response->id)
            ->toBe(3);
    });

    it('updates an app through PATCH and omits unchanged fields', function (): void {
        $mockClient = new MockClient([
            UpdateAppRequest::class => MockResponse::make([
                'data' => [
                    ...app_gateway_data(),
                    'repository_url' => 'https://github.com/nckrtl/orbit-docs.git',
                    'default_branch' => 'stable',
                ],
                'meta' => ['request_id' => orbit_request_id()],
            ]),
        ]);
        $connector = app_gateway_connector($mockClient);
        $request = new UpdateAppRequest(
            appId: 3,
            repositoryUrl: 'https://github.com/nckrtl/orbit-docs.git',
            defaultBranch: 'stable',
        );

        $response = $connector->send($request)->dto();

        expect($request->getMethod())
            ->toBe(Method::PATCH)
            ->and($request->resolveEndpoint())
            ->toBe('/api/v1/projects/3')
            ->and($request->body()->all())
            ->toBe([
                'repository_url' => 'https://github.com/nckrtl/orbit-docs.git',
                'default_branch' => 'stable',
            ])
            ->and($request->body()->all())
            ->not
            ->toHaveKey('main_branch')
            ->and($response)
            ->toBeInstanceOf(AppResponse::class)
            ->and($response->repositoryUrl)
            ->toBe('https://github.com/nckrtl/orbit-docs.git')
            ->and($response->defaultBranch)
            ->toBe('stable');
    });

    it('transports a task check update and an explicit clear', function (): void {
        $set = new UpdateAppRequest(appId: 3, taskCheck: 'vp run check', taskCheckProvided: true);
        $clear = new UpdateAppRequest(appId: 3, taskCheckProvided: true);

        expect($set->body()->all())
            ->toBe(['task_check' => 'vp run check'])
            ->and($clear->body()->all())
            ->toBe(['task_check' => null])
            ->and(new UpdateAppRequest(appId: 3, root: 'public')->body()->all())
            ->toBe(['root' => 'public']);
    });

    it('sends a task check on create only when one is given, including an explicit null', function (): void {
        $request = static fn (?string $taskCheck, bool $provided): CreateAppRequest => new CreateAppRequest(
            slug: 'kit',
            repositoryUrl: 'https://github.com/acme/kit.git',
            root: '.',
            type: 'node-package',
            taskCheck: $taskCheck,
            taskCheckProvided: $provided,
        );

        expect($request(null, false)->body()->all())->not->toHaveKey('task_check')
            ->and($request('vp run check', true)->body()->all())->toMatchArray(['task_check' => 'vp run check'])
            ->and($request(null, true)->body()->all())->toMatchArray(['task_check' => null]);
    });

    it('reads the task check from a Project response', function (): void {
        $response = AppResponse::fromGatewayData([
            'id' => 3,
            'name' => 'kit',
            'slug' => 'kit',
            'type' => 'laravel-package',
            'repository_url' => 'https://github.com/acme/kit.git',
            'default_branch' => 'main',
            'root' => '.',
            'defaults' => null,
            'task_check' => 'composer check',
        ], 'request-id');

        expect($response->taskCheck)->toBe('composer check')
            ->and($response->toArray()['task_check'])->toBe('composer check')
            ->and(AppResponse::fromGatewayData(['id' => 4], 'request-id')->taskCheck)->toBeNull();
    });

    it('does not keep the replaced App request class name', function (): void {
        expect(class_exists('Orbit\\Sdk\\Requests\\Apps\\RemoveAppRequest'))->toBeFalse();
    });
});

function app_gateway_connector(MockClient $mockClient): GatewayConnector
{
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mockClient);

    return $connector;
}

/** @return array<string, mixed> */
function app_gateway_data(): array
{
    return [
        'id' => 3,
        'name' => 'orbit-docs',
        'slug' => 'orbit-docs',
        'type' => 'laravel-app',
        'repository_url' => 'git@github.com:nckrtl/orbit-docs.git',
        'default_branch' => 'main',
        'root' => 'public',
        'task_check' => 'composer check',
        'defaults' => null,
    ];
}

/** @return array<string, mixed> */
function app_public_data(): array
{
    return app_gateway_data();
}

function orbit_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
