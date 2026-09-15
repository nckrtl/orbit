<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\AppInstances\ResolveAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\ResolveDirectoryInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\ScanInstanceDependenciesRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-dependency-cli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://gateway.test'));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

function scan_cli_id(): string
{
    return '11111111-1111-4111-8111-111111111111';
}

/** @return array<string, mixed> */
function scan_cli_inventory(string $state = 'present'): array
{
    $snapshot = ['observed_at' => '2026-09-15T19:28:20+00:00', 'source' => [
        'project_root' => '/home/orbit/fixture', 'reference' => null, 'file_hashes' => [], 'format' => null,
    ], 'graph' => ['resolutions' => [], 'requirements' => []]];
    $result = ['state' => 'present', 'succeeded' => true, 'attempted_at' => '2026-09-15T19:28:20+00:00', 'error_code' => null, 'snapshot' => $snapshot];
    $js = ['ecosystem' => 'npm', ...$result];
    $js['snapshot']['graph'] = ['resolutions' => [
        ['id' => 'widget', 'ecosystem' => 'npm', 'name' => 'widget', 'version' => '1.0.0', 'regular' => true, 'development' => true, 'source_reference' => null, 'integrity' => null],
        ['id' => 'nested/widget', 'ecosystem' => 'npm', 'name' => 'widget', 'version' => '2.0.0', 'regular' => false, 'development' => true, 'source_reference' => null, 'integrity' => null],
    ], 'requirements' => [
        ['from' => null, 'to' => 'widget', 'name' => 'widget', 'constraint' => '^1', 'kind' => 'dependency', 'scope' => 'regular', 'optional' => false],
    ]];
    if ($state === 'absent' || $state === 'stale-absent') {
        $js['snapshot']['graph'] = null;
    }
    $failed = in_array($state, ['stale', 'stale-absent', 'unknown'], true);
    $js['state'] = $state === 'stale-absent' ? 'stale' : $state;
    if ($failed) {
        $js['succeeded'] = false;
        $js['attempted_at'] = '2026-09-15T20:28:20+00:00';
        $js['error_code'] = 'dependencies.unreadable_source';
    }
    if ($state === 'unknown') {
        $js['snapshot'] = null;
    }

    return ['data' => ['instance_id' => 17, 'succeeded' => ! $failed, 'composer' => ['ecosystem' => 'composer', ...$result], 'javascript' => $js], 'meta' => ['request_id' => scan_cli_id()]];
}

function scan_cli_mock(string $state = 'present', bool $domain = true): MockClient
{
    $target = ['instance_id' => 17, 'app_id' => 3, 'node_id' => 9, 'environment' => 'development'];
    if ($domain) {
        $target = ['domain' => 'fixture.example.test', ...$target];
    }

    return MockClient::global([
        ($domain ? ResolveAppInstanceRequest::class : ResolveDirectoryInstanceRequest::class) => MockResponse::make(['data' => $target, 'meta' => ['request_id' => scan_cli_id()]]),
        ScanInstanceDependenciesRequest::class => MockResponse::make(scan_cli_inventory($state)),
    ]);
}

describe('single instance dependency scan', function (): void {
    it('preserves the complete typed result in one plain JSON document', function (string $state): void {
        $mock = scan_cli_mock($state);
        $code = Artisan::call('instance:dependencies:scan', ['--app' => 'fixture.example.test', '--json' => true, '--no-interaction' => true]);
        $expected = scan_cli_inventory($state);
        expect($code)->toBe($expected['data']['succeeded'] ? 0 : 1)
            ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([...$expected['data'], 'request_id' => scan_cli_id()])
            ->and(Artisan::output())->not->toContain("\e", 'Working', 'Resolving')
            ->and($mock->getRecordedResponses())->toHaveCount(2)
            ->and($mock->getLastPendingRequest()?->getRequest())->toBeInstanceOf(ScanInstanceDependenciesRequest::class)
            ->and($mock->getLastPendingRequest()?->getRequest()->resolveEndpoint())->toBe('/api/v1/instances/17/dependencies/scan')
            ->and((string) $mock->getLastPendingRequest()?->createPsrRequest()->getBody())->toBe('{}');
    })->with(['present', 'absent', 'stale', 'stale-absent', 'unknown']);

    it('uses the directory selector when the domain is omitted', function (): void {
        $mock = scan_cli_mock(domain: false);
        expect(Artisan::call('instance:dependencies:scan', ['--json' => true]))->toBe(0);
        $request = $mock->getRecordedResponses()[0]->getPendingRequest();
        expect($request->getRequest())->toBeInstanceOf(ResolveDirectoryInstanceRequest::class)
            ->and($request->query()->all())->toBe(['directory' => realpath(getcwd())]);
    });

    it('shows truthful counts freshness timestamps and terminal outcomes', function (string $state): void {
        scan_cli_mock($state);
        $expected = scan_cli_inventory($state);
        expect(Artisan::call('instance:dependencies:scan', ['--app' => 'fixture.example.test', '--no-interaction' => true]))->toBe($expected['data']['succeeded'] ? 0 : 1);
        $text = Artisan::output();
        expect($text)->toContain('Instance: #17', 'Composer', 'JavaScript', 'Resolutions', 'Requirements', 'Observed', 'Attempted', scan_cli_id())
            ->not->toContain("\e", 'Choose', 'Confirm');
        if ($expected['data']['succeeded']) {
            expect($text)->toContain('Dependency scan complete.');
        } else {
            expect($text)->toContain('Dependency scan failed.', 'dependencies.unreadable_source')->not->toContain('Dependency scan complete.');
        }
        expect($text)->toMatch('/Resolutions\s+'.($state === 'unknown' ? '—' : (str_contains($state, 'absent') ? '0' : '2')).'/u');
        if ($state === 'stale') {
            expect($text)->toContain('stale', '2026-09-15T19:28:20+00:00', '2026-09-15T20:28:20+00:00');
        }
    })->with(['present', 'absent', 'stale', 'stale-absent', 'unknown']);

    it('never scans or falls back after target refusal', function (string $error): void {
        $mock = MockClient::global([ResolveAppInstanceRequest::class => MockResponse::make(['error' => ['code' => $error, 'message' => 'Target refused.', 'details' => []]], 409, ['X-Orbit-Request-Id' => scan_cli_id()])]);
        expect(Artisan::call('instance:dependencies:scan', ['--app' => 'fixture.example.test', '--json' => true]))->toBe(1);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($json['error']['code'])->toBe($error)->and($json['error']['request_id'])->toBe(scan_cli_id())
            ->and($mock->getRecordedResponses())->toHaveCount(1);
    })->with(['dependencies.target_not_found', 'dependencies.target_ambiguous', 'node_access.required']);

    it('rejects an empty explicit domain without directory fallback', function (): void {
        $mock = MockClient::global();
        expect(Artisan::call('instance:dependencies:scan', ['--app' => '', '--json' => true]))->toBe(1)
            ->and(json_decode(Artisan::output(), true)['error']['code'])->toBe('dependencies.domain_invalid')
            ->and($mock->getLastPendingRequest())->toBeNull();
    });

    it('rejects malformed scan output without claiming success', function (): void {
        $mock = scan_cli_mock();
        $mock->addResponse(MockResponse::make(['data' => []]), ScanInstanceDependenciesRequest::class);
        expect(Artisan::call('instance:dependencies:scan', ['--app' => 'fixture.example.test', '--json' => true]))->toBe(1)
            ->and(json_decode(Artisan::output(), true)['error']['message'])->toContain('invalid dependency inventory');
    });
});
