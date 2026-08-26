<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Activities\ListActivitiesRequest;
use Orbit\Sdk\Requests\Activities\ShowActivityRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-activity-'.Str::uuid();
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

describe('activity:list', function (): void {
    it('returns a bounded activity collection as JSON', function (): void {
        $mock = MockClient::global([
            ListActivitiesRequest::class => MockResponse::make([
                'data' => [activity_cli_payload()],
                'meta' => [
                    'limit' => 10,
                    'count' => 1,
                    'request_id' => activity_cli_gateway_request_id(),
                ],
            ]),
        ]);
        $expected = json_encode([
            'activities' => [activity_cli_payload()],
            'request_id' => activity_cli_gateway_request_id(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this
            ->artisan('activity:list', [
                '--limit' => '10',
                '--request-id' => '33333333-3333-4333-8333-333333333333',
                '--json' => true,
            ])
            ->expectsOutput($expected)
            ->assertExitCode(0);

        expect($mock->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/activities')
            ->and($mock->getLastRequest()?->query()->all())
            ->toBe([
                'limit' => 10,
                'request_id' => '33333333-3333-4333-8333-333333333333',
            ]);
    });

    it('renders concise activity rows for humans', function (): void {
        MockClient::global([
            ListActivitiesRequest::class => MockResponse::make([
                'data' => [activity_cli_payload()],
                'meta' => ['request_id' => activity_cli_gateway_request_id()],
            ]),
        ]);

        $this
            ->artisan('activity:list')
            ->expectsTable(
                ['ID', 'Time', 'Command', 'Status', 'Caller', 'Target', 'Error'],
                [[
                    42,
                    '2026-08-25T12:00:00+00:00',
                    'process:start',
                    'failed',
                    '2',
                    '3',
                    'process.start_failed',
                ]],
            )
            ->expectsOutput('Request ID: '.activity_cli_gateway_request_id())
            ->assertExitCode(0);
    });

    it('rejects an invalid limit before sending a gateway request', function (string $limit): void {
        $mock = MockClient::global();

        $this
            ->artisan('activity:list', ['--limit' => $limit])
            ->expectsOutputToContain('Limit must be between 1 and 200.')
            ->assertExitCode(1);

        expect($mock->getLastPendingRequest())->toBeNull();
    })->with([
        'fractional' => '1.5',
        'zero' => '0',
        'above maximum' => '201',
    ]);

    it('rejects an invalid request ID before sending a gateway request', function (): void {
        $mock = MockClient::global();

        $this
            ->artisan('activity:list', ['--request-id' => 'not-a-uuid'])
            ->expectsOutputToContain('Request ID must be a UUID.')
            ->assertExitCode(1);

        expect($mock->getLastPendingRequest())->toBeNull();
    });
});

describe('activity:show', function (): void {
    it('shows one activity as JSON', function (): void {
        $mock = MockClient::global([
            ShowActivityRequest::class => MockResponse::make([
                'data' => activity_cli_payload(),
                'meta' => ['request_id' => activity_cli_gateway_request_id()],
            ]),
        ]);
        $expected = json_encode([
            ...activity_cli_payload(),
            'gateway_request_id' => activity_cli_gateway_request_id(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this
            ->artisan('activity:show', ['activity' => '42', '--json' => true])
            ->expectsOutput($expected)
            ->assertExitCode(0);

        expect($mock->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/activities/42');
    });

    it('shows the command outcome for humans', function (): void {
        MockClient::global([
            ShowActivityRequest::class => MockResponse::make([
                'data' => activity_cli_payload(),
                'meta' => ['request_id' => activity_cli_gateway_request_id()],
            ]),
        ]);

        $this
            ->artisan('activity:show', ['activity' => '42'])
            ->expectsOutput('Activity #42: process:start [failed]')
            ->expectsOutput('Activity request ID: 33333333-3333-4333-8333-333333333333')
            ->expectsOutput('Error: process.start_failed')
            ->expectsOutput('Request ID: '.activity_cli_gateway_request_id())
            ->assertExitCode(0);
    });

    it('rejects an invalid activity ID before sending a gateway request', function (): void {
        $mock = MockClient::global();

        $this
            ->artisan('activity:show', ['activity' => 'nope'])
            ->expectsOutputToContain('Activity ID must be a positive integer.')
            ->assertExitCode(1);

        expect($mock->getLastPendingRequest())->toBeNull();
    });
});

/** @return array<string, mixed> */
function activity_cli_payload(): array
{
    return [
        'id' => 42,
        'request_id' => '33333333-3333-4333-8333-333333333333',
        'command' => 'process:start',
        'caller_node_id' => 2,
        'target_node_id' => 3,
        'caller_ip' => '10.44.0.2',
        'status' => 'failed',
        'duration_ms' => 12,
        'exit_code' => 1,
        'error_code' => 'process.start_failed',
        'subject_type' => 'App\\Models\\Process',
        'subject_id' => 7,
        'properties' => ['output_truncated' => true],
        'occurred_at' => '2026-08-25T12:00:00+00:00',
    ];
}

function activity_cli_gateway_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
