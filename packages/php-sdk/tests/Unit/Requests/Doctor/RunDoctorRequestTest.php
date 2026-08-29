<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Doctor\RunDoctorRequest;
use Orbit\Sdk\Responses\Doctor\DoctorReportResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('uses the exact Doctor JSON transport contract', function (
    ?int $nodeId,
    ?array $families,
    string $expectedBody,
): void {
    $mockClient = new MockClient([
        RunDoctorRequest::class => MockResponse::make([
            'data' => [
                'healthy' => true,
                'nodes' => [],
                'summary' => ['nodes' => 0, 'families' => 0, 'checks' => 0, 'drift' => 0, 'unverifiable' => 0],
            ],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]),
    ]);
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mockClient);

    $response = $connector->send(new RunDoctorRequest(nodeId: $nodeId, families: $families))->dto();
    $pendingRequest = $mockClient->getLastPendingRequest();

    $request = $mockClient->getLastRequest();

    expect($request?->getMethod())
        ->toBe(Method::POST)
        ->and($request?->resolveEndpoint())
        ->toBe('/api/v1/doctor')
        ->and($pendingRequest?->headers()->get('Content-Type'))
        ->toBe('application/json')
        ->and((string) $pendingRequest?->body()->__toString())
        ->toBe($expectedBody)
        ->and($response)
        ->toBeInstanceOf(DoctorReportResponse::class);
})->with([
    'no filters' => [null, null, '{}'],
    'node only' => [41, null, '{"node_id":41}'],
    'families only' => [null, ['firewall', 'node'], '{"families":["firewall","node"]}'],
    'explicit zero and empty list' => [0, [], '{"node_id":0,"families":[]}'],
    'all valid families' => [
        null,
        ['node', 'role', 'app', 'instance', 'workspace', 'tool', 'process', 'firewall'],
        '{"families":["node","role","app","instance","workspace","tool","process","firewall"]}',
    ],
    'invalid members preserved for Gateway validation' => [
        null,
        ['', 7, false, null, ['nested']],
        '{"families":["",7,false,null,["nested"]]}',
    ],
]);
