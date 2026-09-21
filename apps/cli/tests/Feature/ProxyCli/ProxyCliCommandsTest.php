<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\Extensions\LocalExtensionState;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\ProxyCli\DisableProxyCliRequest;
use Orbit\Sdk\Requests\ProxyCli\EnableProxyCliRequest;
use Orbit\Sdk\Requests\ProxyCli\ListProxyCliProvidersRequest;
use Orbit\Sdk\Requests\ProxyCli\ShowProxyCliProviderRequest;
use Orbit\Sdk\Requests\ProxyCli\ShowProxyCliStatusRequest;
use Orbit\Sdk\Requests\ProxyCli\UpdateProxyCliAccountRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->previousColumns = getenv('COLUMNS');
    putenv('COLUMNS=200');
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-proxycli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app()->forgetInstance(LocalExtensionState::class);
    app(LocalExtensionState::class)->enable('proxycli');
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

it('enables the fleet collector from a key file and never prints the key', function (): void {
    $keyFile = $this->orbitHome.'/management.key';
    file_put_contents($keyFile, "management-key\n");
    chmod($keyFile, 0600);

    $mock = MockClient::global([
        ListNodesRequest::class => proxycli_cli_nodes_response(),
        EnableProxyCliRequest::class => proxycli_cli_status_response(),
    ]);

    [$exit, $output] = proxycli_cli_display('proxycli:enable', [
        '--node' => 'beast',
        '--cache-connection' => 'valkey',
        '--cliproxy-url' => 'http://127.0.0.1:8317',
        '--cliproxy-management-key-file' => $keyFile,
        '--json' => true,
    ]);

    expect($exit)->toBe(0)
        ->and(json_decode($output, true)['enabled'])->toBeTrue()
        ->and($output)->not->toContain('management-key')
        ->and($mock->getLastPendingRequest()->body()->all()['cliproxy_management_key'])->toBe('management-key');
});

