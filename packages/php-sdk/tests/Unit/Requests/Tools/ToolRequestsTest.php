<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Tools\InstallToolRequest;
use Orbit\Sdk\Requests\Tools\ListToolManagersRequest;
use Orbit\Sdk\Requests\Tools\ListToolsRequest;
use Orbit\Sdk\Requests\Tools\RemoveToolRequest;
use Orbit\Sdk\Requests\Tools\ShowToolRequest;
use Orbit\Sdk\Requests\Tools\UpdateToolRequest;
use Orbit\Sdk\Responses\Tools\ToolManagersResponse;
use Orbit\Sdk\Responses\Tools\ToolResponse;
use Orbit\Sdk\Responses\Tools\ToolsResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/** @mago-expect lint:halstead The six Tool request contracts stay visible together. */
describe('tool requests', function (): void {
    it('uses the exact six Tool methods, endpoints, and queries', function (
        GatewayRequest $request,
        Method $method,
        string $endpoint,
        array $query,
    ): void {
        expect($request->getMethod())
            ->toBe($method)
            ->and($request->resolveEndpoint())
            ->toBe($endpoint)
            ->and($request->query()->all())
            ->toBe($query);
    })->with([
        'manager list' => [
            new ListToolManagersRequest(12),
            Method::GET,
            '/api/v1/tool-managers',
            ['node_id' => 12],
        ],
        'tool list' => [new ListToolsRequest(12), Method::GET, '/api/v1/tools', ['node_id' => 12]],
        'show' => [new ShowToolRequest(41), Method::GET, '/api/v1/tools/41', []],
        'install' => [new InstallToolRequest(12, 'vp', '@openai/codex'), Method::POST, '/api/v1/tools', []],
        'update' => [new UpdateToolRequest(41), Method::POST, '/api/v1/tools/41/update', []],
        'remove' => [new RemoveToolRequest(41), Method::DELETE, '/api/v1/tools/41', []],
    ]);

    it('omits only a null version constraint from install bodies', function (): void {
        expect(new InstallToolRequest(12, 'vp', '@openai/codex')->body()->all())
            ->toBe([
                'node_id' => 12,
                'manager' => 'vp',
                'package' => '@openai/codex',
            ])
            ->and(new InstallToolRequest(12, 'vp', '@openai/codex', '^0.150')->body()->all())
            ->toBe([
                'node_id' => 12,
                'manager' => 'vp',
                'package' => '@openai/codex',
                'version_constraint' => '^0.150',
            ])
            ->and(new InstallToolRequest(12, 'vp', '@openai/codex', '')->body()->all())
            ->toBe([
                'node_id' => 12,
                'manager' => 'vp',
                'package' => '@openai/codex',
                'version_constraint' => '',
            ]);
    });

    it('keeps update and remove requests bodyless', function (GatewayRequest $request): void {
        $mockClient = new MockClient([MockResponse::make(['data' => []])]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);
        $connector->send($request);
        $pendingRequest = $mockClient->getLastPendingRequest();

        expect($request)
            ->not->toBeInstanceOf(HasBody::class)->and($pendingRequest?->body())->toBeNull()->and(
                $pendingRequest?->headers()->all(),
            )
            ->not->toHaveKey('Content-Type')->and(
                (string) $pendingRequest?->createPsrRequest()->getBody(),
            )->toBeEmpty();
    })->with([
        'update' => [new UpdateToolRequest(41)],
        'remove' => [new RemoveToolRequest(41)],
    ]);

    it('maps every operation to its typed DTO and preserves both request IDs', function (): void {
        $callerRequestId = '11111111-1111-4111-8111-111111111111';
        $responseRequestId = tool_request_id();

        foreach (tool_transport_cases($responseRequestId) as $case) {
            $request = $case['request'];
            $mockClient = new MockClient([
                $request::class => MockResponse::make($case['response'], $case['status']),
            ]);
            $connector = new GatewayConnector(
                'https://10.44.0.1',
                requestIdResolver: static fn (): string => $callerRequestId,
            );
            $connector->withMockClient($mockClient);
            $response = $connector->send($request)->dto();

            expect($mockClient->getLastPendingRequest()?->headers()->get('X-Orbit-Request-Id'))
                ->toBe($callerRequestId)
                ->and($response)
                ->toBeInstanceOf($case['response_class'])
                ->and($response->requestId)
                ->toBe($responseRequestId);
        }
    });

    it('rejects malformed Tool collection envelopes and members', function (
        GatewayRequest $request,
        mixed $data,
        string $exception,
    ): void {
        $mockClient = new MockClient([
            $request::class => MockResponse::make([
                'data' => $data,
                'meta' => ['request_id' => tool_request_id()],
            ]),
        ]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);

        expect(fn (): mixed => $connector->send($request)->dto())->toThrow($exception);
    })->with([
        'manager non-list envelope' => [new ListToolManagersRequest(12), ['name' => 'apt'], GatewayApiException::class],
        'tool scalar envelope' => [new ListToolsRequest(12), 'invalid', GatewayApiException::class],
        'manager scalar member' => [new ListToolManagersRequest(12), ['invalid'], GatewayApiException::class],
        'tool scalar member' => [new ListToolsRequest(12), [42], GatewayApiException::class],
        'manager numeric member key' => [
            new ListToolManagersRequest(12),
            [['id' => 1, 'node_id' => 12, 'name' => 'apt', 'status' => 'active', 0 => 'invalid']],
            GatewayApiException::class,
        ],
        'tool malformed member' => [new ListToolsRequest(12), [['id' => '41']], InvalidArgumentException::class],
    ]);
});

