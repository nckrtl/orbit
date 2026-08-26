<?php

declare(strict_types=1);

use App\Commands\Nodes\AddNodeRoleCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\AddNodeRoleRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-role-'.Str::uuid();
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

it('adds one node role and renders the exact lifecycle output', function (): void {
    expect(class_exists(AddNodeRoleCommand::class))->toBeTrue();

    $mock = MockClient::global([
        AddNodeRoleRequest::class => node_role_response(),
    ]);

    $this
        ->artisan('node:role:add', ['node' => '2', 'role' => 'app-dev'])
        ->expectsOutput('Role [app-dev] assigned to node [mini]; status: provisioning.')
        ->expectsOutput('Local setup required: orbit node:setup app-dev')
        ->expectsOutput('Request ID: '.node_role_request_id())
        ->assertExitCode(0);

    expect($mock->getLastPendingRequest()?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/nodes/2/roles')
        ->and($mock->getLastRequest())
        ->toBeInstanceOf(AddNodeRoleRequest::class)
        ->and($mock->getLastRequest()?->body()->all())
        ->toBe(['role' => 'app-dev']);
});

it('returns the exact typed role response in JSON mode', function (): void {
    MockClient::global([
        AddNodeRoleRequest::class => node_role_response(),
    ]);
    $expected = json_encode([
        ...node_role_payload(),
        'request_id' => node_role_request_id(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan('node:role:add', ['node' => '2', 'role' => 'app-dev', '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(0);
});

it('allows the same numeric role request to be repeated', function (): void {
    $mock = MockClient::global([
        AddNodeRoleRequest::class => node_role_response(),
    ]);

    $this->artisan('node:role:add', ['node' => '2', 'role' => 'app-dev'])->assertExitCode(0);
    $this->artisan('node:role:add', ['node' => '2', 'role' => 'app-dev'])->assertExitCode(0);

    expect($mock->getRecordedResponses())->toHaveCount(2);
});

it('rejects an invalid node ID before gateway IO', function (string $node): void {
    $mock = MockClient::global();

    $this
        ->artisan('node:role:add', ['node' => $node, 'role' => 'app-dev'])
        ->expectsOutputToContain('Node ID must be a positive integer.')
        ->assertExitCode(1);

    expect($mock->getLastPendingRequest())->toBeNull();
})->with(['mini', '0', '-1']);

/** @return array<string, mixed> */
function node_role_payload(): array
{
    return [
        'node_id' => 2,
        'node_name' => 'mini',
        'assignment' => [
            'role' => 'app-dev',
            'status' => 'provisioning',
            'failed_step' => null,
            'error_code' => null,
            'local_action_required' => true,
            'local_command' => 'orbit node:setup app-dev',
        ],
    ];
}

function node_role_response(): MockResponse
{
    return MockResponse::make([
        'data' => node_role_payload(),
        'meta' => ['request_id' => node_role_request_id()],
    ]);
}

function node_role_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
