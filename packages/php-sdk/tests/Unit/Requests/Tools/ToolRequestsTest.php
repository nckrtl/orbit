<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Tools\InstallToolRequest;
use Orbit\Sdk\Requests\Tools\ListToolManagersRequest;
use Orbit\Sdk\Requests\Tools\ListToolsRequest;
use Orbit\Sdk\Requests\Tools\RemoveToolRequest;
use Orbit\Sdk\Requests\Tools\ScanToolInventoryRequest;
use Orbit\Sdk\Requests\Tools\ShowToolRequest;
use Orbit\Sdk\Requests\Tools\UpdateToolRequest;
use Orbit\Sdk\Responses\Tools\ToolInventoryResponse;
use Orbit\Sdk\Responses\Tools\ToolManagersResponse;
use Orbit\Sdk\Responses\Tools\ToolResponse;
use Orbit\Sdk\Responses\Tools\ToolsResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('tool requests', function (): void {
    it('uses the exact Tool methods, endpoints, and queries', function (
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
        'inventory scan' => [
            new ScanToolInventoryRequest(12),
            Method::GET,
            '/api/v1/tool-inventory',
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

    it('keeps read and removal requests bodyless', function (GatewayRequest $request): void {
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
        'scan' => [new ScanToolInventoryRequest(12)],
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
        'inventory list envelope' => [new ScanToolInventoryRequest(12), [], GatewayApiException::class],
        'inventory scalar envelope' => [new ScanToolInventoryRequest(12), 'invalid', GatewayApiException::class],
        'inventory manager scalar' => [new ScanToolInventoryRequest(12), tool_inventory_request_data(['managers' => ['invalid']]), GatewayApiException::class],
        'inventory package scalar' => [
            new ScanToolInventoryRequest(12),
            tool_inventory_request_data(['managers' => [[
                'manager' => 'brew',
                'scan_state' => 'complete',
                'packages' => ['ripgrep'],
            ]]]),
            GatewayApiException::class,
        ],
        'inventory malformed package' => [
            new ScanToolInventoryRequest(12),
            tool_inventory_request_data(['managers' => [[
                'manager' => 'brew',
                'scan_state' => 'complete',
                'packages' => [[
                    'manager' => 'brew',
                    'package' => 'ripgrep',
                    'package_kind' => 'formula',
                    'installed_version' => '14.1.1',
                    'dependency' => 'yes',
                    'registered' => false,
                    'tool_id' => null,
                    'adoption' => 'supported',
                    'adoption_block' => null,
                ]],
            ]]]),
            InvalidArgumentException::class,
        ],
    ]);

    it('preserves recorded authorization and node-eligibility scan errors', function (string $fixture): void {
        $recorded = tool_scan_error_fixture($fixture);
        $error = $recorded['body']['error'];
        $mockClient = new MockClient([
            ScanToolInventoryRequest::class => MockResponse::make($recorded['body'], $recorded['status']),
        ]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);

        try {
            $connector->send(new ScanToolInventoryRequest(12))->dto();
            $this->fail('Expected GatewayApiException.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())
                ->toBe($error['code'])
                ->and($exception->getMessage())
                ->toBe($error['message'])
                ->and($exception->details())
                ->toBe($error['details'])
                ->and($exception->details())
                ->not->toHaveKey('id');

            if ($error['code'] === 'node_access.required') {
                expect($exception->details()['consumer_node'])
                    ->toBe(['id' => 3, 'name' => 'scan-consumer'])
                    ->and($exception->details()['serving_node'])
                    ->toBe(['id' => 2, 'name' => 'scan-target']);
            }
        }
    })->with([
        'access required' => ['access-required'],
        'inactive node' => ['node-inactive'],
        'unmanaged node' => ['node-unmanaged'],
    ]);

    it('preserves a persisted tool id from a version-probe install failure', function (): void {
        $requestId = tool_request_id();
        $mockClient = new MockClient([
            InstallToolRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'tool.version_probe_failed',
                        'message' => 'The tool manager operation failed.',
                        'details' => [
                            'step' => 'install',
                            'outcome' => 'manager_failed',
                            'id' => 110,
                        ],
                    ],
                ],
                502,
                ['X-Orbit-Request-Id' => $requestId],
            ),
        ]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);

        try {
            $connector->send(new InstallToolRequest(12, 'brew', 'totally-fake'))->dto();
            $this->fail('Expected GatewayApiException.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())
                ->toBe('tool.version_probe_failed')
                ->and($exception->details())
                ->toBe([
                    'step' => 'install',
                    'outcome' => 'manager_failed',
                    'id' => 110,
                ])
                ->and($exception->requestId())
                ->toBe($requestId);
        }
    });
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
            'request' => new ScanToolInventoryRequest(12),
            'response' => [
                'data' => tool_inventory_request_data(),
                'meta' => ['request_id' => $requestId],
            ],
            'response_class' => ToolInventoryResponse::class,
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

/** @return array{status: int, body: array{error: array{code: string, message: string, details: array<string, mixed>}}} */
function tool_scan_error_fixture(string $name): array
{
    $path = dirname(__DIR__, 4)."/fixtures/tools/tool-scan/{$name}.json";
    $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($fixture) || ! is_array($fixture['body']['error'] ?? null)) {
        throw new RuntimeException("Tool scan fixture {$name} is unreadable.");
    }

    /** @var array{status: int, body: array{error: array{code: string, message: string, details: array<string, mixed>}}} $fixture */
    return $fixture;
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function tool_inventory_request_data(array $overrides = []): array
{
    return array_replace([
        'node_id' => 12,
        'observed_at' => '2026-04-26T12:00:00+00:00',
        'managers' => [
            [
                'manager' => 'brew',
                'scan_state' => 'complete',
                'packages' => [[
                    'manager' => 'brew',
                    'package' => 'ripgrep',
                    'package_kind' => 'formula',
                    'installed_version' => '14.1.1',
                    'dependency' => false,
                    'registered' => true,
                    'tool_id' => 2,
                    'adoption' => 'supported',
                    'adoption_block' => null,
                ]],
            ],
            [
                'manager' => 'brew-cask',
                'scan_state' => 'unsupported',
                'packages' => [],
            ],
            [
                'manager' => 'vp',
                'scan_state' => 'absent',
                'packages' => [],
            ],
        ],
    ], $overrides);
}
