<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Analytics\SetAnalyticsCredentialsRequest;
use Orbit\Sdk\Requests\Analytics\ShowAnalyticsCredentialsRequest;
use Orbit\Sdk\Requests\Analytics\UnsetAnalyticsCredentialsRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-analytics-credentials-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);

    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
    $this->requestId = (string) Str::uuid();
    $this->answer = fn (bool $configured): MockResponse => MockResponse::make([
        'data' => ['configured' => $configured, 'driver' => 'plausible_ce'],
        'meta' => ['request_id' => $this->requestId],
    ]);
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('analytics:credentials', function (): void {
    it('returns whether a key is stored as JSON', function (): void {
        MockClient::global([ShowAnalyticsCredentialsRequest::class => ($this->answer)(false)]);

        $this->artisan('analytics:credentials', ['--json' => true])
            ->expectsOutputToContain('"configured":false')
            ->expectsOutputToContain('"driver":"plausible_ce"')
            ->assertExitCode(0);
    });

    it('stores a key and never prints it', function (): void {
        $secret = 'plausible-stats-sentinel-key';
        $mockClient = MockClient::global([SetAnalyticsCredentialsRequest::class => ($this->answer)(true)]);

        $this->artisan('analytics:credentials', ['--set' => true, '--api-key' => $secret, '--json' => true])
            ->expectsOutputToContain('"configured":true')
            ->doesntExpectOutputToContain($secret)
            ->assertExitCode(0);

        expect($mockClient->getLastPendingRequest()?->body()->all())->toBe(['api_key' => $secret]);
    });

    it('names that a key is stored for a human, without the key', function (): void {
        $secret = 'plausible-stats-sentinel-key';
        MockClient::global([SetAnalyticsCredentialsRequest::class => ($this->answer)(true)]);

        $this->artisan('analytics:credentials', ['--set' => true, '--api-key' => $secret])
            ->expectsOutputToContain('The Plausible Stats API key is stored.')
            ->doesntExpectOutputToContain($secret)
            ->assertExitCode(0);
    });

    it('clears the stored key', function (): void {
        MockClient::global([UnsetAnalyticsCredentialsRequest::class => ($this->answer)(false)]);

        $this->artisan('analytics:credentials', ['--unset' => true, '--json' => true])
            ->expectsOutputToContain('"configured":false')
            ->assertExitCode(0);
    });

    it('refuses --set and --unset together before any request', function (): void {
        $mockClient = MockClient::global();

        $this->artisan('analytics:credentials', ['--set' => true, '--unset' => true, '--json' => true])
            ->expectsOutputToContain('analytics.credentials_conflict')
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    });

    it('refuses a missing key without a terminal', function (): void {
        $mockClient = MockClient::global();

        $this->artisan('analytics:credentials', ['--set' => true, '--json' => true])
            ->expectsOutputToContain('analytics.api_key_invalid')
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    });
});
