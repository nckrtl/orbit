<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Doctor\RunDoctorRequest;
use Orbit\Sdk\Responses\Doctor\DoctorReportResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Command\Command;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-doctor-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/tmp/ca.pem',
    ));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('exposes the doctor command', function (): void {
    expect(app(Kernel::class)->all())->toHaveKey('doctor');
});

it('rejects invalid node options through the exact json envelope before HTTP', function (mixed $node): void {
    $mock = MockClient::global();
    $expected = json_encode([
        'error' => [
            'code' => 'doctor.node_id_invalid',
            'message' => 'Node ID must be a positive integer.',
            'request_id' => null,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $exitCode = Artisan::call('doctor', ['--node' => $node, '--json' => true]);

    expect($exitCode)
        ->toBe(Command::FAILURE)
        ->and(trim(Artisan::output()))
        ->toBe($expected)
        ->and($mock->getLastPendingRequest())
        ->toBeNull();
})->with([
    'zero' => [0],
    'non-integer' => ['edge-node'],
    'empty' => [''],
]);

it('posts the exact empty request to the doctor endpoint', function (): void {
    $mock = doctor_cli_mock(doctor_cli_report(healthy: true));

    expect(Artisan::call('doctor', ['--json' => true]))->toBe(Command::SUCCESS);

    $request = $mock->getLastPendingRequest();

    expect($request?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/doctor')
        ->and($request?->getMethod())
        ->toBe(Method::POST)
        ->and($request?->body()->all())
        ->toBe('{}');
});

it('forwards the node and repeated families in received order', function (): void {
    $mock = doctor_cli_mock(doctor_cli_report(healthy: true));

    expect(Artisan::call('doctor', [
        '--node' => '7',
        '--family' => ['instance', 'workspace'],
        '--json' => true,
    ]))
        ->toBe(Command::SUCCESS);

    expect($mock->getLastPendingRequest()?->body()->all())
        ->toBe('{"node_id":7,"families":["instance","workspace"]}');
});

it('renders rich unhealthy reports in received order', function (): void {
    $requestId = doctor_cli_request_id();
    $data = doctor_cli_report(
        healthy: false,
        nodes: [
            doctor_cli_node(name: 'alpha', families: [
                doctor_cli_family(family: 'node', status: 'healthy', checked: 2, issues: []),
                doctor_cli_family(family: 'workspace', status: 'drift', checked: 3, issues: [
                    doctor_cli_issue(code: 'workspace.branch_mismatch', summary: 'Branch differs.'),
                    doctor_cli_issue(code: 'workspace.php_mismatch', summary: 'PHP version differs.'),
                ]),
            ]),
            doctor_cli_node(
                'beta',
                [
                    doctor_cli_family(family: 'firewall', status: 'unverifiable', checked: 1, issues: [
                        doctor_cli_issue(
                            code: 'firewall.status_unavailable',
                            summary: 'Firewall status is unavailable.',
                            kind: 'unverifiable',
                            resourceType: 'firewall',
                        ),
                    ]),
                ],
                nodeId: 11,
            ),
        ],
        summary: ['nodes' => 2, 'families' => 3, 'checks' => 6, 'drift' => 2, 'unverifiable' => 1],
    );
    doctor_cli_mock($data, $requestId);

    $this
        ->artisan('doctor')
        ->expectsTable(
            ['Node', 'Family', 'Status', 'Checked', 'Finding'],
            [
                ['alpha', 'node', 'healthy', 2, '—'],
                ['alpha', 'workspace', 'drift', 3, 'workspace.branch_mismatch: Branch differs.'],
                ['alpha', 'workspace', 'drift', 3, 'workspace.php_mismatch: PHP version differs.'],
                ['beta', 'firewall', 'unverifiable', 1, 'firewall.status_unavailable: Firewall status is unavailable.'],
            ],
        )
        ->expectsOutput('Healthy: no')
        ->expectsOutput("Request ID: {$requestId}")
        ->assertExitCode(Command::FAILURE);
});

it('renders a completed unverifiable report instead of a gateway failure', function (): void {
    $requestId = '22222222-2222-4222-8222-222222222222';
    $data = doctor_cli_report(
        healthy: false,
        nodes: [doctor_cli_node('gamma', [
            doctor_cli_family(family: 'process', status: 'unverifiable', checked: 1, issues: [
                doctor_cli_issue(
                    code: 'process.state_unavailable',
                    summary: 'Process state could not be verified.',
                    kind: 'unverifiable',
                    resourceType: 'process',
                ),
            ]),
        ])],
        summary: ['nodes' => 1, 'families' => 1, 'checks' => 1, 'drift' => 0, 'unverifiable' => 1],
    );
    doctor_cli_mock($data, $requestId);

    $this
        ->artisan('doctor')
        ->expectsTable(
            ['Node', 'Family', 'Status', 'Checked', 'Finding'],
            [[
                'gamma',
                'process',
                'unverifiable',
                1,
                'process.state_unavailable: Process state could not be verified.',
            ]],
        )
        ->expectsOutput('Healthy: no')
        ->expectsOutput("Request ID: {$requestId}")
        ->doesntExpectOutputToContain('"error"')
        ->assertExitCode(Command::FAILURE);
});

it('writes the exact one-line report json and follows its healthy state', function (string $scenario): void {
    $healthy = $scenario === 'healthy';
    $requestId = doctor_cli_request_id();
    $nodes = $healthy
        ? []
        : [doctor_cli_node('delta', [
            doctor_cli_family(family: 'app', status: 'drift', checked: 1, issues: [
                doctor_cli_issue(
                    code: 'app.repository_mismatch',
                    summary: 'Repository differs.',
                    resourceType: 'app',
                ),
            ]),
        ])];
    $summary = $healthy
        ? ['nodes' => 0, 'families' => 0, 'checks' => 0, 'drift' => 0, 'unverifiable' => 0]
        : ['nodes' => 1, 'families' => 1, 'checks' => 1, 'drift' => 1, 'unverifiable' => 0];
    $data = doctor_cli_report($healthy, $nodes, $summary);
    $expected = DoctorReportResponse::fromGatewayData($data, $requestId)->toArray();
    doctor_cli_mock($data, $requestId);

    $exitCode = Artisan::call('doctor', ['--json' => true]);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe($healthy ? Command::SUCCESS : Command::FAILURE);
    expect($output)
        ->toBe(json_encode($expected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->not->toContain("\n", 'Healthy:', 'Request ID:', 'Node  Family');
    expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe($expected);
})->with([
    'healthy success' => ['healthy'],
    'unhealthy failure' => ['unhealthy'],
]);

it('renders only SDK-redacted credential values from nested gateway data', function (): void {
    $credential = 'doctor-cli-nested-secret';
    $issue = doctor_cli_issue(
        code: "token={$credential}",
        summary: "password={$credential}",
        resourceId: "api_token={$credential}",
        resourceName: "secret={$credential}",
        expected: "Authorization: Bearer {$credential}",
        observed: "https://operator:{$credential}@example.test",
    );
    $data = doctor_cli_report(
        healthy: false,
        nodes: [doctor_cli_node("token={$credential}", [
            doctor_cli_family(family: 'node', status: 'drift', checked: 1, issues: [$issue]),
        ])],
        summary: ['nodes' => 1, 'families' => 1, 'checks' => 1, 'drift' => 1, 'unverifiable' => 0],
    );
    doctor_cli_mock($data);

    expect(Artisan::call('doctor', ['--json' => true]))->toBe(Command::FAILURE);
    expect(trim(Artisan::output()))
        ->toContain('[REDACTED]')
        ->not->toContain($credential);
});

it('renders gateway API errors through the shared exact json failure envelope', function (): void {
    $requestId = doctor_cli_request_id();
    $credential = 'doctor-gateway-error-secret';
    MockClient::global([
        RunDoctorRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'doctor.unavailable',
                    'message' => 'Doctor is unavailable.',
                    'details' => [
                        'authorization' => "Bearer {$credential}",
                        'nested' => ['api_token' => $credential],
                    ],
                ],
            ],
            503,
            ['X-Orbit-Request-Id' => $requestId],
        ),
    ]);
    $expected = json_encode([
        'error' => [
            'code' => 'doctor.unavailable',
            'message' => 'Doctor is unavailable.',
            'request_id' => $requestId,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    expect(Artisan::call('doctor', ['--json' => true]))->toBe(Command::FAILURE);
    expect(trim(Artisan::output()))
        ->toBe($expected)
        ->not->toContain('details', 'authorization', 'api_token', $credential);
});

/**
 * @param list<array{node_id:int,node_name:string,healthy:bool,families:list<array{family:string,status:string,checked:int,issues:list<array{code:string,kind:string,resource_type:string,resource_id:int|string|null,resource_name:string|null,summary:string,expected:bool|string|null,observed:bool|string|null}>}>}> $nodes
 * @param array{nodes:int,families:int,checks:int,drift:int,unverifiable:int}|null $summary
 * @return array{healthy:bool,nodes:list<array{node_id:int,node_name:string,healthy:bool,families:list<array{family:string,status:string,checked:int,issues:list<array{code:string,kind:string,resource_type:string,resource_id:int|string|null,resource_name:string|null,summary:string,expected:bool|string|null,observed:bool|string|null}>}>}>,summary:array{nodes:int,families:int,checks:int,drift:int,unverifiable:int}}
 */
function doctor_cli_report(bool $healthy, array $nodes = [], ?array $summary = null): array
{
    return [
        'healthy' => $healthy,
        'nodes' => $nodes,
        'summary' => $summary ?? ['nodes' => 0, 'families' => 0, 'checks' => 0, 'drift' => 0, 'unverifiable' => 0],
    ];
}

/**
 * @param list<array{family:string,status:string,checked:int,issues:list<array{code:string,kind:string,resource_type:string,resource_id:int|string|null,resource_name:string|null,summary:string,expected:bool|string|null,observed:bool|string|null}>}> $families
 * @return array{node_id:int,node_name:string,healthy:bool,families:list<array{family:string,status:string,checked:int,issues:list<array{code:string,kind:string,resource_type:string,resource_id:int|string|null,resource_name:string|null,summary:string,expected:bool|string|null,observed:bool|string|null}>}>}
 */
function doctor_cli_node(string $name, array $families, int $nodeId = 7): array
{
    return [
        'node_id' => $nodeId,
        'node_name' => $name,
        'healthy' =>
            $families === []
                || array_all($families, static fn (array $family): bool => $family['status'] === 'healthy'),
        'families' => $families,
    ];
}

/**
 * @param list<array{code:string,kind:string,resource_type:string,resource_id:int|string|null,resource_name:string|null,summary:string,expected:bool|string|null,observed:bool|string|null}> $issues
 * @return array{family:string,status:string,checked:int,issues:list<array{code:string,kind:string,resource_type:string,resource_id:int|string|null,resource_name:string|null,summary:string,expected:bool|string|null,observed:bool|string|null}>}
 */
function doctor_cli_family(string $family, string $status, int $checked, array $issues): array
{
    return [
        'family' => $family,
        'status' => $status,
        'checked' => $checked,
        'issues' => $issues,
    ];
}

/**
 * @mago-expect lint:excessive-parameter-list The helper mirrors the complete Doctor issue wire shape.
 * @return array{code:string,kind:string,resource_type:string,resource_id:int|string|null,resource_name:string|null,summary:string,expected:bool|string|null,observed:bool|string|null}
 */
function doctor_cli_issue(
    string $code,
    string $summary,
    string $kind = 'drift',
    string $resourceType = 'workspace',
    int|string|null $resourceId = 7,
    ?string $resourceName = 'primary',
    bool|string|null $expected = true,
    bool|string|null $observed = false,
): array {
    return [
        'code' => $code,
        'kind' => $kind,
        'resource_type' => $resourceType,
        'resource_id' => $resourceId,
        'resource_name' => $resourceName,
        'summary' => $summary,
        'expected' => $expected,
        'observed' => $observed,
    ];
}

/** @param array<array-key, mixed> $data */
function doctor_cli_mock(array $data, ?string $requestId = null): MockClient
{
    return MockClient::global([
        RunDoctorRequest::class => MockResponse::make([
            'data' => $data,
            'meta' => ['request_id' => $requestId ?? doctor_cli_request_id()],
        ]),
    ]);
}

function doctor_cli_request_id(): string
{
    return '11111111-1111-4111-8111-111111111111';
}
