<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Support\Console\ConsoleInterrupted;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\AppInstances\ListAppInstancesRequest;
use Orbit\Sdk\Requests\AppInstances\ResolveAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\ResolveDirectoryInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\ScanInstanceDependenciesRequest;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

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
function scan_cli_inventory(string $state = 'present', int $instanceId = 17): array
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

    return ['data' => ['instance_id' => $instanceId, 'succeeded' => ! $failed, 'composer' => ['ecosystem' => 'composer', ...$result], 'javascript' => $js], 'meta' => ['request_id' => scan_cli_id()]];
}

function fleet_cli_list_id(): string
{
    return '22222222-2222-4222-8222-222222222222';
}

function fleet_cli_listing_json(string $dataJson): string
{
    return '{"data":'.$dataJson.',"meta":{"request_id":"'.fleet_cli_list_id().'"}}';
}

function fleet_cli_listing_without_data(): string
{
    return '{"meta":{"request_id":"'.fleet_cli_list_id().'"}}';
}

function fleet_cli_listed_instance_json(int $id, string $name, string $environment, string $domain): string
{
    return json_encode(fleet_cli_listed_instance($id, $name, $environment, $domain), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/** @return array<string, mixed> */
function fleet_cli_listed_instance(int $id, string $name, string $environment, string $domain): array
{
    $payload = instance_payload();
    $payload['id'] = $id;
    $payload['name'] = $name;
    $payload['environment'] = $environment;
    $payload['domain'] = $domain;
    $payload['url'] = 'https://'.$domain;
    $payload['route']['domain'] = $domain;
    $payload['route']['target']['app_instance_id'] = $id;
    $payload['route']['targets'][0]['app_instance_id'] = $id;

    return $payload;
}

/** @return list<array<string, mixed>> */
function fleet_cli_targets(): array
{
    return [
        fleet_cli_listed_instance(19, 'later', 'development', 'later.example.test'),
        fleet_cli_listed_instance(18, 'middle', 'development', 'middle.example.test'),
        fleet_cli_listed_instance(17, 'first', 'production', 'first.example.test'),
    ];
}

function fleet_cli_scan_id(PendingRequest $pending): int
{
    preg_match('#/instances/(\d+)/dependencies/scan#', $pending->getRequest()->resolveEndpoint(), $matches);

    return (int) $matches[1];
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

describe('fleet dependency scan', function (): void {
    it('rejects --all with --app before HTTP', function (): void {
        $mock = MockClient::global();
        expect(Artisan::call('instance:dependencies:scan', ['--all' => true, '--app' => 'fixture.example.test', '--json' => true]))->toBe(1)
            ->and(json_decode(Artisan::output(), true)['error']['code'])->toBe('dependencies.target_conflict')
            ->and($mock->getLastPendingRequest())->toBeNull();
    });

    it('succeeds for an empty authorized fleet without scanning', function (): void {
        $mock = MockClient::global([
            ListAppInstancesRequest::class => MockResponse::make(['data' => [], 'meta' => ['request_id' => fleet_cli_list_id()]]),
            ScanInstanceDependenciesRequest::class => static function (): never {
                throw new RuntimeException('scan should not run');
            },
            ResolveDirectoryInstanceRequest::class => static function (): never {
                throw new RuntimeException('directory selector should not run');
            },
        ]);
        expect(Artisan::call('instance:dependencies:scan', ['--all' => true, '--json' => true, '--no-interaction' => true]))->toBe(0);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($json)->toBe([
            'succeeded' => true,
            'summary' => ['attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0],
            'instances' => [],
            'request_id' => fleet_cli_list_id(),
        ])->and($mock->getRecordedResponses())->toHaveCount(1)
            ->and($mock->getLastPendingRequest()?->getRequest())->toBeInstanceOf(ListAppInstancesRequest::class)
            ->and(Artisan::output())->not->toContain("\e", 'Choose');
    });

    it('stops after listing failure without scanning', function (): void {
        $mock = MockClient::global([
            ListAppInstancesRequest::class => MockResponse::make([
                'error' => ['code' => 'node_access.required', 'message' => 'Access required.', 'details' => []],
            ], 403, ['X-Orbit-Request-Id' => fleet_cli_list_id()]),
            ScanInstanceDependenciesRequest::class => static function (): never {
                throw new RuntimeException('scan should not run');
            },
        ]);
        expect(Artisan::call('instance:dependencies:scan', ['--all' => true, '--json' => true]))->toBe(1);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($json['error']['code'])->toBe('node_access.required')
            ->and($json['error']['request_id'])->toBe(fleet_cli_list_id())
            ->and($json)->not->toHaveKey('instances')
            ->and($mock->getRecordedResponses())->toHaveCount(1);
    });

    it('treats a raw empty JSON array as a successful empty fleet', function (): void {
        $mock = MockClient::global([
            ListAppInstancesRequest::class => MockResponse::make(fleet_cli_listing_json('[]')),
            ScanInstanceDependenciesRequest::class => static function (): never {
                throw new RuntimeException('scan should not run');
            },
        ]);
        expect(Artisan::call('instance:dependencies:scan', ['--all' => true, '--json' => true, '--no-interaction' => true]))->toBe(0);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($json['succeeded'])->toBeTrue()
            ->and($json['summary'])->toBe(['attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0])
            ->and($json['instances'])->toBe([])
            ->and($mock->getRecordedResponses())->toHaveCount(1);
    });

    it('rejects malformed listings before scanning', function (string $body): void {
        $mock = MockClient::global([
            ListAppInstancesRequest::class => MockResponse::make($body),
            ScanInstanceDependenciesRequest::class => static function (): never {
                throw new RuntimeException('scan should not run');
            },
        ]);
        expect(Artisan::call('instance:dependencies:scan', ['--all' => true, '--json' => true]))->toBe(1);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($json['error']['code'])->toBe('gateway.request_failed')
            ->and($json['error']['message'])->toBe('Gateway response contains invalid collection data.')
            ->and($json['error']['request_id'])->toBe(fleet_cli_list_id())
            ->and($json)->not->toHaveKey('instances')
            ->and($json)->not->toHaveKey('summary')
            ->and($json)->not->toHaveKey('succeeded')
            ->and(Artisan::output())->not->toContain('invalid-listing-sentinel')
            ->and($mock->getRecordedResponses())->toHaveCount(1)
            ->and($mock->getLastPendingRequest()?->getRequest())->toBeInstanceOf(ListAppInstancesRequest::class);
    })->with([
        'missing data' => [fleet_cli_listing_without_data()],
        'scalar data' => [fleet_cli_listing_json('"invalid-listing-sentinel"')],
        'empty object data' => [fleet_cli_listing_json('{}')],
        'numeric-key data object' => [fleet_cli_listing_json('{"0":'.fleet_cli_listed_instance_json(15, 'partial', 'development', 'partial.example.test').'}')],
        'array rows' => [fleet_cli_listing_json('[[]]')],
        'empty object rows' => [fleet_cli_listing_json('[{}]')],
        'missing identity fields' => [fleet_cli_listing_json('[{"id":15}]')],
        'invalid identity fields' => [fleet_cli_listing_json('[{"id":0,"app_id":1,"node_id":2,"name":"first","environment":"production","domain":null}]')],
        'null and scalar rows' => [fleet_cli_listing_json('[null,42]')],
        'malformed row after valid row' => [fleet_cli_listing_json('['.fleet_cli_listed_instance_json(17, 'first', 'production', 'first.example.test').',{}]')],
    ]);

    it('scans captured targets in list order and continues after one failure', function (): void {
        $scanned = [];
        $mock = MockClient::global([
            ListAppInstancesRequest::class => MockResponse::make([
                'data' => fleet_cli_targets(),
                'meta' => ['request_id' => fleet_cli_list_id()],
            ]),
            ScanInstanceDependenciesRequest::class => function (PendingRequest $pending) use (&$scanned): MockResponse {
                $id = fleet_cli_scan_id($pending);
                $scanned[] = $id;
                if ($id === 18) {
                    return MockResponse::make([
                        'error' => ['code' => 'dependencies.instance_unavailable', 'message' => 'The instance is unavailable.', 'details' => []],
                    ], 409, ['X-Orbit-Request-Id' => scan_cli_id()]);
                }

                return MockResponse::make(scan_cli_inventory(instanceId: $id));
            },
        ]);
        expect(Artisan::call('instance:dependencies:scan', ['--all' => true, '--json' => true, '--no-interaction' => true]))->toBe(1);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($scanned)->toBe([19, 18, 17])
            ->and($json['succeeded'])->toBeFalse()
            ->and($json['summary'])->toBe(['attempted' => 3, 'succeeded' => 2, 'failed' => 1, 'skipped' => 0])
            ->and($json['request_id'])->toBe(fleet_cli_list_id())
            ->and(array_column($json['instances'], 'instance_id'))->toBe([19, 18, 17])
            ->and($json['instances'][0]['succeeded'])->toBeTrue()
            ->and($json['instances'][0]['name'])->toBe('later')
            ->and($json['instances'][1]['succeeded'])->toBeFalse()
            ->and($json['instances'][1]['error']['code'])->toBe('dependencies.instance_unavailable')
            ->and($json['instances'][2]['succeeded'])->toBeTrue()
            ->and($json['instances'][2]['javascript']['snapshot']['graph']['resolutions'])->toHaveCount(2)
            ->and(Artisan::output())->not->toContain("\e", 'Working', 'Choose')
            ->and($mock->getRecordedResponses())->toHaveCount(4);
    });

    it('records a timed-out target and still scans later captured instances', function (): void {
        $scanned = [];
        MockClient::global([
            ListAppInstancesRequest::class => MockResponse::make([
                'data' => fleet_cli_targets(),
                'meta' => ['request_id' => fleet_cli_list_id()],
            ]),
            ScanInstanceDependenciesRequest::class => function (PendingRequest $pending) use (&$scanned): MockResponse {
                $id = fleet_cli_scan_id($pending);
                $scanned[] = $id;
                if ($id === 18) {
                    throw new FatalRequestException(new RuntimeException('cURL error 28: timeout'), $pending);
                }

                return MockResponse::make(scan_cli_inventory(instanceId: $id));
            },
        ]);
        expect(Artisan::call('instance:dependencies:scan', ['--all' => true, '--json' => true]))->toBe(1);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($scanned)->toBe([19, 18, 17])
            ->and($json['summary'])->toBe(['attempted' => 3, 'succeeded' => 2, 'failed' => 1, 'skipped' => 0])
            ->and($json['instances'][1]['error']['code'])->toBe('gateway.unreachable')
            ->and($json['instances'][2]['instance_id'])->toBe(17)
            ->and($json['instances'][2]['succeeded'])->toBeTrue()
            ->and($json)->not->toHaveKey('error');
    });

    it('does not mark unattempted targets complete after cancellation', function (): void {
        $scanned = [];
        MockClient::global([
            ListAppInstancesRequest::class => MockResponse::make([
                'data' => fleet_cli_targets(),
                'meta' => ['request_id' => fleet_cli_list_id()],
            ]),
            ScanInstanceDependenciesRequest::class => function (PendingRequest $pending) use (&$scanned): MockResponse {
                $id = fleet_cli_scan_id($pending);
                $scanned[] = $id;
                if ($id === 18) {
                    throw new ConsoleInterrupted(2);
                }

                return MockResponse::make(scan_cli_inventory(instanceId: $id));
            },
        ]);
        expect(Artisan::call('instance:dependencies:scan', ['--all' => true, '--json' => true]))->toBe(1);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($scanned)->toBe([19, 18])
            ->and($json['succeeded'])->toBeFalse()
            ->and($json['summary'])->toBe(['attempted' => 2, 'succeeded' => 1, 'failed' => 1, 'skipped' => 1])
            ->and(array_column($json['instances'], 'instance_id'))->toBe([19, 18])
            ->and($json['instances'][1]['error']['code'])->toBe('input.cancelled');
    });

    it('shows later targets and a nonzero summary after a failed instance', function (): void {
        MockClient::global([
            ListAppInstancesRequest::class => MockResponse::make([
                'data' => fleet_cli_targets(),
                'meta' => ['request_id' => fleet_cli_list_id()],
            ]),
            ScanInstanceDependenciesRequest::class => function (PendingRequest $pending): MockResponse {
                $id = fleet_cli_scan_id($pending);
                if ($id === 18) {
                    return MockResponse::make([
                        'error' => ['code' => 'dependencies.instance_unavailable', 'message' => 'The instance is unavailable.', 'details' => []],
                    ], 409, ['X-Orbit-Request-Id' => scan_cli_id()]);
                }

                return MockResponse::make(scan_cli_inventory(instanceId: $id));
            },
        ]);
        expect(Artisan::call('instance:dependencies:scan', ['--all' => true, '--no-interaction' => true]))->toBe(1);
        $text = Artisan::output();
        expect($text)->toContain('Instance: #19', 'Instance: #18', 'Instance: #17', 'later.example.test', 'first.example.test', 'dependencies.instance_unavailable', 'Summary: 3 attempted, 2 complete, 1 failed.', fleet_cli_list_id())
            ->not->toContain("\e", 'Choose', 'Confirm');
    });
});
