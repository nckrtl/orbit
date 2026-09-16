<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\AppInstances\TransferAppInstanceRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe(TransferAppInstanceRequest::class, function (): void {
    it('posts the exact transfer request and maps destination placement and transfer progress', function (): void {
        $mock = new MockClient([
            TransferAppInstanceRequest::class => MockResponse::make(transfer_instance_envelope(), 201),
        ]);
        $connector = transfer_instance_connector($mock);
        $request = new TransferAppInstanceRequest(
            instanceId: 11,
            nodeId: 8,
            name: 'preview',
            sqliteSourcePath: '/srv/source/database.sqlite',
        );

        $response = $connector->send($request)->dto();
        $pending = $mock->getLastPendingRequest();

        expect($request->getMethod())
            ->toBe(Method::POST)
            ->and($request->resolveEndpoint())
            ->toBe('/api/v1/instances/11/transfer')
            ->and($request->body()->all())
            ->toBe([
                'node_id' => 8,
                'name' => 'preview',
                'sqlite_source_path' => '/srv/source/database.sqlite',
            ])
            ->and((string) $pending?->createPsrRequest()->getBody())
            ->toBe('{"node_id":8,"name":"preview","sqlite_source_path":"\\/srv\\/source\\/database.sqlite"}')
            ->and($response)
            ->toBeInstanceOf(AppInstanceResponse::class)
            ->and($response->id)
            ->toBe(11)
            ->and($response->nodeId)
            ->toBe(8)
            ->and($response->name)
            ->toBe('preview')
            ->and($response->domain)
            ->toBe('preview.shop.other.orbit')
            ->and($response->transfer?->status)
            ->toBe('completed')
            ->and($response->transfer?->destinationPath)
            ->toBe('/srv/orbit/apps/shop/preview')
            ->and($response->transfer?->cleanupCompleted)
            ->toBeTrue()
            ->and($response->requestId)
            ->toBe(transfer_instance_request_id());
    });

    it('preserves omission separately from every supplied optional string', function (string $name, string $path): void {
        $omitted = new TransferAppInstanceRequest(11, 8);
        $supplied = new TransferAppInstanceRequest(11, 8, $name, $path);

        expect($omitted->body()->all())
            ->toBe(['node_id' => 8])
            ->and($supplied->body()->all())
            ->toBe([
                'node_id' => 8,
                'name' => $name,
                'sqlite_source_path' => $path,
            ]);
    })->with([
        'empty values' => ['', ''],
        'relative values' => ['preview', 'database/app.sqlite'],
        'absolute SQLite path' => ['preview', '/srv/app/database.sqlite'],
    ]);

    it('preserves a bounded correlated Gateway error without exposing a sensitive SQLite path', function (): void {
        $credential = 'transfer-sdk-secret-72d0';
        $path = "/srv/source/token={$credential}/database.sqlite";
        $mock = new MockClient([
            TransferAppInstanceRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'instance.destination_exists',
                    'message' => 'destination already exists. Retry with a different name to use another destination path.',
                    'details' => ['source' => $path],
                ],
            ], 409, ['X-Orbit-Request-Id' => transfer_instance_request_id()]),
        ]);
        $request = new TransferAppInstanceRequest(11, 8, null, $path);

        try {
            transfer_instance_connector($mock)->send($request);
            $this->fail('Expected a GatewayApiException.');
        } catch (GatewayApiException $exception) {
            $diagnostics = implode("\n", [
                print_r($request, return: true),
                $exception->getMessage(),
                (string) $exception,
                json_encode($exception->details(), JSON_THROW_ON_ERROR),
            ]);

            expect($exception->errorCode())
                ->toBe('instance.destination_exists')
                ->and($exception->getMessage())
                ->toContain('destination already exists')
                ->and($exception->requestId())
                ->toBe(transfer_instance_request_id())
                ->and($diagnostics)
                ->not->toContain($credential, $path);
        }

        $parameter = new ReflectionParameter([TransferAppInstanceRequest::class, '__construct'], 'sqliteSourcePath');
        expect($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1);
    });
});

function transfer_instance_connector(MockClient $mock): GatewayConnector
{
    $connector = new GatewayConnector('https://gateway.test');
    $connector->withMockClient($mock);

    return $connector;
}

/** @return array<string, mixed> */
function transfer_instance_envelope(): array
{
    return [
        'data' => [
            'id' => 11,
            'app_id' => 3,
            'node_id' => 8,
            'name' => 'preview',
            'environment' => 'development',
            'source_layout' => 'checkout',
            'checkout_path' => '/srv/orbit/apps/shop/preview',
            'production_user' => null,
            'production_home' => null,
            'root' => null,
            'effective_root' => 'public',
            'selected_branch' => 'main',
            'branch_override' => null,
            'migration_required' => false,
            'starting_commit' => str_repeat('a', 40),
            'detached' => true,
            'status' => 'active',
            'route' => [
                'id' => 41,
                'kind' => 'app',
                'app_id' => 3,
                'node_id' => null,
                'cluster_id' => 2,
                'generation_basis_node_id' => 8,
                'domain' => 'preview.shop.other.orbit',
                'provenance' => 'generated',
                'publication' => 'private',
                'public_publication' => 'inactive',
                'status' => 'active',
                'failed_step' => null,
                'error_code' => null,
                'replaces_route_id' => null,
                'replaced_by_route_id' => null,
                'replacement_step' => null,
                'target' => [
                    'id' => 51,
                    'app_instance_id' => 11,
                    'position' => 0,
                ],
                'process_id' => null,
                'upstream' => null,
            ],
            'domain' => 'preview.shop.other.orbit',
            'url' => 'https://preview.shop.other.orbit',
            'removal' => null,
            'transfer' => [
                'operation_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a846',
                'id' => 11,
                'source_node_id' => 7,
                'destination_node_id' => 8,
                'destination_name' => 'preview',
                'destination_path' => '/srv/orbit/apps/shop/preview',
                'destination_domain' => 'preview.shop.other.orbit',
                'sqlite_selected' => true,
                'status' => 'completed',
                'current_step' => 'completed',
                'cutover_completed' => true,
                'cleanup_completed' => true,
                'failed_step' => null,
                'error_code' => null,
                'recovery_evidence' => null,
            ],
            'deploy_steps' => [],
        ],
        'meta' => ['request_id' => transfer_instance_request_id()],
    ];
}

function transfer_instance_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a847';
}
