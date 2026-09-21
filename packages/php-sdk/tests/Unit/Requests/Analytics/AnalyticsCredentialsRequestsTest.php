<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\Analytics\SetAnalyticsCredentialsRequest;
use Orbit\Sdk\Requests\Analytics\ShowAnalyticsCredentialsRequest;
use Orbit\Sdk\Requests\Analytics\UnsetAnalyticsCredentialsRequest;
use Orbit\Sdk\Responses\Analytics\AnalyticsCredentialsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

function analytics_credentials_payload(bool $configured = true): array
{
    return [
        'data' => ['configured' => $configured, 'driver' => 'plausible_ce'],
        'meta' => ['request_id' => node_role_request_id()],
    ];
}

describe('analytics credentials requests', function (): void {
    it('reads whether a Stats API key is stored and never expects the key', function (): void {
        $mockClient = new MockClient([
            ShowAnalyticsCredentialsRequest::class => MockResponse::make(analytics_credentials_payload(false)),
        ]);

        $response = node_role_gateway_connector($mockClient)->send(new ShowAnalyticsCredentialsRequest)->dto();

        expect((new ShowAnalyticsCredentialsRequest)->getMethod())->toBe(Method::GET)
            ->and((new ShowAnalyticsCredentialsRequest)->resolveEndpoint())->toBe('/api/v1/analytics/credentials')
            ->and($response)->toBeInstanceOf(AnalyticsCredentialsResponse::class)
            ->and($response->toArray())->toBe([
                'configured' => false,
                'driver' => 'plausible_ce',
                'request_id' => node_role_request_id(),
            ]);
    });

    it('sends the key on set and hides it from debug output', function (): void {
        $secret = 'plausible-stats-sentinel-key';
        $mockClient = new MockClient([
            SetAnalyticsCredentialsRequest::class => MockResponse::make(analytics_credentials_payload()),
        ]);
        $request = new SetAnalyticsCredentialsRequest($secret);

        $response = node_role_gateway_connector($mockClient)->send($request)->dto();

        expect($request->getMethod())->toBe(Method::PUT)
            ->and($request->resolveEndpoint())->toBe('/api/v1/analytics/credentials')
            ->and($mockClient->getLastPendingRequest()?->body()->all())->toBe(['api_key' => $secret])
            ->and($response->configured)->toBeTrue()
            ->and(print_r($request, true))->not->toContain($secret);
    });

    it('clears the stored key', function (): void {
        $mockClient = new MockClient([
            UnsetAnalyticsCredentialsRequest::class => MockResponse::make(analytics_credentials_payload(false)),
        ]);

        $response = node_role_gateway_connector($mockClient)->send(new UnsetAnalyticsCredentialsRequest)->dto();

        expect((new UnsetAnalyticsCredentialsRequest)->getMethod())->toBe(Method::DELETE)
            ->and($response->configured)->toBeFalse();
    });

    it('refuses an answer that lacks configured', function (): void {
        $mockClient = new MockClient([
            ShowAnalyticsCredentialsRequest::class => MockResponse::make([
                'data' => ['driver' => 'plausible_ce'],
                'meta' => ['request_id' => node_role_request_id()],
            ]),
        ]);

        expect(fn () => node_role_gateway_connector($mockClient)->send(new ShowAnalyticsCredentialsRequest)->dto())
            ->toThrow(GatewayApiException::class, 'invalid analytics credentials data');
    });
});
