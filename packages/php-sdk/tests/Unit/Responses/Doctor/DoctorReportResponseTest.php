<?php

declare(strict_types=1);

use Orbit\Sdk\Responses\Doctor\DoctorFamilyResponse;
use Orbit\Sdk\Responses\Doctor\DoctorIssueResponse;
use Orbit\Sdk\Responses\Doctor\DoctorNodeResponse;
use Orbit\Sdk\Responses\Doctor\DoctorReportResponse;

/** @mago-expect analysis:mixed-array-assignment The test mutates an untyped Gateway report fixture. */
it('preserves the exact report, received order, aggregates, and scalar variants', function (): void {
    $data = doctor_report_data();
    $data['nodes'][0]['families'] = [
        doctor_family_data(family: 'firewall', status: 'unverifiable', checked: 9, issues: [doctor_issue_data(
            code: 'firewall.rule_missing',
            kind: 'unverifiable',
            resourceType: 'firewall',
            resourceId: 71,
            resourceName: null,
            expected: true,
            observed: false,
        )]),
        doctor_family_data(family: 'node', status: 'healthy', checked: 4, issues: [
            doctor_issue_data(
                code: 'node.platform_mismatch',
                resourceId: 'external',
                resourceName: 'primary',
                observed: null,
            ),
            doctor_issue_data(
                code: 'node.architecture_mismatch',
                resourceId: null,
                resourceName: '',
                expected: null,
                observed: 'arm64',
            ),
        ]),
        doctor_family_data(family: 'workspace', status: 'drift', checked: 2, issues: []),
    ];
    $data['nodes'][] = [
        'node_id' => 3,
        'node_name' => 'earlier-node-id',
        'healthy' => true,
        'families' => [],
    ];
    $data['summary'] = ['nodes' => 99, 'families' => 88, 'checks' => 77, 'drift' => 66, 'unverifiable' => 55];
    $data['unknown'] = 'drop';
    $data['nodes'][0]['unknown'] = 'drop';

    $response = DoctorReportResponse::fromGatewayData($data, '0198e15c-bf97-7c23-8f1f-61b8fe67a844');
    $expected = $data;
    unset($expected['unknown'], $expected['nodes'][0]['unknown']);
    $expected['request_id'] = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';

    expect($response->toArray())
        ->toBe($expected)
        ->and(array_column($response->toArray()['nodes'], 'node_id'))
        ->toBe([7, 3]);
});

/** @mago-expect analysis:mixed-array-assignment The test mutates an untyped Gateway report fixture. */
it('accepts every family and all statuses', function (): void {
    $families = ['node', 'role', 'app', 'instance', 'workspace', 'tool', 'process', 'firewall'];
    $statuses = ['healthy', 'drift', 'unverifiable'];
    $data = doctor_report_data();
    $data['nodes'][0]['families'] = array_map(
        static fn (string $family, int $index): array => doctor_family_data($family, $statuses[$index % 3], $index, []),
        $families,
        array_keys($families),
    );

    $response = DoctorReportResponse::fromGatewayData($data, '');
    $actual = $response->toArray()['nodes'][0]['families'];

    expect(array_column($actual, 'family'))
        ->toBe($families)
        ->and(array_column($actual, 'status'))
        ->toBe(['healthy', 'drift', 'unverifiable', 'healthy', 'drift', 'unverifiable', 'healthy', 'drift']);
});

/** @mago-expect analysis:mixed-array-assignment The test injects malformed values into an untyped wire fixture. */
it('drops malformed nested members without fallback DTOs', function (string $level, mixed $value): void {
    $data = doctor_report_data();
    if ($level === 'node') {
        $data['nodes'][] = $value;
    }
    if ($level === 'family') {
        $data['nodes'][0]['families'][] = $value;
    }
    if ($level === 'issue') {
        $data['nodes'][0]['families'][0]['issues'][] = $value;
    }

    $response = DoctorReportResponse::fromGatewayData($data, '');
    expect($response->nodes)
        ->toHaveCount(1)
        ->and($response->nodes[0]->families)
        ->toHaveCount(1)
        ->and($response->nodes[0]->families[0]->issues)
        ->toHaveCount(1);
})->with([
    'node' => ['node', 'bad'],
    'family' => ['family', 4],
    'issue' => ['issue', false],
]);

