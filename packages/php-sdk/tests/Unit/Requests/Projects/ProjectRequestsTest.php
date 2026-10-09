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
            apps: project_apps_data(),
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
                'apps' => project_apps_data(),
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
            apps: [['name' => 'web', 'path' => 'web', 'web_root' => 'public', 'type' => 'laravel-app']],
            name: 'Orbit Docs',
            defaultBranch: 'stable',
        );

        expect($request->body()->all())->toBe([
            'name' => 'Orbit Docs',
            'slug' => 'orbit-docs',
            'type' => 'laravel-app',
            'repository_url' => 'git@github.com:nckrtl/orbit-docs.git',
            'default_branch' => 'stable',
            'apps' => [['name' => 'web', 'path' => 'web', 'web_root' => 'public', 'type' => 'laravel-app']],
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
            ->and(new UpdateProjectRequest(projectId: 3, apps: project_apps_data())->body()->all())
            ->toBe(['apps' => project_apps_data()]);
    });

    it('sends a task check on create only when one is given, including an explicit null', function (): void {
        $request = static fn (?string $taskCheck, bool $provided): CreateProjectRequest => new CreateProjectRequest(
            slug: 'kit',
            repositoryUrl: 'https://github.com/acme/kit.git',
            apps: [['name' => 'kit', 'path' => '.', 'web_root' => null, 'type' => 'node-package']],
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
            'apps' => [['name' => 'kit', 'path' => '.', 'web_root' => null, 'type' => 'laravel-package']],
            'task_check' => 'composer check',
        ], 'request-id');

        expect($response->taskCheck)->toBe('composer check')
            ->and($response->toArray()['task_check'])->toBe('composer check')
            ->and(ProjectResponse::fromGatewayData(['id' => 4], 'request-id')->taskCheck)->toBeNull();
    });

    it('transports source access on create and update only when given', function (): void {
        $create = static fn (?string $sourceAccess): CreateProjectRequest => new CreateProjectRequest(
            slug: 'leden',
            repositoryUrl: 'git@github.com:acme/leden.git',
            apps: project_apps_data(),
            sourceAccess: $sourceAccess,
        );

        expect($create(null)->body()->all())->not->toHaveKey('source_access')
            ->and($create('gh_cli')->body()->all())->toMatchArray(['source_access' => 'gh_cli'])
            ->and(new UpdateProjectRequest(projectId: 14, defaultBranch: 'main', sourceAccess: 'gh_cli')->body()->all())
            ->toBe(['source_access' => 'gh_cli', 'default_branch' => 'main'])
            ->and(new UpdateProjectRequest(projectId: 14, defaultBranch: 'main')->body()->all())
            ->not->toHaveKey('source_access');
    });

    it('reads source access from a Project response', function (): void {
        $response = ProjectResponse::fromGatewayData(['id' => 14, 'source_access' => 'gh_cli'], 'request-id');

        expect($response->sourceAccess)->toBe('gh_cli')
            ->and($response->toArray()['source_access'])->toBe('gh_cli')
            ->and(ProjectResponse::fromGatewayData(['id' => 4], 'request-id')->sourceAccess)->toBe('github_app');
    });

    it('serializes task workspace routing as true, false, or omitted', function (): void {
        $create = static fn (?bool $routed): CreateProjectRequest => new CreateProjectRequest(
            slug: 'kit',
            repositoryUrl: 'https://github.com/acme/kit.git',
            apps: [['name' => 'kit', 'path' => '.', 'web_root' => null, 'type' => 'node-package']],
            type: 'node-package',
            taskWorkspaceRouted: $routed,
        );
        $update = static fn (?bool $routed): UpdateProjectRequest => new UpdateProjectRequest(
            projectId: 3,
            taskWorkspaceRouted: $routed,
        );

        expect($create(null)->body()->all())->not->toHaveKey('task_workspace_routed')
            ->and($create(false)->body()->all())->toMatchArray(['task_workspace_routed' => false])
            ->and((string) $create(false)->body())->toContain('"task_workspace_routed":false')
            ->and($create(true)->body()->all())->toMatchArray(['task_workspace_routed' => true])
            ->and($update(null)->body()->all())->toBe([])
            ->and($update(false)->body()->all())->toBe(['task_workspace_routed' => false])
            ->and((string) $update(false)->body())->toBe('{"task_workspace_routed":false}')
            ->and($update(true)->body()->all())->toBe(['task_workspace_routed' => true]);
    });

    it('serializes the review-and-merge switch and the merge check only when given', function (): void {
        expect(new UpdateProjectRequest(projectId: 3, reviewAndMerge: true, mergeCheck: 'Required checks', mergeCheckProvided: true)->body()->all())
            ->toBe(['review_and_merge' => true, 'merge_check' => 'Required checks'])
            ->and(new UpdateProjectRequest(projectId: 3, reviewAndMerge: false)->body()->all())->toBe(['review_and_merge' => false])
            ->and(new UpdateProjectRequest(projectId: 3, mergeCheckProvided: true)->body()->all())->toBe(['merge_check' => null])
            ->and(new UpdateProjectRequest(projectId: 3, mergeCheck: 'Ignored')->body()->all())->toBe([]);
    });

    it('parses the review-and-merge settings', function (): void {
        $on = ProjectResponse::fromGatewayData(['id' => 3, 'review_and_merge' => true, 'merge_check' => 'Required checks'], 'request-id');
        $omitted = ProjectResponse::fromGatewayData(['id' => 4], 'request-id');

        expect($on->reviewAndMerge)->toBeTrue()
            ->and($on->mergeCheck)->toBe('Required checks')
            ->and($on->toArray())->toMatchArray(['review_and_merge' => true, 'merge_check' => 'Required checks'])
            ->and($omitted->reviewAndMerge)->toBeNull()
            ->and($omitted->toArray())->not->toHaveKey('review_and_merge');
    });

    it('parses true, false, and omitted task workspace routing without coercing other types', function (): void {
        $response = static fn (mixed $routed): ProjectResponse => ProjectResponse::fromGatewayData([
            'id' => 3,
            'task_workspace_routed' => $routed,
        ], 'request-id');
        $omitted = ProjectResponse::fromGatewayData(['id' => 4], 'request-id');

        expect($response(false)->taskWorkspaceRouted)->toBeFalse()
            ->and($response(false)->toArray()['task_workspace_routed'])->toBeFalse()
            ->and(json_encode($response(false)->toArray(), JSON_THROW_ON_ERROR))->toContain('"task_workspace_routed":false')
            ->and($response(true)->taskWorkspaceRouted)->toBeTrue()
            ->and($response(true)->toArray()['task_workspace_routed'])->toBeTrue()
            ->and($omitted->taskWorkspaceRouted)->toBeNull()
            ->and($omitted->toArray())->not->toHaveKey('task_workspace_routed')
            ->and($response('false')->taskWorkspaceRouted)->toBeNull()
            ->and($response(0)->taskWorkspaceRouted)->toBeNull()
            ->and($response(null)->toArray())->not->toHaveKey('task_workspace_routed');
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
        'source_access' => 'github_app',
        'default_branch' => 'main',
        'apps' => project_apps_data(),
        'task_check' => 'composer check',
        'task_workspace_routed' => true,
    ];
}

/** @return list<array{name: string, path: string, web_root: string|null, type: string}> */
function project_apps_data(): array
{
    return [['name' => 'web', 'path' => '.', 'web_root' => 'public', 'type' => 'laravel-app']];
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

it('preserves explicit compute modes and omits an unspecified mode', function (?string $mode): void {
    $create = new CreateProjectRequest(slug: 'orbit', repositoryUrl: 'https://github.com/nckrtl/orbit.git', apps: [['name' => 'kit', 'path' => '.', 'web_root' => null, 'type' => 'node-package']], taskCompute: $mode);
    $update = new UpdateProjectRequest(projectId: 3, taskCompute: $mode);
    foreach ([$create, $update] as $request) {
        $body = $request->body()->all();
        if ($mode === null) {
            expect($body)->not->toHaveKey('task_compute');
        } else {
            expect($body['task_compute'])->toBe($mode);
        }
    }
})->with([null, 'shared', 'vm']);
