<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\ProxyCli\ListProxyCliProvidersRequest;
use Orbit\Sdk\Requests\Schedules\ListSchedulesRequest;
use Orbit\Sdk\Requests\Tools\ListToolManagersRequest;
use Orbit\Sdk\Requests\Tools\ListToolsRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

dataset('strict collection requests', [
    'tools' => [new ListToolsRequest(12), 'tools', '/api/v1/tools', ['node_id' => 12]],
    'managers' => [new ListToolManagersRequest(12), 'managers', '/api/v1/tool-managers', ['node_id' => 12]],
    'schedules' => [new ListSchedulesRequest, 'schedules', '/api/v1/schedules', []],
    'providers' => [new ListProxyCliProvidersRequest, 'providers', '/api/v1/proxycli/providers', []],
]);

describe('strict collection envelope ownership', function (): void {
    it('rejects malformed envelopes with a safe typed failure', function (
        GatewayRequest $request, string $key, string $path, array $query, string $body,
    ): void {
        $mock = new MockClient([MockResponse::make($body)]);
        $connector = new GatewayConnector('https://gateway.example');
        $connector->withMockClient($mock);

        try {
            $connector->send($request)->dto();
            $this->fail('Expected a safe collection failure.');
        } catch (GatewayApiException $exception) {
            expect($exception->getMessage())->toBeIn([
                'Gateway response is not valid JSON.',
                'Gateway response contains invalid collection data.',
            ])->and($exception->details())->toBe([])
                ->and($exception->getPrevious())->toBeNull()
                ->and((string) $exception)->not->toContain('strict-list-secret');
        }
    })->with('strict collection requests')->with([
        'missing' => ['{}'],
        'null' => ['{"data":null}'],
        'string' => ['{"data":"strict-list-secret"}'],
        'boolean' => ['{"data":false}'],
        'number' => ['{"data":42}'],
        'object' => ['{"data":{"name":"strict-list-secret"}}'],
        'scalar member' => ['{"data":["strict-list-secret"]}'],
        'null member' => ['{"data":[null]}'],
        'numeric member key' => ['{"data":[{"0":"strict-list-secret"}]}'],
        'invalid JSON' => ['{"data": strict-list-secret'],
    ]);

    it('keeps valid empty collections and bodyless GET transport', function (
        GatewayRequest $request, string $key, string $path, array $query, string $data,
    ): void {
        $id = '11111111-1111-4111-8111-111111111111';
        $mock = new MockClient([MockResponse::make('{"data":'.$data.',"meta":{"request_id":"'.$id.'"}}')]);
        $connector = new GatewayConnector('https://gateway.example');
        $connector->withMockClient($mock);
        $dto = $connector->send($request)->dto();
        $pending = $mock->getLastPendingRequest();

        expect($dto->toArray())->toBe([$key => [], 'request_id' => $id])
            ->and($request->getMethod())->toBe(Method::GET)
            ->and($request->query()->all())->toBe($query)
            ->and($pending?->getUrl())->toBe('https://gateway.example'.$path)
            ->and($pending?->body())->toBeNull()
            ->and($pending?->headers()->all())->not->toHaveKey('Content-Type')
            ->and((string) $pending?->createPsrRequest()->getBody())->toBe('');
    })->with('strict collection requests')->with(['[]', '{}']);

    it('preserves ordered providers and validated correlation', function (): void {
        $id = '11111111-1111-4111-8111-111111111111';
        $mock = new MockClient([MockResponse::make([
            'data' => [['provider' => 'codex'], ['provider' => 'claude']],
            'meta' => ['request_id' => $id],
        ])]);
        $connector = new GatewayConnector('https://gateway.example');
        $connector->withMockClient($mock);
        $dto = $connector->send(new ListProxyCliProvidersRequest)->dto();

        expect($dto->requestId)->toBe($id)
            ->and(array_map(static fn ($provider): string => $provider->provider, $dto->providers))->toBe(['codex', 'claude'])
            ->and($dto->providers[0]->requestId)->toBe($id)
            ->and($dto->providers[1]->requestId)->toBe($id);
    });

    it('leaves permissive collection fallback unchanged', function (array $body): void {
        $mock = new MockClient([MockResponse::make($body)]);
        $connector = new GatewayConnector('https://gateway.example');
        $connector->withMockClient($mock);

        expect($connector->send(new ListNodesRequest)->dto()->nodes)->toBe([]);
    })->with([
        'missing' => [[]],
        'null' => [['data' => null]],
        'scalar' => [['data' => 'invalid']],
        'scalar members' => [['data' => [false, 42, null]]],
    ]);
});