/** @mago-expect analysis:mixed-array-assignment The test injects malformed values into an untyped wire fixture. */
it('drops the owning nested member for malformed collections', function (string $level, mixed $value): void {
    $data = doctor_report_data();
    if ($level === 'families') {
        $data['nodes'][0]['families'] = $value;
    }
    if ($level === 'issues') {
        $data['nodes'][0]['families'][0]['issues'] = $value;
    }

    $response = DoctorReportResponse::fromGatewayData($data, '');
    expect($level === 'families' ? $response->nodes : $response->nodes[0]->families)->toBeEmpty();
})->with([
    'families scalar' => ['families', 'node'],
    'families map' => ['families', ['selected' => []]],
    'issues scalar' => ['issues', 'issue'],
    'issues map' => ['issues', ['selected' => []]],
]);

it('fails closed for invalid required top-level data', function (Closure $mutate): void {
    $data = doctor_report_data();
    $mutate($data);

    expect(fn (): DoctorReportResponse => DoctorReportResponse::fromGatewayData($data, ''))
        ->toThrow(InvalidArgumentException::class, 'Invalid Doctor report response.');
})->with([
    'healthy' => [static function (array &$data): void {
        $data['healthy'] = 1;
    }],
    'nodes scalar' => [static function (array &$data): void {
        $data['nodes'] = 'node';
    }],
    'nodes map' => [static function (array &$data): void {
        $data['nodes'] = ['selected' => []];
    }],
    'summary scalar' => [static function (array &$data): void {
        $data['summary'] = 'summary';
    }],
    'summary missing' => [static function (array &$data): void {
        unset($data['summary']['checks']);
    }],
    'summary negative' => [static function (array &$data): void {
        $data['summary']['drift'] = -1;
    }],
    'summary string' => [static function (array &$data): void {
        $data['summary']['nodes'] = '1';
    }],
]);

/** @mago-expect analysis:mixed-array-assignment The test injects malformed values into an untyped wire fixture. */
it('drops invalid nested required values', function (string $level, string $key, mixed $value): void {
    $data = doctor_report_data();
    if ($level === 'node') {
        $data['nodes'][0][$key] = $value;
    }
    if ($level === 'family') {
        $data['nodes'][0]['families'][0][$key] = $value;
    }
    if ($level === 'issue') {
        $data['nodes'][0]['families'][0]['issues'][0][$key] = $value;
    }

    $response = DoctorReportResponse::fromGatewayData($data, '');
    $collection = match ($level) {
        'node' => $response->nodes,
        'family' => $response->nodes[0]->families,
        default => $response->nodes[0]->families[0]->issues,
    };
    expect($collection)->toBeEmpty();
})->with([
    'node id' => ['node', 'node_id', 0],
    'node name' => ['node', 'node_name', ''],
    'node healthy' => ['node', 'healthy', 1],
    'family token' => ['family', 'family', 'database'],
    'family status' => ['family', 'status', 'unknown'],
    'checked' => ['family', 'checked', -1],
    'code' => ['issue', 'code', ''],
    'kind' => ['issue', 'kind', 'healthy'],
    'resource type' => ['issue', 'resource_type', 'database'],
    'resource id' => ['issue', 'resource_id', []],
    'resource name' => ['issue', 'resource_name', 9],
    'summary' => ['issue', 'summary', []],
    'expected' => ['issue', 'expected', 9],
    'observed' => ['issue', 'observed', []],
]);

it('drops an issue when a required nullable key is missing', function (string $key): void {
    $data = doctor_report_data();
    unset($data['nodes'][0]['families'][0]['issues'][0][$key]);

    $response = DoctorReportResponse::fromGatewayData($data, '');

    expect($response->nodes[0]->families[0]->issues)->toBeEmpty();
})->with([
    'resource ID' => ['resource_id'],
    'resource name' => ['resource_name'],
    'expected' => ['expected'],
    'observed' => ['observed'],
]);

/** @mago-expect analysis:mixed-array-assignment The test mutates an untyped Gateway report fixture. */
it('redacts every string before enforcing its final bound', function (string $level, string $key): void {
    $credential = 'doctor-bounds-secret';
    $value = 'token='.$credential.str_repeat('x', times: 250);
    $data = doctor_report_data();
    if ($level === 'node') {
        $data['nodes'][0][$key] = $value;
    }
    if ($level === 'issue') {
        $data['nodes'][0]['families'][0]['issues'][0][$key] = $value;
    }

    $response = DoctorReportResponse::fromGatewayData($data, '');
    expect(json_encode($response->toArray(), JSON_THROW_ON_ERROR))
        ->not
        ->toContain($credential)
        ->and($response->nodes)
        ->toHaveCount(1)
        ->and($response->nodes[0]->families[0]->issues)
        ->toHaveCount(1);
})->with([
    'node name' => ['node', 'node_name'],
    'code' => ['issue', 'code'],
    'resource id' => ['issue', 'resource_id'],
    'resource name' => ['issue', 'resource_name'],
    'summary' => ['issue', 'summary'],
    'expected' => ['issue', 'expected'],
    'observed' => ['issue', 'observed'],
]);

