<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Firewall\AllowFirewallRuleRequest;
use Orbit\Sdk\Requests\Firewall\DenyFirewallRuleRequest;
use Orbit\Sdk\Requests\Firewall\ListFirewallRulesRequest;
use Orbit\Sdk\Requests\Firewall\RemoveFirewallRuleRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-firewall-'.Str::uuid();
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

it('sends normalized allow input through the active gateway as JSON', function (): void {
    $mock = MockClient::global([
        AllowFirewallRuleRequest::class => firewall_cli_response(action: 'allow', backendStatus: 'active', status: 201),
    ]);

    $this
        ->artisan('firewall:allow', [
            'name' => 'private-web',
            '--node' => '7',
            '--from' => '192.0.2.0/24',
            '--protocol' => 'tcp',
            '--port' => '443',
            '--json' => true,
        ])
        ->expectsOutput(firewall_cli_json(action: 'allow', backendStatus: 'active'))
        ->assertExitCode(0);

    expect($mock->getLastPendingRequest()?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/nodes/7/firewall-rules/allow')
        ->and($mock->getLastRequest())
        ->toBeInstanceOf(AllowFirewallRuleRequest::class)
        ->and($mock->getLastRequest()?->body()->all())
        ->toBe([
            'name' => 'private-web',
            'source' => '192.0.2.0/24',
            'protocol' => 'tcp',
            'port' => '443',
        ]);
});

it('omits optional firewall source and protocol for Gateway defaults', function (
    string $command,
    string $requestClass,
): void {
    $mock = MockClient::global([
        $requestClass => firewall_cli_response(
            action: $command === 'firewall:allow' ? 'allow' : 'deny',
            backendStatus: 'active',
            status: 201,
        ),
    ]);

    $this
        ->artisan($command, [
            'name' => 'web',
            '--node' => '7',
            '--port' => '443',
        ])
        ->assertExitCode(0);

    expect($mock->getLastRequest())
        ->toBeInstanceOf($requestClass)
        ->and($mock->getLastRequest()?->body()->all())
        ->toBe([
            'name' => 'web',
            'port' => '443',
        ]);
})->with([
    'allow' => ['firewall:allow', AllowFirewallRuleRequest::class],
    'deny' => ['firewall:deny', DenyFirewallRuleRequest::class],
]);

it('reports inactive UFW as a structured gateway failure', function (
    string $command,
    string $requestClass,
    array $arguments,
): void {
    $mock = MockClient::global([
        $requestClass => firewall_cli_backend_inactive_response(),
    ]);

    $this
        ->artisan($command, $arguments)
        ->expectsOutputToContain('The firewall backend is inactive.')
        ->expectsOutput('Request ID: '.firewall_cli_request_id())
        ->doesntExpectOutputToContain('stored')
        ->doesntExpectOutputToContain('kept')
        ->assertExitCode(1);

    expect($mock->getLastRequest())
        ->toBeInstanceOf($requestClass)
        ->and($mock->getRecordedResponses())
        ->toHaveCount(1);
})->with([
    'allow' => [
        'firewall:allow',
        AllowFirewallRuleRequest::class,
        ['name' => 'private-web', '--node' => '7', '--port' => '443'],
    ],
    'deny' => [
        'firewall:deny',
        DenyFirewallRuleRequest::class,
        ['name' => 'block-admin', '--node' => '7', '--port' => '9000'],
    ],
    'remove' => [
        'firewall:remove',
        RemoveFirewallRuleRequest::class,
        ['name' => 'private-web', '--node' => '7'],
    ],
]);

it('reports inactive UFW with the exact safe JSON error envelope', function (
    string $command,
    string $requestClass,
    array $arguments,
): void {
    $mock = MockClient::global([
        $requestClass => firewall_cli_backend_inactive_response(),
    ]);
    $expected = json_encode([
        'error' => [
            'code' => 'firewall.backend_inactive',
            'message' => 'The firewall backend is inactive.',
            'request_id' => firewall_cli_request_id(),
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan($command, [...$arguments, '--json' => true])
        ->expectsOutput($expected)
        ->doesntExpectOutputToContain('backend_status')
        ->doesntExpectOutputToContain('stored')
        ->doesntExpectOutputToContain('kept')
        ->assertExitCode(1);

    expect($mock->getLastRequest())
        ->toBeInstanceOf($requestClass)
        ->and($mock->getRecordedResponses())
        ->toHaveCount(1);
})->with([
    'allow JSON' => [
        'firewall:allow',
        AllowFirewallRuleRequest::class,
        ['name' => 'private-web', '--node' => '7', '--port' => '443'],
    ],
    'deny JSON' => [
        'firewall:deny',
        DenyFirewallRuleRequest::class,
        ['name' => 'block-admin', '--node' => '7', '--port' => '9000'],
    ],
    'remove JSON' => [
        'firewall:remove',
        RemoveFirewallRuleRequest::class,
        ['name' => 'private-web', '--node' => '7'],
    ],
]);

it('lists stable named rules for one node', function (): void {
    MockClient::global([
        ListFirewallRulesRequest::class => MockResponse::make([
            'data' => [firewall_cli_payload(action: 'allow', backendStatus: null)],
            'meta' => ['request_id' => firewall_cli_request_id()],
        ]),
    ]);

    $this
        ->artisan('firewall:list', ['--node' => '7'])
        ->expectsTable(
            ['Name', 'Action', 'Source', 'Port', 'Protocol', 'Status'],
            [['private-web', 'allow', '192.0.2.0/24', '443', 'tcp', 'active']],
        )
        ->expectsOutput('Request ID: '.firewall_cli_request_id())
        ->assertExitCode(0);
});

it('removes one stable name within one node', function (): void {
    $mock = MockClient::global([
        RemoveFirewallRuleRequest::class => firewall_cli_response(action: 'allow', backendStatus: 'absent'),
    ]);

    $this
        ->artisan('firewall:remove', [
            'name' => 'private-web',
            '--node' => '7',
        ])
        ->expectsOutput('Firewall rule [private-web] removed.')
        ->expectsOutput('Request ID: '.firewall_cli_request_id())
        ->assertExitCode(0);

    expect($mock->getLastPendingRequest()?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/nodes/7/firewall-rules/private-web');
});

it('rejects invalid local input before making a gateway request', function (
    string $command,
    array $arguments,
    string $message,
): void {
    $mock = MockClient::global();

    $this
        ->artisan($command, $arguments)
        ->expectsOutputToContain($message)
        ->assertExitCode(1);

    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'node ID' => [
        'firewall:list',
        ['--node' => 'app-dev'],
        'Node ID must be a positive integer.',
    ],
    'name' => [
        'firewall:allow',
        ['name' => 'Private Web', '--node' => '7', '--port' => '443'],
        'Firewall rule name is invalid.',
    ],
    'protocol' => [
        'firewall:allow',
        ['name' => 'private-web', '--node' => '7', '--port' => '443', '--protocol' => 'sctp'],
        'Firewall protocol must be tcp or udp.',
    ],
    'source' => [
        'firewall:allow',
        ['name' => 'private-web', '--node' => '7', '--port' => '443', '--from' => 'example.test'],
        'Firewall source must be any or a valid IPv4 or IPv6 address or CIDR.',
    ],
    'port' => [
        'firewall:deny',
        ['name' => 'block-admin', '--node' => '7', '--port' => '9000:8000'],
        'Firewall port must be from 1 to 65535 or an ordered range.',
    ],
]);

it('shows the gateway request ID on bounded failures', function (): void {
    MockClient::global([
        AllowFirewallRuleRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'firewall.rule_collision',
                    'message' => 'The managed firewall name is ambiguous.',
                    'details' => [],
                ],
            ],
            502,
            ['X-Orbit-Request-Id' => firewall_cli_request_id()],
        ),
    ]);

    $this
        ->artisan('firewall:allow', [
            'name' => 'private-web',
            '--node' => '7',
            '--port' => '443',
        ])
        ->expectsOutputToContain('The managed firewall name is ambiguous.')
        ->expectsOutput('Request ID: '.firewall_cli_request_id())
        ->assertExitCode(1);
});

function firewall_cli_response(string $action, ?string $backendStatus, int $status = 200): MockResponse
{
    return MockResponse::make([
        'data' => firewall_cli_payload($action, $backendStatus),
        'meta' => ['request_id' => firewall_cli_request_id()],
    ], $status);
}

function firewall_cli_backend_inactive_response(): MockResponse
{
    return MockResponse::make(
        [
            'error' => [
                'code' => 'firewall.backend_inactive',
                'message' => 'The firewall backend is inactive.',
                'details' => ['backend_status' => 'inactive'],
            ],
        ],
        503,
        ['X-Orbit-Request-Id' => firewall_cli_request_id()],
    );
}

/** @return array<string, mixed> */
function firewall_cli_payload(string $action, ?string $backendStatus): array
{
    return [
        'id' => 11,
        'node_id' => 7,
        'node' => 'app-dev',
        'name' => $action === 'deny' ? 'block-admin' : 'private-web',
        'action' => $action,
        'source' => $action === 'deny' ? 'any' : '192.0.2.0/24',
        'protocol' => 'tcp',
        'port' => $action === 'deny' ? '9000' : '443',
        'status' => 'active',
        'backend_status' => $backendStatus,
        'failed_step' => null,
        'error_code' => null,
    ];
}

function firewall_cli_json(string $action, ?string $backendStatus): string
{
    return json_encode([
        ...firewall_cli_payload($action, $backendStatus),
        'request_id' => firewall_cli_request_id(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function firewall_cli_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
