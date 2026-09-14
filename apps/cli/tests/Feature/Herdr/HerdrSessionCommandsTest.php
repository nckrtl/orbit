<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Herdr\CreateHerdrSessionRequest;
use Orbit\Sdk\Requests\Herdr\DestroyHerdrSessionRequest;
use Orbit\Sdk\Requests\Herdr\IssueObservationGrantRequest;
use Orbit\Sdk\Requests\Herdr\ListHerdrSessionsRequest;
use Orbit\Sdk\Requests\Herdr\RestartHerdrSessionRequest;
use Orbit\Sdk\Requests\Herdr\ShowHerdrSessionRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-herdr-'.Str::uuid();
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

it('adds a named Herdr session through the active gateway', function (): void {
    $mock = MockClient::global([
        ListNodesRequest::class => herdr_cli_nodes_response(),
        CreateHerdrSessionRequest::class => herdr_cli_session_response(201),
    ]);

    $this
        ->artisan('herdr:session:create', [
            'session' => 'commander-tasks',
            '--node' => 'beast',
            '--user' => 'nckrtl',
            '--publish-observer' => true,
            '--json' => true,
        ])
        ->assertExitCode(0);

    expect($mock->getLastRequest())
        ->toBeInstanceOf(CreateHerdrSessionRequest::class)
        ->and($mock->getLastRequest()?->body()->all())
        ->toBe([
            'node_id' => 4,
            'session' => 'commander-tasks',
            'user' => 'nckrtl',
            'publish_observer' => true,
        ]);
});

it('lists Herdr sessions for one Node', function (): void {
    $mock = MockClient::global([
        ListHerdrSessionsRequest::class => MockResponse::make([
            'data' => [herdr_cli_session_payload()],
            'meta' => ['request_id' => herdr_cli_request_id()],
        ]),
    ]);

    $this
        ->artisan('herdr:session:list', [
            '--node' => '4',
            '--json' => true,
        ])
        ->assertExitCode(0);

    expect($mock->getLastRequest())
        ->toBeInstanceOf(ListHerdrSessionsRequest::class)
        ->and($mock->getLastRequest()?->query()->all())
        ->toBe(['node_id' => 4]);
});

it('shows one named Herdr session after resolving it on the Node', function (): void {
    $mock = MockClient::global([
        ListHerdrSessionsRequest::class => MockResponse::make([
            'data' => [herdr_cli_session_payload()],
            'meta' => ['request_id' => herdr_cli_request_id()],
        ]),
        ShowHerdrSessionRequest::class => herdr_cli_session_response(),
    ]);

    $this
        ->artisan('herdr:session:show', [
            'session' => 'commander-tasks',
            '--node' => '4',
            '--json' => true,
        ])
        ->assertExitCode(0);

    expect($mock->getLastRequest())
        ->toBeInstanceOf(ShowHerdrSessionRequest::class)
        ->and($mock->getLastRequest()?->resolveEndpoint())
        ->toBe('/api/v1/herdr/sessions/12');
});

it('restarts a named session with explicit handoff', function (): void {
    $mock = MockClient::global([
        ListHerdrSessionsRequest::class => MockResponse::make([
            'data' => [herdr_cli_session_payload()],
            'meta' => ['request_id' => herdr_cli_request_id()],
        ]),
        RestartHerdrSessionRequest::class => herdr_cli_session_response(),
    ]);

    $this
        ->artisan('herdr:session:restart', [
            'session' => 'commander-tasks',
            '--node' => '4',
            '--handoff' => true,
        ])
        ->assertExitCode(0);

    expect($mock->getLastRequest())
        ->toBeInstanceOf(RestartHerdrSessionRequest::class)
        ->and($mock->getLastRequest()?->body()->all())
        ->toBe(['handoff' => true]);
});

