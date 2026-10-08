<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Fleet\ResumeFleetRolloutRequest;
use Orbit\Sdk\Requests\Fleet\ShowFleetRolloutRequest;
use Orbit\Sdk\Requests\Nodes\ConvergeNodeRequest;
use Orbit\Sdk\Responses\Fleet\FleetRolloutStatusResponse;
use Orbit\Sdk\Responses\Fleet\NodeFootprintResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/** Replays a recorded Gateway response and returns the request's DTO. */
function fleet_fixture_send(string $fixture, GatewayRequest $request): object
{
    $recorded = json_decode((string) file_get_contents(dirname(__DIR__, levels: 4)."/fixtures/{$fixture}.json"), true, flags: JSON_THROW_ON_ERROR);
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient(new MockClient([$request::class => MockResponse::make($recorded['body'], $recorded['status'])]));
    $dto = $connector->send($request)->dto();
    assert(is_object($dto));

    return $dto;
}

describe('fleet transport', function (): void {
    it('addresses the fleet and node converge routes', function (GatewayRequest $request, Method $method, string $endpoint, array $body): void {
        expect($request->getMethod())->toBe($method)
            ->and($request->resolveEndpoint())->toBe($endpoint)
            ->and(method_exists($request, 'body') ? $request->body()->all() : [])->toBe($body);
    })->with([
        'status' => [new ShowFleetRolloutRequest, Method::GET, '/api/v1/fleet/rollout', []],
        'resume' => [new ResumeFleetRolloutRequest, Method::POST, '/api/v1/fleet/rollout/resume', []],
        'resume with skip' => [new ResumeFleetRolloutRequest('app-dev'), Method::POST, '/api/v1/fleet/rollout/resume', ['skip' => 'app-dev']],
        'converge' => [new ConvergeNodeRequest(2), Method::POST, '/api/v1/nodes/2/converge', ['force' => false]],
        'converge forced' => [new ConvergeNodeRequest(2, force: true), Method::POST, '/api/v1/nodes/2/converge', ['force' => true]],
    ]);

    it('reads a halted rollout with each Node result', function (): void {
        $status = fleet_fixture_send('fleet/fleet-rollout-status/halted', new ShowFleetRolloutRequest);

        expect($status)->toBeInstanceOf(FleetRolloutStatusResponse::class)
            ->and($status->status)->toBe('halted')
            ->and($status->rollout?->haltedNode)->toBe('app-dev')
            ->and(array_map(static fn ($node): string => $node->outcome, $status->rollout->nodes ?? []))->toBe(['failed', 'pending'])
            ->and($status->excluded)->toBe([['node_id' => 1, 'node' => 'gateway', 'reason' => 'gateway']])
            ->and($status->toArray()['request_id'])->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844');
    });

    it('reads a node converge', function (): void {
        $result = fleet_fixture_send('nodes/node-converge/applied', new ConvergeNodeRequest(2));

        expect($result)->toBeInstanceOf(NodeFootprintResponse::class)
            ->and($result->artifacts)->toBe(['agent' => 'applied', 'caddy' => 'applied'])
            ->and($result->changed)->toBeTrue();
    });
});
