<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Tools\RemoveToolRequest;
use Orbit\Sdk\Requests\Tools\UpdateToolRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-tool-action-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

it('rejects a blocked update with no constraint as an invalid response', function (): void {
    MockClient::global([
        UpdateToolRequest::class => MockResponse::make([
            'data' => [
                'id' => 41,
                'node_id' => 12,
                'manager' => 'apt',
                'package' => 'curl',
                'version_constraint' => null,
                'protected' => false,
                'status' => 'installed',
                'installed_version' => null,
                'failed_operation' => null,
                'error_code' => null,
                'outcome' => 'blocked_by_constraint',
            ],
            'meta' => ['request_id' => '77777777-7777-4777-8777-777777777777'],
        ]),
    ]);
    $this
        ->artisan('tool:update', ['tool' => '41'])
        ->expectsOutput('Gateway response is invalid.')
        ->expectsOutput('Request ID: 77777777-7777-4777-8777-777777777777')
        ->assertExitCode(1);
});

it('writes exact update DTO JSON', function (): void {
    $id = '88888888-8888-4888-8888-888888888888';
    MockClient::global([
        UpdateToolRequest::class => MockResponse::make([
            'data' => [
                'id' => 41,
                'node_id' => 12,
                'manager' => 'vp',
                'package' => '@openai/codex',
                'version_constraint' => '^0.150',
                'protected' => false,
                'status' => 'installed',
                'installed_version' => '0.151.0',
                'failed_operation' => null,
                'error_code' => null,
                'outcome' => 'applied',
            ],
            'meta' => ['request_id' => $id],
        ]),
    ]);
    $this
        ->artisan('tool:update', ['tool' => '41', '--json' => true])
        ->expectsOutput(json_encode([
            'id' => 41,
            'node_id' => 12,
            'manager' => 'vp',
            'package' => '@openai/codex',
            'version_constraint' => '^0.150',
            'protected' => false,
            'status' => 'installed',
            'installed_version' => '0.151.0',
            'failed_operation' => null,
            'error_code' => null,
            'outcome' => 'applied',
            'request_id' => $id,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->doesntExpectOutput('Tool [')
        ->assertSuccessful();
});

it('renders JSON constraint failures with exact codes and request IDs', function (): void {
    foreach ([
        ['tool.version_constraint_blocked', 'Tool update blocked by the version constraint.'],
        ['tool.constraint_invalid',         'Tool version constraint is invalid.'],
    ] as [$code, $message]) {
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        MockClient::destroyGlobal();
        MockClient::global([
            UpdateToolRequest::class => MockResponse::make(
                [
                    'error' => ['code' => $code, 'message' => $message],
                ],
                422,
                ['X-Orbit-Request-Id' => $id],
            ),
        ]);
        $this
            ->artisan('tool:update', ['tool' => '41', '--json' => true])
            ->expectsOutput(json_encode(['error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => $id,
            ]], JSON_THROW_ON_ERROR))
            ->assertExitCode(1);
    }
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('updates a tool and preserves its request ID', function (): void {
    $mock = MockClient::global([
        UpdateToolRequest::class => MockResponse::make(
            [
                'data' => [
                    'id' => 41,
                    'node_id' => 12,
                    'manager' => 'vp',
                    'package' => '@openai/codex',
                    'version_constraint' => '^0.150',
                    'protected' => false,
                    'status' => 'installed',
                    'installed_version' => '0.151.0',
                    'failed_operation' => null,
                    'error_code' => null,
                    'outcome' => 'applied',
                ],
                'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
            ],
            200,
            ['X-Request-Id' => 'req-update-41'],
        ),
    ]);

    $this
        ->artisan('tool:update', ['tool' => '41'])
        ->expectsOutput('Tool [@openai/codex] updated.')
        ->expectsOutput('Request ID: 11111111-1111-4111-8111-111111111111')
        ->assertSuccessful();

    expect($mock->getLastRequest())
        ->toBeInstanceOf(UpdateToolRequest::class)
        ->and($mock->getLastRequest()?->getMethod())
        ->toBe(Method::POST)
        ->and($mock->getLastPendingRequest()?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/tools/41/update')
        ->and($mock->getLastPendingRequest()?->query()->all())
        ->toBeEmpty()
        ->and($mock->getLastPendingRequest()?->body())
        ->toBeNull();
});

it('removes a tool as one typed request and writes one DTO JSON line', function (): void {
    $mock = MockClient::global([
        RemoveToolRequest::class => MockResponse::make(
            [
                'data' => [
                    'id' => 41,
                    'node_id' => 12,
                    'manager' => 'apt',
                    'package' => 'curl',
                    'version_constraint' => null,
                    'protected' => false,
                    'status' => 'removed',
                    'installed_version' => null,
                    'failed_operation' => null,
                    'error_code' => null,
                    'outcome' => 'applied',
                ],
                'meta' => ['request_id' => '22222222-2222-4222-8222-222222222222'],
            ],
            200,
            ['X-Request-Id' => 'req-remove-41'],
        ),
    ]);

    $this
        ->artisan('tool:remove', ['tool' => '41', '--json' => true])
        ->expectsOutput(json_encode([
            'id' => 41,
            'node_id' => 12,
            'manager' => 'apt',
            'package' => 'curl',
            'version_constraint' => null,
            'protected' => false,
            'status' => 'removed',
            'installed_version' => null,
            'failed_operation' => null,
            'error_code' => null,
            'outcome' => 'applied',
            'request_id' => '22222222-2222-4222-8222-222222222222',
        ], JSON_UNESCAPED_SLASHES))
        ->assertSuccessful();

    expect($mock->getLastRequest())
        ->toBeInstanceOf(RemoveToolRequest::class)
        ->and($mock->getLastRequest()?->getMethod())
        ->toBe(Method::DELETE)
        ->and($mock->getLastPendingRequest()?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/tools/41')
        ->and($mock->getLastPendingRequest()?->query()->all())
        ->toBeEmpty()
        ->and($mock->getLastPendingRequest()?->body())
        ->toBeNull();
});

it('rejects a non-positive tool ID before sending HTTP', function (): void {
    $mock = MockClient::global();

    $this
        ->artisan('tool:update', ['tool' => '0'])
        ->expectsOutput('Tool ID must be a positive integer.')
        ->assertExitCode(1);
    $this
        ->artisan('tool:remove', ['tool' => '-1'])
        ->expectsOutput('Tool ID must be a positive integer.')
        ->assertExitCode(1);

    expect($mock->getLastRequest())->toBeNull();
});

it('renders unchanged and constraint-blocked update outcomes exactly', function (): void {
    foreach ([
        ['unchanged', 'Tool [curl] is already current.', '33333333-3333-4333-8333-333333333333'],
        [
            'blocked_by_constraint',
            'Tool [curl] update blocked by constraint [^8].',
            '44444444-4444-4444-8444-444444444444',
        ],
    ] as [$outcome, $message, $requestId]) {
        MockClient::destroyGlobal();
        MockClient::global([
            UpdateToolRequest::class => MockResponse::make([
                'data' => [
                    'id' => 41,
                    'node_id' => 12,
                    'manager' => 'apt',
                    'package' => 'curl',
                    'version_constraint' => '^8',
                    'protected' => false,
                    'status' => 'installed',
                    'installed_version' => '8.0',
                    'failed_operation' => null,
                    'error_code' => null,
                    'outcome' => $outcome,
                ],
                'meta' => ['request_id' => $requestId],
            ]),
        ]);
        $this
            ->artisan('tool:update', ['tool' => '41'])
            ->expectsOutput($message)
            ->expectsOutput("Request ID: {$requestId}")
            ->assertSuccessful();
    }
});

it('renders the exact human remove message and request ID', function (): void {
    MockClient::global([
        RemoveToolRequest::class => MockResponse::make([
            'data' => [
                'id' => 41,
                'node_id' => 12,
                'manager' => 'apt',
                'package' => 'curl',
                'version_constraint' => null,
                'protected' => false,
                'status' => 'removed',
                'installed_version' => null,
                'failed_operation' => null,
                'error_code' => null,
                'outcome' => 'applied',
            ],
            'meta' => ['request_id' => '55555555-5555-4555-8555-555555555555'],
        ]),
    ]);
    $this
        ->artisan('tool:remove', ['tool' => '41'])
        ->expectsOutput('Tool [curl] removed.')
        ->expectsOutput('Request ID: 55555555-5555-4555-8555-555555555555')
        ->assertSuccessful();
});

it('rejects nonnumeric IDs for both actions before HTTP', function (): void {
    $mock = MockClient::global();
    foreach (['tool:update', 'tool:remove'] as $command) {
        $this->artisan($command, ['tool' => 'abc'])->assertExitCode(1);
    }
    expect($mock->getLastRequest())->toBeNull();
});

it('renders invalid successful outcomes with the response request ID', function (): void {
    MockClient::global([
        UpdateToolRequest::class => MockResponse::make([
            'data' => [
                'id' => 41,
                'node_id' => 12,
                'manager' => 'apt',
                'package' => 'curl',
                'version_constraint' => null,
                'protected' => false,
                'status' => 'installed',
                'installed_version' => null,
                'failed_operation' => null,
                'error_code' => null,
                'outcome' => 'unexpected',
            ],
            'meta' => ['request_id' => '66666666-6666-4666-8666-666666666666'],
        ]),
    ]);
    $this
        ->artisan('tool:update', ['tool' => '41'])
        ->expectsOutput('Gateway response is invalid.')
        ->expectsOutput('Request ID: 66666666-6666-4666-8666-666666666666')
        ->assertExitCode(1);
});

it('rejects an invalid successful remove outcome', function (): void {
    MockClient::global([
        RemoveToolRequest::class => MockResponse::make([
            'data' => [
                'id' => 41,
                'node_id' => 12,
                'manager' => 'apt',
                'package' => 'curl',
                'version_constraint' => null,
                'protected' => false,
                'status' => 'installed',
                'installed_version' => null,
                'failed_operation' => null,
                'error_code' => null,
                'outcome' => 'unexpected',
            ],
            'meta' => ['request_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'],
        ]),
    ]);
    $this
        ->artisan('tool:remove', ['tool' => '41'])
        ->expectsOutput('Gateway response is invalid.')
        ->expectsOutput('Request ID: bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')
        ->assertExitCode(1);
});
