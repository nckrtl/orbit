<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\Analytics\UpdateAnalyticsRequest;
use Orbit\Sdk\Responses\Analytics\AnalyticsUpdateResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe(UpdateAnalyticsRequest::class, function (): void {
    it('posts the version and returns the typed update response', function (): void {
        $mockClient = new MockClient([
            UpdateAnalyticsRequest::class => MockResponse::make([
                'data' => ['node_id' => 17, 'node_name' => 'services', 'version' => '3.3.0', 'previous_version' => '3.2.1'],
                'meta' => ['request_id' => node_role_request_id()],
            ]),
        ]);
        $request = new UpdateAnalyticsRequest('3.3.0');

        $response = node_role_gateway_connector($mockClient)->send($request)->dto();

        expect($request->getMethod())->toBe(Method::POST)
            ->and($request->resolveEndpoint())->toBe('/api/v1/analytics/update')
            ->and($mockClient->getLastPendingRequest()?->body()->all())->toBe(['version' => '3.3.0'])
            ->and($response)->toBeInstanceOf(AnalyticsUpdateResponse::class)
            ->and($response->toArray())->toBe([
                'node_id' => 17,
                'node_name' => 'services',
                'version' => '3.3.0',
                'previous_version' => '3.2.1',
                'request_id' => node_role_request_id(),
            ]);
    });

    it('refuses an answer that lacks a field', function (): void {
        $mockClient = new MockClient([
            UpdateAnalyticsRequest::class => MockResponse::make([
                'data' => ['node_id' => 17, 'node_name' => 'services', 'version' => '3.3.0'],
                'meta' => ['request_id' => node_role_request_id()],
            ]),
        ]);

        expect(fn () => node_role_gateway_connector($mockClient)->send(new UpdateAnalyticsRequest('3.3.0'))->dto())
            ->toThrow(GatewayApiException::class, 'invalid analytics update data');
    });
});
