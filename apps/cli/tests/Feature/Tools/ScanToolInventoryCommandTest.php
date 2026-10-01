<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Tools\ScanToolInventoryRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->previousColumns = getenv('COLUMNS');
    putenv('COLUMNS=120');
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-tool-scan-'.Str::uuid();
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
    if ($this->previousColumns === false) {
        putenv('COLUMNS');
    } else {
        putenv('COLUMNS='.$this->previousColumns);
    }
});

it('sends only the inventory read and renders formula, cask, dependency, and unsupported rows', function (): void {
    $mock = MockClient::global(gateway_fixture_mock('tools/tool-scan/discovered'));

    expect(Artisan::call('tool:scan', ['--node' => '2']))->toBe(0);
    $output = Artisan::output();

    expect($output)
        ->toContain('ripgrep')
        ->toContain('formula')
        ->toContain('openssl@3')
        ->toContain('dependency')
        ->toContain('wireguard-tools')
        ->toContain('protected')
        ->toContain('docker')
        ->toContain('cask')
        ->toContain('authorization_required')
        ->toContain('font-hack')
        ->toContain('@openai/codex')
        ->toContain('pnpm')
        ->toContain('version_unreadable')
        ->toContain('Node #2 observed at 2026-04-26T12:00:00+00:00.')
        ->not->toContain('not an inventory');

    expect($mock->getLastRequest()?->getMethod())
        ->toBe(Method::GET)
        ->and($mock->getLastPendingRequest()?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/tool-inventory')
        ->and($mock->getLastRequest()?->query()->all())
        ->toBe(['node_id' => 2])
        ->and($mock->getLastRequest())
        ->toBeInstanceOf(ScanToolInventoryRequest::class);
});

it('renders an empty completed inventory without calling it a failed scan', function (): void {
    MockClient::global(gateway_fixture_mock('tools/tool-scan/empty'));

    expect(Artisan::call('tool:scan', ['--node' => '2']))->toBe(0);
    expect(Artisan::output())
        ->toContain('No installed packages.')
        ->toContain('complete')
        ->not->toContain('not an inventory');
});

it('names partial and unsupported scans instead of presenting an empty inventory', function (string $fixture, string $state): void {
    MockClient::global(gateway_fixture_mock($fixture));

    expect(Artisan::call('tool:scan', ['--node' => '2']))->toBe(0);
    expect(Artisan::output())
        ->toContain($state)
        ->toContain('not an inventory')
        ->toContain('ripgrep')
        ->not->toContain('No installed packages.');
})->with([
    'linux cask' => ['tools/tool-scan/linux', 'unsupported'],
    'linux vite' => ['tools/tool-scan/linux', 'absent'],
    'partial cask' => ['tools/tool-scan/partial', 'incomplete'],
    'partial vite' => ['tools/tool-scan/partial', 'conflicting'],
]);

it('writes the recorded inventory json without reshaping package facts', function (string $fixture): void {
    $body = json_decode(gateway_fixture($fixture)['body'], true, flags: JSON_THROW_ON_ERROR);
    $mock = MockClient::global(gateway_fixture_mock($fixture));

    expect(Artisan::call('tool:scan', ['--node' => '2', '--json' => true]))->toBe(0);
    expect(trim(Artisan::output()))
        ->toBe(json_encode([...$body['data'], 'request_id' => $body['meta']['request_id']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->and($mock->getLastPendingRequest()?->body())
        ->toBeNull();
})->with([
    'discovered' => ['tools/tool-scan/discovered'],
    'empty' => ['tools/tool-scan/empty'],
    'linux' => ['tools/tool-scan/linux'],
    'partial' => ['tools/tool-scan/partial'],
]);

it('rejects an invalid node id before any request', function (): void {
    $mock = MockClient::global();

    expect(Artisan::call('tool:scan', ['--node' => '0', '--json' => true]))
        ->toBe(1)
        ->and(trim(Artisan::output()))
        ->toBe(json_encode(['error' => [
            'code' => 'tool.node_id_invalid',
            'message' => 'Node ID must be a positive integer.',
            'request_id' => null,
        ]], JSON_THROW_ON_ERROR))
        ->and($mock->getLastPendingRequest())
        ->toBeNull();
});

it('drops recorded node access details and does not scan', function (): void {
    MockClient::global(gateway_fixture_mock('tools/tool-scan/access-required'));

    expect(Artisan::call('tool:scan', ['--node' => '2', '--json' => true]))->toBe(1);
    $output = Artisan::output();

    expect(trim($output))
        ->toBe(json_encode(['error' => [
            'code' => 'node_access.required',
            'message' => 'Node access is required.',
            'request_id' => null,
        ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->and($output)
        ->not->toContain('consumer_node')
        ->not->toContain('serving_node')
        ->not->toContain('scan-consumer')
        ->not->toContain('scan-target');
});

it('renders recorded inactive and unmanaged scan refusals with the scan step and no tool id', function (string $fixture, string $code, string $message): void {
    MockClient::global(gateway_fixture_mock($fixture));

    expect(Artisan::call('tool:scan', ['--node' => '2', '--json' => true]))->toBe(1);
    expect(trim(Artisan::output()))
        ->toBe(json_encode(['error' => [
            'code' => $code,
            'message' => $message,
            'details' => [
                'step' => 'scan',
                'outcome' => 'manager_failed',
            ],
            'request_id' => null,
        ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->not->toContain('"id"');
})->with([
    'inactive' => [
        'tools/tool-scan/node-inactive',
        'tool.node_inactive',
        'Tools can be scanned only on an active node.',
    ],
    'unmanaged' => [
        'tools/tool-scan/node-unmanaged',
        'tool.node_unmanaged',
        'Tools can be scanned only on a Gateway-managed node.',
    ],
]);
