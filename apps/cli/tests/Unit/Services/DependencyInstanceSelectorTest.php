<?php

declare(strict_types=1);

use App\Services\DependencyInstanceSelector;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\AppInstances\ResolveAppInstanceRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('dependency domain selector hook', function (): void {
    it('delegates the exact domain to Gateway and preserves owning instance data', function (): void {
        $mock = new MockClient([ResolveAppInstanceRequest::class => MockResponse::make([
            'data' => ['domain' => 'app.example.test', 'instance_id' => 17, 'app_id' => 2, 'node_id' => 9, 'environment' => 'production'],
            'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
        ])]);
        $connector = new GatewayConnector('https://gateway.test');
        $connector->withMockClient($mock);
        $target = new DependencyInstanceSelector()->resolveDomain($connector, ' APP.EXAMPLE.TEST ');
        expect($target->instanceId)->toBe(17)->and($target->nodeId)->toBe(9)->and($target->appId)->toBe(2)
            ->and($target->environment)->toBe('production')->and($target->requestId)->toBe('11111111-1111-4111-8111-111111111111')
            ->and($mock->getLastPendingRequest()?->query()->all())->toBe(['domain' => ' APP.EXAMPLE.TEST ']);
        $mock->assertSentCount(1);
    });

    it('propagates an ambiguous target without fallback or a second request', function (): void {
        $mock = new MockClient([ResolveAppInstanceRequest::class => MockResponse::make([
            'error' => ['code' => 'dependencies.target_ambiguous', 'message' => 'The domain does not select one instance.', 'details' => []],
            'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
        ], 409, ['X-Orbit-Request-Id' => '11111111-1111-4111-8111-111111111111'])]);
        $connector = new GatewayConnector('https://gateway.test');
        $connector->withMockClient($mock);
        try {
            new DependencyInstanceSelector()->resolveDomain($connector, 'app.example.test');
            test()->fail('Ambiguous target accepted.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())->toBe('dependencies.target_ambiguous')
                ->and($exception->requestId())->toBe('11111111-1111-4111-8111-111111111111');
        }
        $mock->assertSentCount(1);
    });
});
