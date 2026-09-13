<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\AppInstances\CloneAppInstanceRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe(CloneAppInstanceRequest::class, function (): void {
    it('posts the exact clone request and maps the target and sole preview Route', function (): void {
        $mock = new MockClient([
            CloneAppInstanceRequest::class => MockResponse::make(clone_instance_envelope(), 201),
        ]);
        $connector = clone_instance_connector($mock);
        $request = new CloneAppInstanceRequest(
            candidateId: 11,
            nodeId: 7,
            name: 'production',
            previewName: 'shop.com',
            branch: 'release',
            sqliteSourcePath: '/srv/candidate/database.sqlite',
        );

        $response = $connector->send($request)->dto();
        $pending = $mock->getLastPendingRequest();

        expect($request->getMethod())
            ->toBe(Method::POST)
            ->and($request->resolveEndpoint())
            ->toBe('/api/v1/instances/11/clone')
            ->and($request->body()->all())
            ->toBe([
                'node_id' => 7,
                'name' => 'production',
                'preview_name' => 'shop.com',
                'branch' => 'release',
                'sqlite_source_path' => '/srv/candidate/database.sqlite',
            ])
            ->and((string) $pending?->createPsrRequest()->getBody())
            ->toBe('{"node_id":7,"name":"production","preview_name":"shop.com","branch":"release","sqlite_source_path":"\\/srv\\/candidate\\/database.sqlite"}')
            ->and($pending?->headers()->get('Content-Type'))
            ->toBe('application/json')
            ->and($response)
            ->toBeInstanceOf(AppInstanceResponse::class)
            ->and($response->id)
            ->toBe(29)
            ->and($response->selectedBranch)
            ->toBe('release')
            ->and($response->route?->id)
            ->toBe(41)
            ->and($response->route?->hostname)
            ->toBe('shop.com.prod.orbit')
            ->and($response->route?->target?->appInstanceId)
            ->toBe(29)
            ->and($response->requestId)
            ->toBe(clone_instance_request_id());
    });

    it('preserves omission separately from every supplied optional string', function (string $branch, string $path): void {
        $omitted = new CloneAppInstanceRequest(11, 7, 'production', 'shop.com');
        $supplied = new CloneAppInstanceRequest(11, 7, 'production', 'shop.com', $branch, $path);

        expect($omitted->body()->all())
            ->toBe([
                'node_id' => 7,
                'name' => 'production',
                'preview_name' => 'shop.com',
            ])
            ->and($supplied->body()->all())
            ->toBe([
                'node_id' => 7,
                'name' => 'production',
                'preview_name' => 'shop.com',
                'branch' => $branch,
                'sqlite_source_path' => $path,
            ]);
    })->with([
        'empty values' => ['', ''],
        'relative values' => ['feature/production', 'database/app.sqlite'],
        'absolute SQLite path' => ['main', '/srv/app/database.sqlite'],
    ]);

    it('preserves a bounded correlated Gateway error without exposing a sensitive SQLite path', function (): void {
        $credential = 'clone-sdk-secret-72d0';
        $path = "/srv/candidate/token={$credential}/database.sqlite";
        $mock = new MockClient([
            CloneAppInstanceRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'instance.clone_sqlite_source_invalid',
                    'message' => 'The selected SQLite source is invalid.',
                    'details' => ['source' => $path],
                ],
            ], 422, ['X-Orbit-Request-Id' => clone_instance_request_id()]),
        ]);
        $request = new CloneAppInstanceRequest(11, 7, 'production', 'shop.com', null, $path);

        try {
            clone_instance_connector($mock)->send($request);
            $this->fail('Expected a GatewayApiException.');
        } catch (GatewayApiException $exception) {
            $diagnostics = implode("\n", [
                print_r($request, return: true),
                $exception->getMessage(),
                (string) $exception,
                json_encode($exception->details(), JSON_THROW_ON_ERROR),
            ]);

            expect($exception->errorCode())
                ->toBe('instance.clone_sqlite_source_invalid')
                ->and($exception->getMessage())
                ->toBe('The selected SQLite source is invalid.')
                ->and($exception->requestId())
                ->toBe(clone_instance_request_id())
                ->and($diagnostics)
                ->not->toContain($credential, $path);
        }

        $parameter = new ReflectionParameter([CloneAppInstanceRequest::class, '__construct'], 'sqliteSourcePath');
        expect($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1);
    });
});

function clone_instance_connector(MockClient $mock): GatewayConnector
{
    $connector = new GatewayConnector('https://gateway.test');
    $connector->withMockClient($mock);

    return $connector;
}

/** @return array<string, mixed> */
function clone_instance_envelope(): array
{
    return [
        'data' => [
            'id' => 29,
            'app_id' => 3,
            'node_id' => 7,
            'name' => 'production',
            'environment' => 'production',
            'source_layout' => 'release',
            'checkout_path' => '/home/orbit-app-29/releases/candidate',
            'production_user' => 'orbit-app-29',
            'production_home' => '/home/orbit-app-29',
            'root' => null,
            'effective_root' => '/home/orbit-app-29/current/public',
            'selected_branch' => 'release',
            'branch_override' => 'release',
            'migration_required' => false,
            'starting_commit' => str_repeat('a', 40),
            'detached' => false,
            'status' => 'active',
            'route' => [
                'id' => 41,
                'app_id' => 3,
                'node_id' => 7,
                'cluster_id' => null,
                'generation_basis_node_id' => null,
                'hostname' => 'shop.com.prod.orbit',
                'provenance' => 'explicit',
                'publication' => 'private',
                'status' => 'active',
                'failed_step' => null,
                'error_code' => null,
                'target' => [
                    'id' => 51,
                    'app_instance_id' => 29,
                    'position' => 1,
                ],
            ],
            'hostname' => 'shop.com.prod.orbit',
            'url' => 'https://shop.com.prod.orbit',
            'removal' => null,
        ],
        'meta' => ['request_id' => clone_instance_request_id()],
    ];
}

function clone_instance_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
