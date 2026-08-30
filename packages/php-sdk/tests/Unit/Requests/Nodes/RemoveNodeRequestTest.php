<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\RemoveNodeRequest;
use Orbit\Sdk\Responses\Nodes\RemovedNodeResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe(RemoveNodeRequest::class, function (): void {
    it('removes a node by numeric ID and maps the removal envelope', function (): void {
        $mockClient = new MockClient([
            RemoveNodeRequest::class => MockResponse::make([
                'data' => [
                    'id' => 12,
                    'name' => 'operator',
                    'removed' => true,
                ],
                'meta' => ['request_id' => '0198e15d-16c4-7855-8eb2-182b53ad28ba'],
            ]),
        ]);
        $connector = new GatewayConnector(
            'https://10.44.0.1',
            requestIdResolver: static fn (): string => '11111111-1111-4111-8111-111111111111',
        );
        $connector->withMockClient($mockClient);

        $response = $connector->send(new RemoveNodeRequest(12))->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::DELETE)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/nodes/12')
            ->and($mockClient->getLastPendingRequest()?->body()->all())
            ->toBe(['force' => false, 'offline' => false])
            ->and($response)
            ->toBeInstanceOf(RemovedNodeResponse::class)
            ->and($response->id)
            ->toBe(12)
            ->and($response->name)
            ->toBe('operator')
            ->and($response->removed)
            ->toBeTrue()
            ->and($response->requestId)
            ->toBe('0198e15d-16c4-7855-8eb2-182b53ad28ba')
            ->and($mockClient->getLastPendingRequest()?->headers()->get('X-Orbit-Request-Id'))
            ->toBe('11111111-1111-4111-8111-111111111111');
    });

    it('sends the force and offline claims in the request body', function (
        bool $force,
        bool $offline,
        array $expectedBody,
    ): void {
        $mockClient = new MockClient([
            RemoveNodeRequest::class => MockResponse::make([
                'data' => [
                    'id' => 12,
                    'name' => 'operator',
                    'removed' => true,
                ],
                'meta' => ['request_id' => '0198e15d-16c4-7855-8eb2-182b53ad28ba'],
            ]),
        ]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);

        $connector->send(new RemoveNodeRequest(12, force: $force, offline: $offline))->dto();

        expect($mockClient->getLastPendingRequest()?->body()->all())
            ->toBe($expectedBody);
    })->with([
        'default (both false)' => [false, false, ['force' => false, 'offline' => false]],
        'force alone' => [true, false, ['force' => true, 'offline' => false]],
        'offline alone' => [false, true, ['force' => false, 'offline' => true]],
        'both true' => [true, true, ['force' => true, 'offline' => true]],
    ]);

    it('preserves stable gateway failure context', function (): void {
        $mockClient = new MockClient([
            RemoveNodeRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'node.removal_failed',
                        'message' => 'The node could not be removed.',
                        'details' => ['node' => 'operator'],
                    ],
                ],
                422,
                ['X-Orbit-Request-Id' => '0198e15d-16c4-7855-8eb2-182b53ad28ba'],
            ),
        ]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);

        try {
            $connector->send(new RemoveNodeRequest(12))->dto();
            $this->fail('Expected GatewayApiException.');
        } catch (GatewayApiException $exception) {
            expect($exception->getMessage())
                ->toBe('The node could not be removed.')
                ->and($exception->errorCode())
                ->toBe('node.removal_failed')
                ->and($exception->details())
                ->toBe(['node' => 'operator'])
                ->and($exception->requestId())
                ->toBe('0198e15d-16c4-7855-8eb2-182b53ad28ba');
        }
    });
});
