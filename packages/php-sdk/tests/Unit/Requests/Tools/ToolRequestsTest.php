<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Tools\InstallToolRequest;
use Orbit\Sdk\Requests\Tools\ListToolManagersRequest;
use Orbit\Sdk\Requests\Tools\ListToolsRequest;
use Orbit\Sdk\Requests\Tools\RemoveToolRequest;
use Orbit\Sdk\Requests\Tools\ShowToolRequest;
use Orbit\Sdk\Requests\Tools\UpdateToolRequest;
use Orbit\Sdk\Responses\Tools\ToolResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('Tool requests', function (): void {
    it('uses the approved request method, endpoint, and query', function (
        string $requestClass,
        Method $method,
        string $endpoint,
        array $query,
    ): void {
        $request = str_contains($requestClass, 'List') ? new $requestClass(12) : new $requestClass(41);

        expect($request->getMethod())
            ->toBe($method)
            ->and($request->resolveEndpoint())
            ->toBe($endpoint)
            ->and($request->query()->all())
            ->toBe($query);
    })->with([
        'manager list' => [ListToolManagersRequest::class, Method::GET, '/api/v1/tool-managers', ['node_id' => 12]],
        'tool list' => [ListToolsRequest::class, Method::GET, '/api/v1/tools', ['node_id' => 12]],
        'show' => [ShowToolRequest::class, Method::GET, '/api/v1/tools/41', []],
        'update' => [UpdateToolRequest::class, Method::POST, '/api/v1/tools/41/update', []],
        'remove' => [RemoveToolRequest::class, Method::DELETE, '/api/v1/tools/41', []],
    ]);

    it('preserves omitted and non-null version constraint semantics', function (): void {
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
            ]);
    });

    it('preserves the caller request ID across every operation', function (): void {
        $requestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
        $requests = [
            new ListToolManagersRequest(12),
            new ListToolsRequest(12),
            new ShowToolRequest(41),
            new InstallToolRequest(12, 'vp', '@openai/codex'),
            new UpdateToolRequest(41),
            new RemoveToolRequest(41),
        ];

        foreach ($requests as $request) {
            $data = $request instanceof ListToolManagersRequest ? [tool_manager_gateway_data()] : tool_gateway_data();
            $data = $request instanceof ListToolsRequest ? [$data] : $data;
            $mock = new MockClient([MockResponse::make(['data' => $data, 'meta' => ['request_id' => $requestId]])]);
            $connector = new GatewayConnector(
                'https://gateway.test',
                requestIdResolver: static fn (): string => $requestId,
            );
            $connector->withMockClient($mock);
            $dto = $connector->send($request)->dto();

            expect($mock->getLastPendingRequest()?->headers()->get('X-Orbit-Request-Id'))
                ->toBe($requestId)
                ->and($dto->requestId)
                ->toBe($requestId);
        }
    });
});

describe('Tool response', function (): void {
    it('maps the approved gateway fields', function (): void {
        $requestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
        $tool = ToolResponse::fromGatewayData([
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
        ], $requestId);

        expect($tool->id)
            ->toBe(41)
            ->and($tool->nodeId)
            ->toBe(12)
            ->and($tool->manager)
            ->toBe('vp')
            ->and($tool->package)
            ->toBe('@openai/codex')
            ->and($tool->versionConstraint)
            ->toBe('^0.150')
            ->and($tool->protected)
            ->toBeFalse()
            ->and($tool->status)
            ->toBe('installed')
            ->and($tool->installedVersion)
            ->toBe('0.150.0')
            ->and($tool->failedOperation)
            ->toBeNull()
            ->and($tool->errorCode)
            ->toBeNull()
            ->and($tool->outcome)
            ->toBe('applied')
            ->and($tool->requestId)
            ->toBe($requestId);
    });

    it('redacts credential-shaped strings and rejects malformed scalar fields', function (): void {
        $credential = 'tool-response-secret';
        $data = tool_gateway_data();
        $data['package'] = "https://operator:{$credential}@packages.test/tool?token={$credential}";
        $tool = ToolResponse::fromGatewayData($data, '0198e15c-bf97-7c23-8f1f-61b8fe67a844');

        expect(json_encode($tool->toArray(), JSON_THROW_ON_ERROR))
            ->toContain('[REDACTED]')
            ->not->toContain($credential);

        $data['protected'] = 'false';

        expect(
            fn (): ToolResponse => ToolResponse::fromGatewayData(
                $data,
                '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
            ),
        )
            ->toThrow(UnexpectedValueException::class);
    });
});

/** @return array<string, mixed> */
function tool_gateway_data(): array
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

/** @return array<string, mixed> */
function tool_manager_gateway_data(): array
{
    return [
        'id' => 7,
        'node_id' => 12,
        'name' => 'vp',
        'status' => 'available',
        'installed_version' => '0.9.0',
        'failed_step' => null,
        'error_code' => null,
    ];
}
