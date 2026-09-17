<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\Extensions\LocalExtensionState;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Herdr\AdoptHerdrSessionRequest;
use Orbit\Sdk\Requests\Herdr\CreateHerdrSessionRequest;
use Orbit\Sdk\Requests\Herdr\DestroyHerdrSessionRequest;
use Orbit\Sdk\Requests\Herdr\IssueObservationGrantRequest;
use Orbit\Sdk\Requests\Herdr\ListHerdrSessionsRequest;
use Orbit\Sdk\Requests\Herdr\RestartHerdrSessionRequest;
use Orbit\Sdk\Requests\Herdr\ShowHerdrSessionRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->previousColumns = getenv('COLUMNS');
    putenv('COLUMNS=200');
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-herdr-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app()->forgetInstance(LocalExtensionState::class);
    app(LocalExtensionState::class)->enable('herdr');
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
    if ($this->previousColumns === false) {
        putenv('COLUMNS');
    } else {
        putenv('COLUMNS='.$this->previousColumns);
    }
});

it('adds a named Herdr session through the active gateway', function (): void {
    $mock = MockClient::global([
        ListNodesRequest::class => herdr_cli_nodes_response(),
        CreateHerdrSessionRequest::class => herdr_cli_session_response(201),
    ]);

    [$exit, $output] = herdr_cli_display('herdr:session:create', [
        'session' => 'commander-tasks',
        '--node' => 'beast',
        '--user' => 'nckrtl',
        '--publish-observer' => true,
    ]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('commander-tasks')
        ->and($output)->toContain('beast')
        ->and($output)->toContain('managed')
        ->and($output)->toContain('active')
        ->and($output)->toContain(herdr_cli_request_id());

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

it('returns the exact typed json for a created session', function (): void {
    MockClient::global([
        ListNodesRequest::class => herdr_cli_nodes_response(),
        CreateHerdrSessionRequest::class => herdr_cli_session_response(201),
    ]);

    [$exit, $output] = herdr_cli_display('herdr:session:create', [
        'session' => 'commander-tasks',
        '--node' => 'beast',
        '--user' => 'nckrtl',
        '--publish-observer' => true,
        '--json' => true,
    ]);

    expect($exit)->toBe(0);
    expect(json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR))
        ->toBe([...herdr_cli_session_payload(), 'request_id' => herdr_cli_request_id()]);
});

it('adopts an existing Herdr session for observation through the active gateway', function (): void {
    $mock = MockClient::global([
        ListNodesRequest::class => herdr_cli_nodes_response(),
        AdoptHerdrSessionRequest::class => herdr_cli_session_response(201, ['management' => 'external', 'process_id' => null]),
    ]);

    [$exit, $output] = herdr_cli_display('herdr:session:adopt', [
        'session' => 'commander-tasks',
        '--node' => 'beast',
        '--user' => 'nckrtl',
        '--publish-observer' => true,
    ]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('commander-tasks')
        ->and($output)->toContain('external')
        ->and($output)->toContain('adopted for observation');

    expect($mock->getLastRequest())
        ->toBeInstanceOf(AdoptHerdrSessionRequest::class)
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

    [$exit, $output] = herdr_cli_display('herdr:session:list', ['--node' => '4']);

    expect($exit)->toBe(0)
        ->and($output)->toContain('commander-tasks')
        ->and($output)->toContain('beast')
        ->and($output)->toContain('Request ID: '.herdr_cli_request_id());

    expect($mock->getLastRequest())
        ->toBeInstanceOf(ListHerdrSessionsRequest::class)
        ->and($mock->getLastRequest()?->query()->all())
        ->toBe(['node_id' => 4]);
});

it('states no matching records for an empty session list', function (): void {
    MockClient::global([
        ListHerdrSessionsRequest::class => MockResponse::make([
            'data' => [],
            'meta' => ['request_id' => herdr_cli_request_id()],
        ]),
    ]);

    [$exit, $output] = herdr_cli_display('herdr:session:list', ['--node' => '4']);

    expect($exit)->toBe(0)
        ->and($output)->toContain('No matching records found.')
        ->and($output)->toContain('Request ID: '.herdr_cli_request_id());
});

it('shows one named Herdr session after resolving it on the Node', function (): void {
    $mock = MockClient::global([
        ListHerdrSessionsRequest::class => MockResponse::make([
            'data' => [herdr_cli_session_payload()],
            'meta' => ['request_id' => herdr_cli_request_id()],
        ]),
        ShowHerdrSessionRequest::class => herdr_cli_session_response(payload: [
            'failed_step' => 'observer',
            'error_code' => 'herdr.observer_failed',
        ]),
    ]);

    [$exit, $output] = herdr_cli_display('herdr:session:show', [
        'session' => 'commander-tasks',
        '--node' => '4',
    ]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('commander-tasks')
        ->and($output)->toContain('Node ID')
        ->and($output)->toContain('healthy')
        ->and($output)->toContain('observer')
        ->and($output)->toContain('herdr.observer_failed');

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

    [$exit, $output] = herdr_cli_display('herdr:session:restart', [
        'session' => 'commander-tasks',
        '--node' => '4',
        '--handoff' => true,
    ]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('commander-tasks')
        ->and($output)->toContain('active');

    expect($mock->getLastRequest())
        ->toBeInstanceOf(RestartHerdrSessionRequest::class)
        ->and($mock->getLastRequest()?->body()->all())
        ->toBe(['handoff' => true]);
});

it('restarts a named session without handoff', function (): void {
    $mock = MockClient::global([
        ListHerdrSessionsRequest::class => MockResponse::make([
            'data' => [herdr_cli_session_payload()],
            'meta' => ['request_id' => herdr_cli_request_id()],
        ]),
        RestartHerdrSessionRequest::class => herdr_cli_session_response(),
    ]);

    [$exit] = herdr_cli_display('herdr:session:restart', [
        'session' => 'commander-tasks',
        '--node' => '4',
    ]);

    expect($exit)->toBe(0);

    expect($mock->getLastRequest())
        ->toBeInstanceOf(RestartHerdrSessionRequest::class)
        ->and($mock->getLastRequest()?->body()->all())
        ->toBe(['handoff' => false]);
});

it('removes a managed session with explicit consent and accepted termination', function (): void {
    $mock = MockClient::global([
        ListHerdrSessionsRequest::class => MockResponse::make([
            'data' => [herdr_cli_session_payload()],
            'meta' => ['request_id' => herdr_cli_request_id()],
        ]),
        DestroyHerdrSessionRequest::class => herdr_cli_session_response(),
    ]);

    [$exit, $output] = herdr_cli_display('herdr:session:destroy', [
        'session' => 'commander-tasks',
        '--node' => '4',
        '--accept-termination' => true,
        '--yes' => true,
    ]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('removed from Orbit');

    expect($mock->getLastRequest())
        ->toBeInstanceOf(DestroyHerdrSessionRequest::class)
        ->and($mock->getLastRequest()?->body()->all())
        ->toBe(['accept_termination' => true]);
});

it('refuses JSON session destruction without --yes and sends no mutation', function (): void {
    $mock = MockClient::global([
        ListHerdrSessionsRequest::class => MockResponse::make([
            'data' => [herdr_cli_session_payload()],
            'meta' => ['request_id' => herdr_cli_request_id()],
        ]),
    ]);

    [$exit, $output] = herdr_cli_display('herdr:session:destroy', [
        'session' => 'commander-tasks',
        '--node' => '4',
        '--json' => true,
    ]);

    expect($exit)->toBe(1);
    expect(json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'error' => [
            'code' => 'input.confirmation_required',
            'message' => 'Supply --yes to confirm this operation.',
            'request_id' => null,
        ],
    ]);

    expect($mock->getLastRequest())->toBeInstanceOf(ListHerdrSessionsRequest::class);
    $mock->assertNotSent(DestroyHerdrSessionRequest::class);
});

it('refuses human session destruction without --yes outside a prompt and sends no mutation', function (): void {
    $mock = MockClient::global([
        ListHerdrSessionsRequest::class => MockResponse::make([
            'data' => [herdr_cli_session_payload()],
            'meta' => ['request_id' => herdr_cli_request_id()],
        ]),
    ]);

    [$exit, $output] = herdr_cli_display('herdr:session:destroy', [
        'session' => 'commander-tasks',
        '--node' => '4',
    ]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('Supply --yes to confirm this operation.');

    $mock->assertNotSent(DestroyHerdrSessionRequest::class);
});

it('fails with herdr.session_in_use when a live pane blocks removal without --accept-termination', function (): void {
    $mock = MockClient::global([
        ListHerdrSessionsRequest::class => MockResponse::make([
            'data' => [herdr_cli_session_payload()],
            'meta' => ['request_id' => herdr_cli_request_id()],
        ]),
        DestroyHerdrSessionRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'herdr.session_in_use',
                    'message' => 'Herdr session [commander-tasks] still has live panes.',
                    'details' => [],
                ],
            ],
            409,
            ['X-Orbit-Request-Id' => herdr_cli_request_id()],
        ),
    ]);

    [$exit, $output] = herdr_cli_display('herdr:session:destroy', [
        'session' => 'commander-tasks',
        '--node' => '4',
        '--yes' => true,
    ]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('still has live panes');

    expect($mock->getLastRequest())->toBeInstanceOf(DestroyHerdrSessionRequest::class);
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

    [$exitOne] = herdr_cli_display('herdr:observe', [
        'session' => 'commander-tasks',
        '--node' => '4',
        '--pane' => 'w1:p1',
        '--terminal' => 'term-abc',
        '--cols' => '120',
        '--rows' => '40',
        '--origin' => 'https://tasks.commander.test',
        '--json' => true,
    ]);

    expect($exitOne)->toBe(0);

    [$exitTwo] = herdr_cli_display('herdr:observe', [
        'session' => 'commander-tasks',
        '--node' => '5',
        '--pane' => 'w1:p1',
        '--terminal' => 'term-workhorse',
        '--cols' => '120',
        '--rows' => '40',
        '--origin' => 'https://tasks.commander.test',
        '--json' => true,
    ]);

    expect($exitTwo)->toBe(0);

    expect($mock->getLastRequest())
        ->toBeInstanceOf(IssueObservationGrantRequest::class)
        ->and($mock->getLastRequest()?->body()->all())
        ->toBe([
            'pane' => 'w1:p1',
            'terminal' => 'term-workhorse',
            'cols' => 120,
            'rows' => 40,
            'origin' => 'https://tasks.commander.test',
        ])
        ->and($mock->getLastRequest()?->resolveEndpoint())
        ->toBe('/api/v1/herdr/sessions/12/observation-grants');
});

it('delivers the observer URL as a raw credential line in human output', function (): void {
    MockClient::global([
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

    [$exit, $output] = herdr_cli_display('herdr:observe', [
        'session' => 'commander-tasks',
        '--node' => '4',
        '--pane' => 'w1:p1',
        '--terminal' => 'term-abc',
        '--cols' => '120',
        '--rows' => '40',
        '--origin' => 'https://tasks.commander.test',
    ]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('wss://commander-tasks.herdr.beast.orbit?access_token=orbit-grant-token')
        ->and($output)->not->toContain('[redacted]');
});

it('rejects an unsafe observation origin without sending a request', function (): void {
    $mock = MockClient::global([]);

    [$exit, $output] = herdr_cli_display('herdr:observe', [
        'session' => 'commander-tasks',
        '--node' => '4',
        '--pane' => 'w1:p1',
        '--terminal' => 'term-abc',
        '--cols' => '120',
        '--rows' => '40',
        '--origin' => 'http://tasks.commander.test',
        '--json' => true,
    ]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('valid HTTPS origin');

    $mock->assertNothingSent();
});

function herdr_cli_display(string $command, array $arguments = []): array
{
    $tester = new CommandTester(app(Kernel::class)->all()[$command]);

    return [$tester->execute($arguments, ['interactive' => false]), $tester->getDisplay(true)];
}

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

/** @param array<string, mixed> $payload */
function herdr_cli_session_response(int $status = 200, array $payload = []): MockResponse
{
    return MockResponse::make([
        'data' => [...herdr_cli_session_payload(), ...$payload],
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
        'management' => 'managed',
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
