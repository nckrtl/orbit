<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Routes\CreateRouteRequest;
use Orbit\Sdk\Requests\Routes\DestroyRouteRequest;
use Orbit\Sdk\Requests\Routes\ListRoutesRequest;
use Orbit\Sdk\Requests\Routes\SetRouteTargetRequest;
use Orbit\Sdk\Requests\Routes\ShowRouteRequest;
use Orbit\Sdk\Requests\Routes\UnsetRouteTargetRequest;
use Orbit\Sdk\Requests\Routes\UpdateRouteRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;

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

    $this->artisan('route:create', [
        'app' => '3',
        'domain' => 'Odd_Value',
        '--publication' => 'future-policy',
        '--target' => '7',
        '--json' => true,
    ])->assertExitCode(0);

    expect($mock->getLastRequest()?->body()->all())->toBe([
        'app_id' => 3,
        'domain' => 'Odd_Value',
        'publication' => 'future-policy',
        'app_instance_id' => 7,
    ]);

    $this->artisan('route:create', [
        'app' => '3',
        'domain' => 'node.test',
        '--node' => '4',
    ])->assertExitCode(0);

    expect($mock->getLastRequest()?->body()->all())->toHaveKey('node_id', 4);
});

it('transports explicit private and public publication intents unchanged', function (string $publication): void {
    $mock = MockClient::global([
        CreateRouteRequest::class => route_mock_response(201),
        UpdateRouteRequest::class => route_mock_response(),
    ]);

    $this->artisan('route:create', [
        'app' => '3',
        'domain' => 'app.test',
        '--publication' => $publication,
        '--cluster' => '5',
        '--json' => true,
    ])->assertExitCode(0);

    expect($mock->getLastRequest()?->body()->all())->toBe([
        'app_id' => 3,
        'domain' => 'app.test',
        'publication' => $publication,
        'cluster_id' => 5,
    ]);

    $this->artisan('route:update', ['route' => '11', '--publication' => $publication])->assertExitCode(0);

    expect($mock->getLastRequest()?->body()->all())->toBe(['publication' => $publication]);
})->with(['private', 'public']);

it('refuses a missing publication value before transport', function (
    string $command,
    array $arguments,
): void {
    $mock = MockClient::global();

    $exitCode = Artisan::call($command, [...$arguments, '--json' => true]);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(1);
    expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe([
        'error' => [
            'code' => 'route.publication_invalid',
            'message' => 'Publication intent must be a non-empty value.',
            'request_id' => null,
        ],
    ]);
    expect($mock->getLastPendingRequest())->toBeNull();

    $this
        ->artisan($command, $arguments)
        ->expectsOutputToContain('Publication intent must be a non-empty value.')
        ->doesntExpectOutputToContain('route.publication_invalid')
        ->assertExitCode(1);
    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'create without value' => ['route:create', ['app' => '1', 'domain' => 'pubtest.orbit', '--publication' => null, '--cluster' => '1']],
    'create with empty value' => ['route:create', ['app' => '1', 'domain' => 'pubtest.orbit', '--publication' => '', '--cluster' => '1']],
    'update without value' => ['route:update', ['route' => '11', '--publication' => null]],
    'update with empty value' => ['route:update', ['route' => '11', '--publication' => '']],
    'update with domain and no publication value' => ['route:update', ['route' => '11', '--domain' => 'next.test', '--publication' => null]],
]);

it('refuses the reported shell shape of a bare --publication flag', function (string $command): void {
    $mock = MockClient::global();

    $exitCode = Artisan::call($command);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(1);
    expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR)['error']['code'] ?? null)
        ->toBe('route.publication_invalid');
    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'route:create' => 'route:create 1 pubtest.orbit --publication --cluster=1 --json',
    'route:update' => 'route:update 11 --publication --json',
]);

