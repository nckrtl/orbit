<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Analytics\UpdateAnalyticsRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-analytics-update-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);

    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
    $this->requestId = (string) Str::uuid();
    $this->answer = fn (string $version, string $previous): MockResponse => MockResponse::make([
        'data' => ['node_id' => 17, 'node_name' => 'services', 'version' => $version, 'previous_version' => $previous],
        'meta' => ['request_id' => $this->requestId],
    ]);
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('analytics:update', function (): void {
    it('sends the version and returns the Gateway answer as JSON', function (): void {
        $mockClient = MockClient::global([UpdateAnalyticsRequest::class => ($this->answer)('3.3.0', '3.2.1')]);

        $this->artisan('analytics:update', ['version' => '3.3.0', '--json' => true])
            ->expectsOutputToContain('"previous_version":"3.2.1"')
            ->assertExitCode(0);

        expect($mockClient->getLastPendingRequest()?->body()->all())->toBe(['version' => '3.3.0']);
    });

    it('names the node and both versions for a human', function (): void {
        MockClient::global([UpdateAnalyticsRequest::class => ($this->answer)('3.3.0', '3.2.1')]);

        $this->artisan('analytics:update', ['version' => '3.3.0'])
            ->expectsOutputToContain('Plausible updated on node [services].')
            ->assertExitCode(0);
    });

    it('says so when that version already runs', function (): void {
        MockClient::global([UpdateAnalyticsRequest::class => ($this->answer)('3.2.1', '3.2.1')]);

        $this->artisan('analytics:update', ['version' => '3.2.1'])
            ->expectsOutputToContain('Plausible already runs 3.2.1 on node [services].')
            ->assertExitCode(0);
    });

    it('refuses a missing or malformed version before any request', function (array $arguments): void {
        $mockClient = MockClient::global();

        $this->artisan('analytics:update', [...$arguments, '--json' => true])
            ->expectsOutputToContain('analytics.version_invalid')
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    })->with([
        'missing' => [[]],
        'v prefix' => [['version' => 'v3.3.0']],
        'two numbers' => [['version' => '3.3']],
        'a tag' => [['version' => 'latest']],
    ]);
});
