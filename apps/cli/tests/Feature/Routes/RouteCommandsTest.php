<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Routes\ClearRouteTargetRequest;
use Orbit\Sdk\Requests\Routes\CreateRouteRequest;
use Orbit\Sdk\Requests\Routes\ListRoutesRequest;
use Orbit\Sdk\Requests\Routes\RemoveRouteRequest;
use Orbit\Sdk\Requests\Routes\SetRouteTargetRequest;
use Orbit\Sdk\Requests\Routes\ShowRouteRequest;
use Orbit\Sdk\Requests\Routes\UpdateRouteRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-route-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('creates target and targetless Routes while transporting policy values', function (): void {
    $mock = MockClient::global([CreateRouteRequest::class => route_mock_response(201)]);

    $this->artisan('route:new', [
        'app' => '3',
        'hostname' => 'Odd_Value',
        '--publication' => 'future-policy',
        '--target' => '7',
        '--json' => true,
    ])->assertExitCode(0);

    expect($mock->getLastRequest()?->body()->all())->toBe([
        'app_id' => 3,
        'hostname' => 'Odd_Value',
        'publication' => 'future-policy',
        'app_instance_id' => 7,
    ]);

    $this->artisan('route:new', [
        'app' => '3',
        'hostname' => 'node.test',
        '--node' => '4',
    ])->assertExitCode(0);

    expect($mock->getLastRequest()?->body()->all())->toHaveKey('node_id', 4);
});

it('rejects impossible create shapes before transport', function (array $arguments, string $code): void {
    $mock = MockClient::global();

    $this
        ->artisan('route:new', $arguments)
        ->expectsOutputToContain($code)
        ->assertExitCode(1);

    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'missing scope' => [
        [
            'app' => '3',
            'hostname' => 'app.test',
            '--json' => true,
        ],
        'route.scope_required',
    ],
    'both scopes' => [
        [
            'app' => '3',
            'hostname' => 'app.test',
            '--node' => '4',
            '--cluster' => '5',
            '--json' => true,
        ],
        'route.scope_required',
    ],
    'target and scope' => [
        [
            'app' => '3',
            'hostname' => 'app.test',
            '--target' => '7',
            '--node' => '4',
            '--json' => true,
        ],
        'route.scope_conflict',
    ],
    'invalid target' => [
        [
            'app' => '3',
            'hostname' => 'app.test',
            '--target' => 'many',
            '--json' => true,
        ],
        'route.id_invalid',
    ],
]);

it('lists, shows, updates, targets, clears, and removes through exact requests', function (): void {
    $mock = MockClient::global([
        ListRoutesRequest::class => MockResponse::make([
            'data' => [route_payload()],
            'meta' => ['request_id' => route_request_id()],
        ]),
        ShowRouteRequest::class => route_mock_response(),
        UpdateRouteRequest::class => route_mock_response(),
        SetRouteTargetRequest::class => route_mock_response(),
        ClearRouteTargetRequest::class => route_mock_response(),
        RemoveRouteRequest::class => route_mock_response(),
    ]);

    $this->artisan('route:list', ['--json' => true])->assertExitCode(0);
    $this->artisan('route:show', ['route' => '11'])->assertExitCode(0);
    $this->artisan('route:update', ['route' => '11', '--hostname' => 'next.test'])->assertExitCode(0);
    expect($mock->getLastRequest()?->body()->all())->toBe(['hostname' => 'next.test']);
    $this->artisan('route:target:set', ['route' => '11', 'target' => '8'])->assertExitCode(0);
    expect($mock->getLastRequest()?->body()->all())->toBe(['app_instance_id' => 8]);
    $this->artisan('route:target:clear', ['route' => '11'])->assertExitCode(0);
    $this->artisan('route:remove', ['route' => '11'])->assertExitCode(0);
});

it('rejects an empty update and invalid IDs before transport', function (): void {
    $mock = MockClient::global();
    $this
        ->artisan('route:update', ['route' => '11', '--json' => true])
        ->expectsOutputToContain('route.update_required')
        ->assertExitCode(1);
    $this
        ->artisan('route:show', ['route' => 'zero', '--json' => true])
        ->expectsOutputToContain('route.id_invalid')
        ->assertExitCode(1);
    expect($mock->getLastPendingRequest())->toBeNull();
});

function route_mock_response(int $status = 200): MockResponse
{
    return MockResponse::make([
        'data' => route_payload(),
        'meta' => ['request_id' => route_request_id()],
    ], $status);
}

/** @return array<string, mixed> */
function route_payload(): array
{
    return [
        'id' => 11,
        'app_id' => 3,
        'node_id' => 4,
        'cluster_id' => null,
        'generation_basis_node_id' => null,
        'hostname' => 'app.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
        'failed_step' => null,
        'error_code' => null,
        'target' => ['id' => 12, 'app_instance_id' => 7, 'position' => 0],
    ];
}

function route_request_id(): string
{
    return '0198e15d-16c4-7855-8eb2-182b53ad28ba';
}
