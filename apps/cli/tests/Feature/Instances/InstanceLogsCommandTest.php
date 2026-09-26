<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\AppInstances\InstanceLogsRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-instance-logs-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1'));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('instance:logs', function (): void {
    it('prints the application log tail and the request ID', function (): void {
        $mock = MockClient::global([InstanceLogsRequest::class => instance_logs_response()]);

        $this
            ->artisan('instance:logs', ['instance' => '12', '--lines' => '25'])
            ->expectsOutput("[2026-09-25 10:15:02] local.ERROR: boom\nDB_PASSWORD=[redacted]")
            ->expectsOutput('Request ID: '.instance_logs_request_id())
            ->doesntExpectOutputToContain('instance-log-db-sentinel')
            ->assertExitCode(0);

        expect($mock->getLastPendingRequest()?->getUrl())->toBe('https://10.44.0.1/api/v1/instances/12/logs')
            ->and($mock->getLastRequest()?->query()->all())->toBe(['lines' => 25]);
    });

    it('returns the documented JSON shape', function (): void {
        MockClient::global([InstanceLogsRequest::class => instance_logs_response()]);

        $exitCode = Artisan::call('instance:logs', ['instance' => '12', '--json' => true]);

        expect($exitCode)->toBe(0)
            ->and(json_decode(trim(Artisan::output()), associative: true, flags: JSON_THROW_ON_ERROR))->toBe([
                'id' => 12,
                'name' => 'shop',
                'lines' => 25,
                'logs' => "[2026-09-25 10:15:02] local.ERROR: boom\nDB_PASSWORD=[redacted]\n",
                'request_id' => instance_logs_request_id(),
            ]);
    });

    it('refuses an invalid Instance ID or line count before it calls the Gateway', function (array $arguments, string $code): void {
        $mock = MockClient::global([InstanceLogsRequest::class => instance_logs_response()]);

        $exitCode = Artisan::call('instance:logs', [...$arguments, '--json' => true]);

        expect($exitCode)->toBe(1)
            ->and(json_decode(trim(Artisan::output()), associative: true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe($code)
            ->and($mock->getRecordedResponses())->toBe([]);
    })->with([
        'non-numeric Instance' => [['instance' => 'shop'], 'instance.id_invalid'],
        'zero lines' => [['instance' => '12', '--lines' => '0'], 'instance.log_lines_invalid'],
        'too many lines' => [['instance' => '12', '--lines' => '1001'], 'instance.log_lines_invalid'],
        'follow with invalid lines' => [['instance' => '12', '--lines' => 'x', '--follow' => true], 'instance.log_lines_invalid'],
    ]);

    it('keeps the Gateway error code and request ID', function (): void {
        MockClient::global([InstanceLogsRequest::class => MockResponse::make([
            'error' => ['code' => 'instance.logs_failed', 'message' => 'The Node did not answer.', 'details' => []],
        ], 502, ['X-Orbit-Request-Id' => instance_logs_request_id()])]);

        $exitCode = Artisan::call('instance:logs', ['instance' => '12', '--json' => true]);

        expect($exitCode)->toBe(1)
            ->and(json_decode(trim(Artisan::output()), associative: true, flags: JSON_THROW_ON_ERROR))->toBe([
                'error' => [
                    'code' => 'instance.logs_failed',
                    'message' => 'The Node did not answer.',
                    'request_id' => instance_logs_request_id(),
                ],
            ]);
    });
});

function instance_logs_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}

function instance_logs_response(): MockResponse
{
    return MockResponse::make([
        'data' => [
            'id' => 12,
            'name' => 'shop',
            'lines' => 25,
            'logs' => "[2026-09-25 10:15:02] local.ERROR: boom\nDB_PASSWORD=instance-log-db-sentinel\n",
        ],
        'meta' => ['request_id' => instance_logs_request_id()],
    ]);
}
