<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Instances\CreateInstanceRequest;
use Orbit\Sdk\Requests\Instances\RegisterInstanceRequest;
use Orbit\Sdk\Requests\Projects\CreateProjectRequest;
use Orbit\Sdk\Requests\Projects\ShowProjectRequest;
use Orbit\Sdk\Requests\Projects\UpdateProjectRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;
use Orbit\Sdk\Responses\Projects\ProjectAppResponse;
use Orbit\Sdk\Responses\Projects\ProjectResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('project apps round-trip through create, update and show requests and responses', function (): void {
    $apps = project_apps_fixture();
    $create = new CreateProjectRequest(slug: 'acme', repositoryUrl: 'https://github.com/acme/acme.git', apps: $apps);
    $update = new UpdateProjectRequest(projectId: 3, apps: $apps);
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient(new MockClient([
        ShowProjectRequest::class => MockResponse::make([
            'data' => ['id' => 3, 'slug' => 'acme', 'apps' => $apps],
            'meta' => ['request_id' => 'request-id'],
        ]),
    ]));

    $response = $connector->send(new ShowProjectRequest(3))->dto();

    expect($create->body()->all()['apps'])->toBe($apps)
        ->and((string) $create->body())->toContain('"apps":[{"name":"web","path":"apps\/site","web_root":"public","type":"laravel-app"},{"name":"docs","path":"docs","web_root":null,"type":"node-app"}]')
        ->and($update->body()->all())->toBe(['apps' => $apps])
        ->and(new UpdateProjectRequest(projectId: 3, defaultBranch: 'main')->body()->all())->not->toHaveKey('apps')
        ->and($response)->toBeInstanceOf(ProjectResponse::class)
        ->and($response->apps)->toHaveCount(2)
        ->and($response->apps)->each->toBeInstanceOf(ProjectAppResponse::class)
        ->and($response->apps[0]->name)->toBe('web')
        ->and($response->apps[0]->path)->toBe('apps/site')
        ->and($response->apps[0]->webRoot)->toBe('public')
        ->and($response->apps[0]->type)->toBe('laravel-app')
        ->and($response->apps[1]->webRoot)->toBeNull()
        ->and($response->toArray()['apps'])->toBe($apps);
});

it('reads project apps and app overrides from an Instance response', function (): void {
    $response = InstanceResponse::fromGatewayData([
        'id' => 7,
        'apps' => project_apps_fixture(),
        'app_overrides' => ['web' => ['path' => 'apps/site', 'web_root' => 'public']],
    ], 'request-id');

    expect($response->apps)->toHaveCount(2)
        ->and($response->apps)->each->toBeInstanceOf(ProjectAppResponse::class)
        ->and($response->appOverrides)->toBe(['web' => ['path' => 'apps/site', 'web_root' => 'public']])
        ->and($response->toArray()['apps'])->toBe(project_apps_fixture())
        ->and($response->toArray()['app_overrides'])->toBe(['web' => ['path' => 'apps/site', 'web_root' => 'public']])
        ->and(json_encode(InstanceResponse::fromGatewayData(['id' => 7, 'app_overrides' => []], 'request-id')->toArray(), JSON_THROW_ON_ERROR))
        ->toContain('"app_overrides":{}');
});

it('sends project apps overrides for an Instance as a JSON object', function (): void {
    $overrides = ['web' => ['path' => 'apps/site', 'web_root' => null]];
    $create = new CreateInstanceRequest(projectId: 3, nodeId: 4, name: 'dev', appOverrides: $overrides);
    $empty = new CreateInstanceRequest(projectId: 3, nodeId: 4, name: 'dev', appOverrides: []);
    $register = new RegisterInstanceRequest(sourcePath: '/work/acme', appOverrides: $overrides);

    expect((string) $create->body())->toContain('"app_overrides":{"web":{"path":"apps\/site","web_root":null}}')
        ->and((string) $empty->body())->toContain('"app_overrides":{}')
        ->and((string) $register->body())->toContain('"app_overrides":{"web":{"path":"apps\/site","web_root":null}}')
        ->and(new CreateInstanceRequest(projectId: 3, nodeId: 4, name: 'dev')->body()->all())->not->toHaveKey('app_overrides')
        ->and(new RegisterInstanceRequest('/work/acme')->body()->all())->not->toHaveKey('app_overrides');
});

it('drops malformed project apps and app overrides from responses', function (): void {
    $project = ProjectResponse::fromGatewayData(['id' => 3, 'apps' => [
        ['name' => 'web', 'path' => '.', 'web_root' => 'public', 'type' => 'laravel-app'],
        ['name' => 'api', 'path' => 'api', 'web_root' => ['public'], 'type' => 'laravel-app'],
        ['name' => 'docs', 'path' => 'docs', 'web_root' => null],
        'invalid',
    ]], 'request-id');
    $instance = InstanceResponse::fromGatewayData(['id' => 7, 'apps' => 'invalid', 'app_overrides' => [
        'web' => ['path' => 'site'],
        'api' => ['path' => 7, 'web_root' => null],
        3 => ['path' => 'x', 'web_root' => null],
    ]], 'request-id');

    expect(array_map(static fn (ProjectAppResponse $app): string => $app->name, $project->apps))->toBe(['web'])
        ->and($instance->apps)->toBe([])
        ->and($instance->appOverrides)->toBe(['web' => ['path' => 'site', 'web_root' => null]]);
});

/** @return list<array{name: string, path: string, web_root: string|null, type: string}> */
function project_apps_fixture(): array
{
    return [
        ['name' => 'web', 'path' => 'apps/site', 'web_root' => 'public', 'type' => 'laravel-app'],
        ['name' => 'docs', 'path' => 'docs', 'web_root' => null, 'type' => 'node-app'],
    ];
}