/**
 * @return list<array{
 *     request: GatewayRequest,
 *     response: array<string, mixed>,
 *     response_class: class-string,
 *     status: int
 * }>
 */
function tool_transport_cases(string $requestId): array
{
    $tool = tool_request_gateway_data();
    $manager = [
        'id' => 7,
        'node_id' => 12,
        'name' => 'vp',
        'status' => 'active',
        'installed_version' => '0.7.1',
        'failed_step' => null,
        'error_code' => null,
    ];

    return [
        [
            'request' => new ListToolManagersRequest(12),
            'response' => ['data' => [$manager], 'meta' => ['request_id' => $requestId]],
            'response_class' => ToolManagersResponse::class,
            'status' => 200,
        ],
        [
            'request' => new ListToolsRequest(12),
            'response' => ['data' => [$tool], 'meta' => ['request_id' => $requestId]],
            'response_class' => ToolsResponse::class,
            'status' => 200,
        ],
        [
            'request' => new ShowToolRequest(41),
            'response' => ['data' => $tool, 'meta' => ['request_id' => $requestId]],
            'response_class' => ToolResponse::class,
            'status' => 200,
        ],
        [
            'request' => new InstallToolRequest(12, 'vp', '@openai/codex', '^0.150'),
            'response' => ['data' => $tool, 'meta' => ['request_id' => $requestId]],
            'response_class' => ToolResponse::class,
            'status' => 201,
        ],
        [
            'request' => new UpdateToolRequest(41),
            'response' => ['data' => $tool, 'meta' => ['request_id' => $requestId]],
            'response_class' => ToolResponse::class,
            'status' => 200,
        ],
        [
            'request' => new RemoveToolRequest(41),
            'response' => ['data' => $tool, 'meta' => ['request_id' => $requestId]],
            'response_class' => ToolResponse::class,
            'status' => 200,
        ],
    ];
}

/** @return array<string, mixed> */
function tool_request_gateway_data(): array
{
    return [
        'id' => 41,
        'node_id' => 12,
        'manager' => 'vp',
        'package' => '@openai/codex',
        'version_constraint' => '^0.150',
        'protected' => false,
        'status' => 'installed',
        'installed_version' => '0.150.0',
        'failed_operation' => null,
        'error_code' => null,
        'outcome' => 'applied',
    ];
}

function tool_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
