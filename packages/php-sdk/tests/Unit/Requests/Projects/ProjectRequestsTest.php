<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Projects\CreateProjectRequest;
use Orbit\Sdk\Requests\Projects\DestroyProjectRequest;
use Orbit\Sdk\Requests\Projects\ListProjectsRequest;
use Orbit\Sdk\Requests\Projects\ShowProjectRequest;
use Orbit\Sdk\Requests\Projects\UpdateProjectRequest;
use Orbit\Sdk\Responses\Projects\ProjectResponse;
use Orbit\Sdk\Responses\Projects\ProjectsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('project requests', function (): void {
    it('creates a project and omits nullable project fields when they are absent', function (): void {
        $mockClient = new MockClient([
            CreateProjectRequest::class => MockResponse::make([
                'data' => project_gateway_data(),
                'meta' => ['request_id' => orbit_request_id()],
            ], 201),
        ]);
        $connector = project_gateway_connector($mockClient);
        $request = new CreateProjectRequest(
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
            ->toBeInstanceOf(ProjectResponse::class)
            ->and($response->requestId)
            ->toBe(orbit_request_id());
    });

    it('serializes explicit source defaults exactly as supplied', function (): void {
        $request = new CreateProjectRequest(
            slug: 'orbit-docs',
            repositoryUrl: 'git@github.com:nckrtl/orbit-docs.git',
            root: 'web/public',
            name: 'Orbit Docs',
            defaultBranch: 'stable',
        );

        expect($request->body()->all())->toBe([
            'name' => 'Orbit Docs',
            'slug' => 'orbit-docs',
            'type' => 'laravel-app',
            'repository_url' => 'git@github.com:nckrtl/orbit-docs.git',
            'default_branch' => 'stable',
            'root' => 'web/public',
        ]);
    });

    it('lists projects through the explicit collection route', function (): void {
        $mockClient = new MockClient([
            ListProjectsRequest::class => MockResponse::make([
                'data' => [project_gateway_data()],
                'meta' => ['request_id' => orbit_request_id()],
            ]),
        ]);
        $connector = project_gateway_connector($mockClient);

        $response = $connector->send(new ListProjectsRequest)->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::GET)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/projects')
            ->and($response)
            ->toBeInstanceOf(ProjectsResponse::class)
            ->and($response->projects)
            ->toHaveCount(1)
            ->and($response->projects[0])
            ->toBeInstanceOf(ProjectResponse::class)
            ->and($response->toArray())
            ->toBe([
                'projects' => [project_public_data()],
                'request_id' => orbit_request_id(),
            ]);
    });

    it('shows a project by numeric ID', function (): void {
        $mockClient = new MockClient([
            ShowProjectRequest::class => MockResponse::make([
                'data' => project_gateway_data(),
                'meta' => ['request_id' => orbit_request_id()],
            ]),
        ]);
        $connector = project_gateway_connector($mockClient);

        $response = $connector->send(new ShowProjectRequest(3))->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::GET)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/projects/3')
            ->and($response)
            ->toBeInstanceOf(ProjectResponse::class);
    });

    it('removes a project by numeric ID and returns its deleted snapshot', function (): void {
        $mockClient = new MockClient([
            DestroyProjectRequest::class => MockResponse::make([
                'data' => project_gateway_data(),
                'meta' => ['request_id' => orbit_request_id()],
            ]),
        ]);
        $connector = project_gateway_connector($mockClient);

        $response = $connector->send(new DestroyProjectRequest(3))->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::DELETE)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/projects/3')
            ->and($response)
            ->toBeInstanceOf(ProjectResponse::class)
            ->and($response->id)
            ->toBe(3);
    });

    it('updates a project through PATCH and omits unchanged fields', function (): void {
        $mockClient = new MockClient([
            UpdateProjectRequest::class => MockResponse::make([
                'data' => [
                    ...project_gateway_data(),
                    'repository_url' => 'https://github.com/nckrtl/orbit-docs.git',
                    'default_branch' => 'stable',
                ],
                'meta' => ['request_id' => orbit_request_id()],
            ]),
        ]);
        $connector = project_gateway_connector($mockClient);
        $request = new UpdateProjectRequest(
            projectId: 3,
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
            ->toBeInstanceOf(ProjectResponse::class)
            ->and($response->repositoryUrl)
            ->toBe('https://github.com/nckrtl/orbit-docs.git')
            ->and($response->defaultBranch)
            ->toBe('stable');
    });

    it('transports a task check update and an explicit clear', function (): void {
        $set = new UpdateProjectRequest(projectId: 3, taskCheck: 'vp run check', taskCheckProvided: true);
        $clear = new UpdateProjectRequest(projectId: 3, taskCheckProvided: true);

        expect($set->body()->all())
            ->toBe(['task_check' => 'vp run check'])
            ->and($clear->body()->all())
            ->toBe(['task_check' => null])
            ->and(new UpdateProjectRequest(projectId: 3, root: 'public')->body()->all())
            ->toBe(['root' => 'public']);
    });

    it('sends a task check on create only when one is given, including an explicit null', function (): void {
        $request = static fn (?string $taskCheck, bool $provided): CreateProjectRequest => new CreateProjectRequest(
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
        $response = ProjectResponse::fromGatewayData([
            'id' => 3,
            'name' => 'kit',
            'slug' => 'kit',
            'type' => 'laravel-package',
            'repository_url' => 'https://github.com/acme/kit.git',
            'default_branch' => 'main',
            'root' => '.',
            'task_check' => 'composer check',
        ], 'request-id');

        expect($response->taskCheck)->toBe('composer check')
            ->and($response->toArray()['task_check'])->toBe('composer check')
            ->and(ProjectResponse::fromGatewayData(['id' => 4], 'request-id')->taskCheck)->toBeNull();
    });

    it('does not keep the replaced Project request class name', function (): void {
        expect(class_exists('Orbit\\Sdk\\Requests\\Projects\\RemoveAppRequest'))->toBeFalse();
    });
});

function project_gateway_connector(MockClient $mockClient): GatewayConnector
{
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mockClient);

    return $connector;
}

/** @return array<string, mixed> */
function project_gateway_data(): array
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
    ];
}

/** @return array<string, mixed> */
function project_public_data(): array
{
    return project_gateway_data();
}

function orbit_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
