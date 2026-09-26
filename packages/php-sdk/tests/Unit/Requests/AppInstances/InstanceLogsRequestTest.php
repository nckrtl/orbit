<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\AppInstances\InstanceLogsRequest;
use Orbit\Sdk\Responses\AppInstances\InstanceLogsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe(InstanceLogsRequest::class, function (): void {
    it('reads one bounded Instance log tail and redacts credential-shaped lines', function (): void {
        $mock = new MockClient([
            InstanceLogsRequest::class => MockResponse::make([
                'data' => [
                    'id' => 12,
                    'name' => 'shop',
                    'lines' => 25,
                    'logs' => "[2026-09-25 10:15:02] local.ERROR: boom\nAuthorization: Bearer instance-log-secret-token\n",
                ],
                'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
            ]),
        ]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mock);
        $request = new InstanceLogsRequest(12, 25);

        $response = $connector->send($request)->dto();

        expect($request->getMethod())->toBe(Method::GET)
            ->and($request->resolveEndpoint())->toBe('/api/v1/instances/12/logs')
            ->and($request->query()->all())->toBe(['lines' => 25])
            ->and($response)->toBeInstanceOf(InstanceLogsResponse::class)
            ->and($response->toArray())->toBe([
                'id' => 12,
                'name' => 'shop',
                'lines' => 25,
                'logs' => "[2026-09-25 10:15:02] local.ERROR: boom\nAuthorization: [REDACTED]\n",
                'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
            ]);
    });

    it('defaults to 100 lines', function (): void {
        expect(new InstanceLogsRequest(3)->query()->all())->toBe(['lines' => 100]);
    });

    it('falls back to empty values for malformed fields', function (): void {
        $mock = new MockClient([
            InstanceLogsRequest::class => MockResponse::make([
                'data' => ['id' => '12', 'name' => null, 'lines' => '25', 'logs' => ['not', 'text']],
                'meta' => ['request_id' => 'not-a-uuid'],
            ]),
        ]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mock);

        $response = $connector->send(new InstanceLogsRequest(12))->dto();

        expect($response->toArray())->toBe(['id' => 0, 'name' => '', 'lines' => 0, 'logs' => '', 'request_id' => '']);
    });
});