it('refuses a management key on the command line by requiring a file', function (): void {
    [$exit, $output] = proxycli_cli_display('proxycli:enable', [
        '--node' => '4',
        '--cache-connection' => 'valkey',
        '--cliproxy-url' => 'http://127.0.0.1:8317',
        '--json' => true,
    ]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('proxycli.key_required')
        ->and($output)->not->toContain('cliproxy_management_key');
});

it('lists provider windows from the snapshot without Primary or Secondary labels', function (): void {
    MockClient::global([
        ListProxyCliProvidersRequest::class => MockResponse::make([
            'data' => [proxycli_cli_provider_payload()],
            'meta' => ['request_id' => proxycli_cli_request_id()],
        ]),
    ]);

    [$exit, $output] = proxycli_cli_display('proxycli:list');

    expect($exit)->toBe(0)
        ->and($output)->toContain('codex', '7d', '5h')
        ->and($output)->not->toContain('Primary', 'Secondary');
});

it('shows one provider and omits a window the snapshot did not return', function (): void {
    MockClient::global([
        ShowProxyCliProviderRequest::class => MockResponse::make([
            'data' => proxycli_cli_provider_payload(windows: [
                ['label' => '7d', 'used_percent' => 40.0, 'remaining_percent' => 60.0, 'resets_at' => '2026-09-27T00:00:00Z'],
            ]),
            'meta' => ['request_id' => proxycli_cli_request_id()],
        ]),
    ]);

    [$exit, $output] = proxycli_cli_display('proxycli:show', ['provider' => 'codex']);

    expect($exit)->toBe(0)
        ->and($output)->toContain('plus.json', '7d 60% remaining')
        ->and($output)->not->toContain('5h', 'Primary', 'Secondary', ' 0% remaining');
});

it('updates an account from cache after the status patch', function (): void {
    $mock = MockClient::global([
        UpdateProxyCliAccountRequest::class => MockResponse::make([
            'data' => [
                ...proxycli_cli_account_payload(),
                'disabled' => true,
            ],
            'meta' => ['request_id' => proxycli_cli_request_id()],
        ]),
    ]);

    [$exit, $output] = proxycli_cli_display('proxycli:update', [
        'account' => 'plus.json',
        '--disabled' => true,
        '--json' => true,
    ]);

    expect($exit)->toBe(0)
        ->and(json_decode($output, true)['disabled'])->toBeTrue()
        ->and($mock->getLastPendingRequest()->body()->all())->toBe(['disabled' => true]);
});

it('disables the fleet collector', function (): void {
    MockClient::global([
        DisableProxyCliRequest::class => proxycli_cli_status_response(payload: [
            'enabled' => false,
            'collected_at' => null,
        ]),
    ]);

    [$exit, $output] = proxycli_cli_display('proxycli:disable', ['--json' => true]);

    expect($exit)->toBe(0)
        ->and(json_decode($output, true)['enabled'])->toBeFalse();
});

it('shows fleet status including a null collected_at', function (): void {
    MockClient::global([
        ShowProxyCliStatusRequest::class => proxycli_cli_status_response(payload: [
            'collected_at' => null,
        ]),
    ]);

    [$exit, $output] = proxycli_cli_display('proxycli:status', ['--json' => true]);

    expect($exit)->toBe(0)
        ->and(json_decode($output, true)['collected_at'])->toBeNull()
        ->and(json_decode($output, true)['hostname'])->toBe('collector.proxycli.orbit');
});

it('refuses update unless exactly one account state flag is supplied', function (): void {
    [$exit, $output] = proxycli_cli_display('proxycli:update', [
        'account' => 'plus.json',
        '--json' => true,
    ]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('proxycli.state_required');
});

/**
 * @param  array<string, mixed>  $arguments
 * @return array{0: int, 1: string}
 */
function proxycli_cli_display(string $command, array $arguments = []): array
{
    $tester = new CommandTester(app(Kernel::class)->all()[$command]);

    return [$tester->execute($arguments, ['interactive' => false]), $tester->getDisplay(true)];
}

function proxycli_cli_nodes_response(): MockResponse
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
        'meta' => ['request_id' => proxycli_cli_request_id()],
    ]);
}

/** @param array<string, mixed> $payload */
function proxycli_cli_status_response(array $payload = []): MockResponse
{
    return MockResponse::make([
        'data' => [
            'enabled' => true,
            'hostname' => 'collector.proxycli.orbit',
            'node_id' => 4,
            'cache_connection' => 'valkey',
            'collected_at' => '2026-09-20T12:00:00Z',
            ...$payload,
        ],
        'meta' => ['request_id' => proxycli_cli_request_id()],
    ]);
}

/**
 * @param  list<array<string, mixed>>|null  $windows
 * @return array<string, mixed>
 */
function proxycli_cli_provider_payload(?array $windows = null): array
{
    $windows ??= [
        ['label' => '7d', 'used_percent' => 40.0, 'remaining_percent' => 60.0, 'resets_at' => '2026-09-27T00:00:00Z'],
        ['label' => '5h', 'used_percent' => 10.0, 'remaining_percent' => 90.0, 'resets_at' => null],
    ];

    return [
        'provider' => 'codex',
        'windows' => $windows,
        'accounts' => [proxycli_cli_account_payload($windows)],
    ];
}

/**
 * @param  list<array<string, mixed>>|null  $windows
 * @return array<string, mixed>
 */
function proxycli_cli_account_payload(?array $windows = null): array
{
    return [
        'id' => 'plus.json',
        'provider' => 'codex',
        'label' => 'plus',
        'disabled' => false,
        'status' => 'ok',
        'windows' => $windows ?? [
            ['label' => '7d', 'used_percent' => 40.0, 'remaining_percent' => 60.0, 'resets_at' => '2026-09-27T00:00:00Z'],
            ['label' => '5h', 'used_percent' => 10.0, 'remaining_percent' => 90.0, 'resets_at' => null],
        ],
        'error' => null,
    ];
}

function proxycli_cli_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
