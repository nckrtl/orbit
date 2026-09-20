<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Analytics\DisableInstanceAnalyticsRequest;
use Orbit\Sdk\Requests\Analytics\EnableInstanceAnalyticsRequest;
use Orbit\Sdk\Requests\Analytics\ShowInstanceAnalyticsRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-instance-analytics-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);

    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
    $this->requestId = (string) Str::uuid();
    $this->enabled = fn (): MockResponse => MockResponse::make([
        'data' => [
            'instance_id' => 12,
            'enabled' => true,
            'domain' => 'shop.example.com',
            'dashboard_url' => 'https://analytics.orbit',
            'hosts' => [[
                'host' => 'analytics.shop.example.com',
                'route_id' => 91,
                'status' => 'active',
                'publication' => 'public',
                'failed_step' => null,
                'error_code' => null,
                'script_url' => 'https://analytics.shop.example.com/js/script.js',
                'event_url' => 'https://analytics.shop.example.com/api/event',
                'dns' => ['type' => 'CNAME', 'name' => 'analytics.shop.example.com', 'value' => 'shop.example.com'],
            ]],
            'snippet' => '<script defer data-domain="shop.example.com" src="https://analytics.shop.example.com/js/script.js"></script>',
        ],
        'meta' => ['request_id' => $this->requestId],
    ]);
    $this->disabled = fn (): MockResponse => MockResponse::make([
        'data' => ['instance_id' => 12, 'enabled' => false, 'domain' => 'shop.example.com', 'dashboard_url' => 'https://analytics.orbit', 'hosts' => [], 'snippet' => null],
        'meta' => ['request_id' => $this->requestId],
    ]);
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('instance:analytics:enable', function (): void {
    it('publishes the default host when none is named, and tells the operator what to do next', function (): void {
        $mockClient = MockClient::global([EnableInstanceAnalyticsRequest::class => ($this->enabled)()]);

        $this->artisan('instance:analytics:enable', ['instance' => '12'])
            ->expectsOutputToContain('Analytics tracking enabled.')
            ->expectsOutputToContain('CNAME analytics.shop.example.com -> shop.example.com')
            ->expectsOutputToContain('<script defer data-domain="shop.example.com"')
            ->assertExitCode(0);

        expect($mockClient->getLastPendingRequest()?->body()->all())->toBe([]);
    });

    it('sends every named host', function (): void {
        $mockClient = MockClient::global([EnableInstanceAnalyticsRequest::class => ($this->enabled)()]);

        $this->artisan('instance:analytics:enable', ['instance' => '12', '--host' => ['stats.example.com', 'analytics.shop.example.com'], '--json' => true])
            ->assertExitCode(0);

        expect($mockClient->getLastPendingRequest()?->body()->all())
            ->toBe(['hosts' => ['stats.example.com', 'analytics.shop.example.com']]);
    });
});

describe('instance:analytics:show', function (): void {
    it('returns the Gateway answer as JSON', function (): void {
        MockClient::global([ShowInstanceAnalyticsRequest::class => ($this->enabled)()]);

        $this->artisan('instance:analytics:show', ['instance' => '12', '--json' => true])
            ->expectsOutputToContain('"script_url":"https://analytics.shop.example.com/js/script.js"')
            ->assertExitCode(0);
    });

    it('says tracking is disabled for an instance without hosts', function (): void {
        MockClient::global([ShowInstanceAnalyticsRequest::class => ($this->disabled)()]);

        $this->artisan('instance:analytics:show', ['instance' => '12'])
            ->expectsOutputToContain('disabled')
            ->assertExitCode(0);
    });
});

describe('instance:analytics:disable', function (): void {
    it('refuses without --yes when it cannot ask, and sends nothing', function (): void {
        $mockClient = MockClient::global();

        $this->artisan('instance:analytics:disable', ['instance' => '12', '--json' => true])
            ->expectsOutputToContain('input.confirmation_required')
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    });

    it('removes the hosts once confirmed', function (): void {
        $mockClient = MockClient::global([DisableInstanceAnalyticsRequest::class => ($this->disabled)()]);

        $this->artisan('instance:analytics:disable', ['instance' => '12', '--yes' => true])
            ->expectsOutputToContain('Analytics tracking disabled.')
            ->assertExitCode(0);

        expect($mockClient->getLastPendingRequest()?->getRequest())->toBeInstanceOf(DisableInstanceAnalyticsRequest::class);
    });
});

it('refuses an instance that is not a positive number before any request', function (string $command): void {
    $mockClient = MockClient::global();

    $this->artisan($command, ['instance' => 'shop', '--json' => true])
        ->expectsOutputToContain('instance.id_invalid')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with(['instance:analytics:show', 'instance:analytics:enable', 'instance:analytics:disable']);
