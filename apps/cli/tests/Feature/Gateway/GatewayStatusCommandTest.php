<?php

declare(strict_types=1);

use App\Commands\Gateway\GatewayStatusCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Gateway\ShowGatewayStatusRequest;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

beforeEach(function (): void {
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

/** @mago-expect lint:halstead The feature group locks the complete status output and failure contract. */
describe(GatewayStatusCommand::class, function (): void {
    it('reports the active gateway through the SDK', function (): void {
        expect(class_exists(GatewayStatusCommand::class))->toBeTrue();

        app(GatewayConfigRepository::class)->add(new GatewayProfile(
            name: 'test',
            url: 'https://10.70.0.1',
            caPath: '/home/orbit/.orbit/ca/root.pem',
        ));
        $mockClient = MockClient::global([
            ShowGatewayStatusRequest::class => MockResponse::make([
                'data' => [
                    'name' => 'orbit-gateway',
                    'status' => 'ok',
                    'version' => '0.1.0',
                    'php_version' => '8.5.8',
                    'laravel_version' => '13.26.1',
                ],
                'meta' => [
                    'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
                ],
            ]),
        ]);

        $expectedOutput = json_encode([
            'gateway' => 'test',
            'url' => 'https://10.70.0.1',
            'name' => 'orbit-gateway',
            'status' => 'ok',
            'version' => '0.1.0',
            'php_version' => '8.5.8',
            'laravel_version' => '13.26.1',
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this
            ->artisan('gateway:status', ['--json' => true])
            ->expectsOutput($expectedOutput)
            ->assertExitCode(0);

        $pendingRequest = $mockClient->getLastPendingRequest();
        $requestId = $pendingRequest?->headers()->get('X-Orbit-Request-Id');

        expect($pendingRequest?->getUrl())
            ->toBe('https://10.70.0.1/api/v1/gateway/status')
            ->and($requestId)
            ->toBeString()
            ->and(Str::isUuid($requestId))
            ->toBeTrue();
    });

    it('does not invent values for malformed gateway status fields', function (): void {
        app(GatewayConfigRepository::class)->add(new GatewayProfile(
            name: 'test',
            url: 'https://10.70.0.1',
            caPath: '/home/orbit/.orbit/ca/root.pem',
        ));
        MockClient::global([
            ShowGatewayStatusRequest::class => MockResponse::make([
                'data' => [
                    'name' => ['invalid'],
                    'status' => null,
                    'version' => 1,
                    'php_version' => false,
                    'laravel_version' => new stdClass,
                ],
                'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
            ]),
        ]);
        $expected = json_encode([
            'gateway' => 'test',
            'url' => 'https://10.70.0.1',
            'name' => '',
            'status' => '',
            'version' => '',
            'php_version' => '',
            'laravel_version' => '',
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this
            ->artisan('gateway:status', ['--json' => true])
            ->expectsOutput($expected)
            ->assertExitCode(0);
    });

    it('renders malformed gateway status fields safely for humans', function (): void {
        app(GatewayConfigRepository::class)->add(new GatewayProfile(
            name: 'test',
            url: 'https://10.70.0.1',
            caPath: '/home/orbit/.orbit/ca/root.pem',
        ));
        MockClient::global([
            ShowGatewayStatusRequest::class => MockResponse::make([
                'data' => [],
                'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
            ]),
        ]);

        $this
            ->artisan('gateway:status')
            ->expectsOutput('test: - (-)')
            ->expectsOutput('URL: https://10.70.0.1')
            ->expectsOutput('Request ID: 0198e15c-bf97-7c23-8f1f-61b8fe67a844')
            ->assertExitCode(0);
    });

    it('fails clearly when no gateway profile is active', function (): void {
        expect(class_exists(GatewayStatusCommand::class))->toBeTrue();

        $this
            ->artisan('gateway:status')
            ->expectsOutputToContain('No active gateway profile.')
            ->assertExitCode(1);
    });

    it('renders a json failure when no gateway profile is active', function (): void {
        $expected = json_encode([
            'error' => [
                'code' => 'gateway.profile_missing',
                'message' => 'No active gateway profile.',
                'request_id' => null,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $exitCode = Artisan::call('gateway:status', ['--json' => true]);

        expect($exitCode)
            ->toBe(1)
            ->and(trim(Artisan::output()))
            ->toBe($expected);
    });

    it('prints the request ID for gateway API errors', function (): void {
        app(GatewayConfigRepository::class)->add(new GatewayProfile(
            name: 'test',
            url: 'https://10.70.0.1',
            caPath: '/home/orbit/.orbit/ca/root.pem',
        ));
        MockClient::global([
            ShowGatewayStatusRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'gateway.unavailable',
                        'message' => 'Gateway is unavailable.',
                        'details' => [],
                    ],
                ],
                503,
                [
                    'X-Orbit-Request-Id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
                ],
            ),
        ]);

        $this
            ->artisan('gateway:status')
            ->expectsOutputToContain('Gateway is unavailable.')
            ->expectsOutput('Request ID: 0198e15c-bf97-7c23-8f1f-61b8fe67a844')
            ->assertExitCode(1);
    });

    it('uses the shared json boundary for fatal transport errors', function (): void {
        app(GatewayConfigRepository::class)->add(new GatewayProfile(
            name: 'test',
            url: 'https://10.70.0.1',
        ));
        MockClient::global([
            ShowGatewayStatusRequest::class => static function (PendingRequest $pendingRequest): never {
                throw new FatalRequestException(
                    new RuntimeException('Authorization: Bearer transport-secret'),
                    $pendingRequest,
                );
            },
        ]);
        $expected = json_encode([
            'error' => [
                'code' => 'gateway.unreachable',
                'message' => 'Could not reach the gateway.',
                'request_id' => null,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $exitCode = Artisan::call('gateway:status', ['--json' => true]);
        $output = trim(Artisan::output());

        expect($exitCode)->toBe(1);
        expect($output)
            ->toBe($expected)
            ->not->toContain('transport-secret');
    });
});