it('rejects impossible create shapes before transport', function (array $arguments, string $code): void {
    $mock = MockClient::global();

    $this
        ->artisan('route:create', $arguments)
        ->expectsOutputToContain($code)
        ->assertExitCode(1);

    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'missing scope' => [
        [
            'app' => '3',
            'domain' => 'app.test',
            '--json' => true,
        ],
        'route.scope_required',
    ],
    'both scopes' => [
        [
            'app' => '3',
            'domain' => 'app.test',
            '--node' => '4',
            '--cluster' => '5',
            '--json' => true,
        ],
        'route.scope_required',
    ],
    'target and scope' => [
        [
            'app' => '3',
            'domain' => 'app.test',
            '--target' => '7',
            '--node' => '4',
            '--json' => true,
        ],
        'route.scope_conflict',
    ],
    'invalid target' => [
        [
            'app' => '3',
            'domain' => 'app.test',
            '--target' => 'many',
            '--json' => true,
        ],
        'route.id_invalid',
    ],
    'publication without value' => [
        [
            'app' => '3',
            'domain' => 'app.test',
            '--node' => '4',
            '--publication' => null,
            '--json' => true,
        ],
        'route.publication_invalid',
    ],
    'missing domain' => [
        [
            'app' => '3',
            'domain' => '',
            '--node' => '4',
            '--json' => true,
        ],
        'route.domain_required',
    ],
]);

it('renders only the first invalid input as one JSON document', function (
    string $command,
    array $arguments,
    string $code,
    string $message,
): void {
    $mock = MockClient::global();

    $exitCode = Artisan::call($command, [...$arguments, '--json' => true]);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(1);
    expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe([
        'error' => [
            'code' => $code,
            'message' => $message,
            'request_id' => null,
        ],
    ]);
    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'create Route' => [
        'route:create',
        [
            'app' => 'invalid',
            'domain' => '',
            '--publication' => null,
            '--target' => 'invalid',
            '--node' => 'invalid',
            '--cluster' => 'invalid',
        ],
        'app.id_invalid',
        'App ID must be a positive integer.',
    ],
    'set Route target' => [
        'route:target:set',
        ['route' => 'invalid', 'target' => 'invalid'],
        'route.id_invalid',
        'Route ID must be a positive integer.',
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
        UnsetRouteTargetRequest::class => route_mock_response(),
        DestroyRouteRequest::class => route_mock_response(),
    ]);

    $this->artisan('route:list', ['--json' => true])->assertExitCode(0);
    $this->artisan('route:show', ['route' => '11'])->assertExitCode(0);
    $this->artisan('route:update', ['route' => '11', '--domain' => 'next.test'])->assertExitCode(0);
    expect($mock->getLastRequest()?->body()->all())->toBe(['domain' => 'next.test']);
    $this->artisan('route:target:set', ['route' => '11', 'target' => '8'])->assertExitCode(0);
    expect($mock->getLastRequest()?->body()->all())->toBe(['app_instance_id' => 8]);
    $this->artisan('route:target:unset', ['route' => '11'])->assertExitCode(0);
    $this->artisan('route:destroy', ['route' => '11'])->assertExitCode(0);
});

it('rejects the removed hostname argument and option', function (string $command, array $arguments, string $message): void {
    $mock = MockClient::global();
    $tester = new CommandTester(app(Kernel::class)->all()[$command]);

    expect($tester->execute($arguments, ['interactive' => false]))->toBe(1);
    expect(trim($tester->getDisplay()))->toContain($message);
    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'create hostname argument' => [
        'route:create',
        ['app' => '3', 'hostname' => 'app.test', '--node' => '4', '--json' => true],
        'The "hostname" argument does not exist.',
    ],
    'update hostname option' => [
        'route:update',
        ['route' => '11', '--hostname' => 'next.test', '--json' => true],
        'The "--hostname" option does not exist.',
    ],
]);

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
        'domain' => 'app.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
        'failed_step' => null,
        'error_code' => null,
        'replaces_route_id' => null,
        'replaced_by_route_id' => null,
        'replacement_step' => null,
        'target' => ['id' => 12, 'app_instance_id' => 7, 'position' => 0],
    ];
}

function route_request_id(): string
{
    return '0198e15d-16c4-7855-8eb2-182b53ad28ba';
}
