<?php

declare(strict_types=1);

use App\Commands\Gateway\GatewayStatusCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Gateway\ShowGatewayStatusRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

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

    it('fails clearly when no gateway profile is active', function (): void {
        expect(class_exists(GatewayStatusCommand::class))->toBeTrue();

        $this
            ->artisan('gateway:status')
            ->expectsOutputToContain('No active gateway profile.')
            ->assertExitCode(1);
    });
});