it('removes a named session with accepted termination', function (): void {
    $mock = MockClient::global([
        ListHerdrSessionsRequest::class => MockResponse::make([
            'data' => [herdr_cli_session_payload()],
            'meta' => ['request_id' => herdr_cli_request_id()],
        ]),
        DestroyHerdrSessionRequest::class => herdr_cli_session_response(),
    ]);

    $this
        ->artisan('herdr:session:destroy', [
            'session' => 'commander-tasks',
            '--node' => '4',
            '--accept-termination' => true,
        ])
        ->assertExitCode(0);

    expect($mock->getLastRequest())
        ->toBeInstanceOf(DestroyHerdrSessionRequest::class)
        ->and($mock->getLastRequest()?->body()->all())
        ->toBe(['accept_termination' => true]);
});

it('issues receive-only observation grants for panes on two Nodes without SSH or input', function (): void {
    $mock = MockClient::global([
        ListHerdrSessionsRequest::class => MockResponse::make([
            'data' => [herdr_cli_session_payload()],
            'meta' => ['request_id' => herdr_cli_request_id()],
        ]),
        IssueObservationGrantRequest::class => MockResponse::make([
            'data' => [
                'observer_url' => 'wss://commander-tasks.herdr.beast.orbit?access_token=orbit-grant-token',
                'scope' => 'terminal.observe',
                'pane' => 'w1:p1',
                'terminal' => 'term-abc',
                'cols' => 120,
                'rows' => 40,
                'expires_at' => '2026-09-13T21:00:00+00:00',
                'nonce' => 'aabbccddeeff00112233445566778899',
            ],
            'meta' => ['request_id' => herdr_cli_request_id()],
        ], 201),
    ]);

    $this
        ->artisan('herdr:observe', [
            'session' => 'commander-tasks',
            '--node' => '4',
            '--pane' => 'w1:p1',
            '--terminal' => 'term-abc',
            '--cols' => '120',
            '--rows' => '40',
            '--json' => true,
        ])
        ->assertExitCode(0);

    $this
        ->artisan('herdr:observe', [
            'session' => 'commander-tasks',
            '--node' => '5',
            '--pane' => 'w1:p1',
            '--terminal' => 'term-workhorse',
            '--cols' => '120',
            '--rows' => '40',
            '--json' => true,
        ])
        ->assertExitCode(0);

    expect($mock->getLastRequest())
        ->toBeInstanceOf(IssueObservationGrantRequest::class)
        ->and($mock->getLastRequest()?->body()->all())
        ->toBe([
            'pane' => 'w1:p1',
            'terminal' => 'term-workhorse',
            'cols' => 120,
            'rows' => 40,
        ])
        ->and($mock->getLastRequest()?->resolveEndpoint())
        ->toBe('/api/v1/herdr/sessions/12/observation-grants');
});

function herdr_cli_nodes_response(): MockResponse
{
    return MockResponse::make([
        'data' => [[
            'id' => 4,
            'name' => 'beast',
            'status' => 'active',
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'tld' => null,
            'public_ssh_host' => '203.0.113.7',
            'public_ssh_port' => 22,
            'user' => 'nckrtl',
            'wireguard_ip' => '10.44.0.7',
            'wireguard_public_key' => 'key',
            'wireguard_endpoint_override' => null,
            'dns_server_override' => null,
            'ssh_host_fingerprint' => null,
            'failed_step' => null,
            'error_code' => null,
            'roles' => [],
        ]],
        'meta' => ['request_id' => herdr_cli_request_id()],
    ]);
}

function herdr_cli_session_response(int $status = 200): MockResponse
{
    return MockResponse::make([
        'data' => herdr_cli_session_payload(),
        'meta' => ['request_id' => herdr_cli_request_id()],
    ], $status);
}

/** @return array<string, mixed> */
function herdr_cli_session_payload(): array
{
    return [
        'id' => 12,
        'node' => 'beast',
        'node_id' => 4,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'process_id' => 481,
        'observer_url' => 'wss://commander-tasks.herdr.beast.orbit',
        'status' => 'active',
        'herdr_version' => '0.9.0',
        'protocol' => 22,
        'health' => [
            'process' => 'healthy',
            'listener' => 'healthy',
            'session' => 'healthy',
        ],
        'failed_step' => null,
        'error_code' => null,
    ];
}

function herdr_cli_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