/** @mago-expect analysis:mixed-array-assignment The test mutates an untyped Gateway report fixture. */
it('drops members whose redacted strings remain over the bound', function (string $level, string $key): void {
    $data = doctor_report_data();
    if ($level === 'node') {
        $data['nodes'][0][$key] = str_repeat('x', times: 256);
    }
    if ($level === 'issue') {
        $data['nodes'][0]['families'][0]['issues'][0][$key] = str_repeat('x', times: 256);
    }
    $response = DoctorReportResponse::fromGatewayData($data, '');
    expect($level === 'node' ? $response->nodes : $response->nodes[0]->families[0]->issues)->toBeEmpty();
})->with([
    'node name' => ['node', 'node_name'],
    'code' => ['issue', 'code'],
    'resource id' => ['issue', 'resource_id'],
    'resource name' => ['issue', 'resource_name'],
    'summary' => ['issue', 'summary'],
    'expected' => ['issue', 'expected'],
    'observed' => ['issue', 'observed'],
]);

it('normalizes invalid request IDs and keeps constructors private', function (): void {
    $credential = 'doctor-request-id-secret';
    $response = DoctorReportResponse::fromGatewayData(doctor_report_data(), "token={$credential}");

    expect($response->requestId)
        ->toBeEmpty()
        ->and(json_encode($response->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain($credential);
    foreach ([
        DoctorReportResponse::class,
        DoctorNodeResponse::class,
        DoctorFamilyResponse::class,
        DoctorIssueResponse::class,
    ] as $class) {
        expect(new ReflectionMethod($class, '__construct'))->isPrivate()->toBeTrue();
    }
});

/** @return array{healthy:bool,nodes:list<array{node_id:int,node_name:string,healthy:bool,families:list<array{family:string,status:string,checked:int,issues:list<array{code:string,kind:string,resource_type:string,resource_id:int|string|null,resource_name:string|null,summary:string,expected:bool|string|null,observed:bool|string|null}>}>}>,summary:array{nodes:int,families:int,checks:int,drift:int,unverifiable:int}} */
function doctor_report_data(): array
{
    return [
        'healthy' => false,
        'nodes' => [[
            'node_id' => 7,
            'node_name' => 'edge-node',
            'healthy' => false,
            'families' => [doctor_family_data()],
        ]],
        'summary' => ['nodes' => 1, 'families' => 1, 'checks' => 1, 'drift' => 1, 'unverifiable' => 0],
    ];
}

/**
 * @param list<array{code:string,kind:string,resource_type:string,resource_id:int|string|null,resource_name:string|null,summary:string,expected:bool|string|null,observed:bool|string|null}>|null $issues
 * @return array{family:string,status:string,checked:int,issues:list<array{code:string,kind:string,resource_type:string,resource_id:int|string|null,resource_name:string|null,summary:string,expected:bool|string|null,observed:bool|string|null}>}
 */
function doctor_family_data(
    string $family = 'node',
    string $status = 'drift',
    int $checked = 1,
    ?array $issues = null,
): array {
    return [
        'family' => $family,
        'status' => $status,
        'checked' => $checked,
        'issues' => $issues ?? [doctor_issue_data(resourceType: $family)],
    ];
}

/**
 * @mago-expect lint:excessive-parameter-list The helper mirrors the exact issue wire shape.
 * @return array{code:string,kind:string,resource_type:string,resource_id:int|string|null,resource_name:string|null,summary:string,expected:bool|string|null,observed:bool|string|null}
 */
function doctor_issue_data(
    string $code = 'node.platform_mismatch',
    string $kind = 'drift',
    string $resourceType = 'node',
    int|string|null $resourceId = 7,
    ?string $resourceName = 'edge-node',
    bool|string|null $expected = 'linux',
    bool|string|null $observed = 'darwin',
): array {
    return [
        'code' => $code,
        'kind' => $kind,
        'resource_type' => $resourceType,
        'resource_id' => $resourceId,
        'resource_name' => $resourceName,
        'summary' => 'Detected drift.',
        'expected' => $expected,
        'observed' => $observed,
    ];
}
