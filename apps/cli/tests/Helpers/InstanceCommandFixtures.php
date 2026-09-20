<?php

declare(strict_types=1);

use Saloon\Http\Faking\MockResponse;

/** @return array<string, mixed> */
function instance_payload(?array $removal = null): array
{
    return [
        'id' => 5,
        'app_id' => 3,
        'node_id' => 2,
        'app' => ['id' => 3, 'name' => 'Orbit docs', 'slug' => 'orbit-docs'],
        'node' => ['id' => 2, 'name' => 'beast'],
        'vite_port' => null,
        'name' => 'dev',
        'environment' => 'development',
        'source_layout' => 'checkout',
        'checkout_path' => '/home/orbit/apps/orbit-docs/dev',
        'production_user' => null,
        'production_home' => null,
        'root' => null,
        'effective_root' => 'public',
        'selected_branch' => 'dev',
        'branch_override' => null,
        'migration_required' => false,
        'starting_commit' => str_repeat('a', times: 40),
        'detached' => false,
        'status' => $removal === null ? 'active' : 'removing',
        'route' => instance_route_payload(),
        'domain' => 'dev.orbit.test',
        'url' => 'https://dev.orbit.test',
        'removal' => $removal,
        'transfer' => null,
        'deploy_steps' => [],
    ];
}

/** @return array<string, mixed> */
function registration_payload(): array
{
    $instance = [
        ...instance_payload(),
        'name' => 'default',
        'checkout_path' => '/home/orbit/apps/acme/default',
        'selected_branch' => 'main',
    ];

    return [
        'app' => [
            'id' => 3,
            'name' => 'acme',
            'slug' => 'acme',
            'repository_url' => 'git@github.com:acme/acme.git',
            'default_branch' => 'main',
            'root' => 'public',
            'defaults' => null,
        ],
        'app_instance' => $instance,
        'app_instances' => [$instance],
        'status' => 'active',
        'source_count' => 1,
        'completed_count' => 1,
    ];
}

function registration_mock_response(): MockResponse
{
    return MockResponse::make([
        'data' => registration_payload(),
        'meta' => ['request_id' => instance_request_id()],
    ]);
}

function registration_json(): string
{
    $data = registration_payload();
    $data['app']['request_id'] = instance_request_id();
    foreach (['app_instance', 'app_instances'] as $key) {
        if ($key === 'app_instance') {
            $data[$key] = [
                ...$data[$key],
                'route' => [...instance_route_payload(), 'request_id' => instance_request_id()],
                'request_id' => instance_request_id(),
            ];

            continue;
        }
        $data[$key] = array_map(static fn (array $row): array => [
            ...$row,
            'route' => [...instance_route_payload(), 'request_id' => instance_request_id()],
            'request_id' => instance_request_id(),
        ], $data[$key]);
    }
    $data['request_id'] = instance_request_id();

    return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/** @return array<string, mixed> */
function instance_route_payload(): array
{
    return [
        'id' => 8,
        'kind' => 'app',
        'app_id' => 3,
        'node_id' => 2,
        'cluster_id' => null,
        'generation_basis_node_id' => 2,
        'domain' => 'dev.orbit.test',
        'provenance' => 'generated',
        'publication' => 'private',
        'status' => 'active',
        'failed_step' => null,
        'error_code' => null,
        'replaces_route_id' => null,
        'replaced_by_route_id' => null,
        'replacement_step' => null,
        'target_set_step' => null,
        'target' => ['id' => 9, 'app_instance_id' => 5, 'position' => 0],
        'targets' => [['id' => 9, 'app_instance_id' => 5, 'position' => 0]],
        'process_id' => null,
        'upstream' => null,
    ];
}

function instance_mock_response(int $status = 200, ?array $payload = null): MockResponse
{
    return MockResponse::make([
        'data' => $payload ?? instance_payload(),
        'meta' => ['request_id' => instance_request_id()],
    ], $status);
}

function removal_mock_response(bool $force = false): MockResponse
{
    return MockResponse::make([
        'data' => removal_payload($force),
        'meta' => ['request_id' => instance_request_id()],
    ]);
}

/** @return array<string, mixed> */
function removal_payload(bool $force = false): array
{
    return [
        'operation_id' => '0198e15d-16c4-7855-8eb2-182b53ad28bb',
        'id' => 5,
        'name' => 'dev',
        'force' => $force,
        'status' => 'completed',
        'current_step' => null,
        'total' => 1,
        'completed' => 1,
        'remaining' => 0,
        'failed_step' => null,
        'error_code' => null,
    ];
}

/** @return array<string, mixed> */
function removal_progress_payload(bool $force = false): array
{
    return [
        'operation_id' => '0198e15d-16c4-7855-8eb2-182b53ad28bb',
        'id' => 5,
        'name' => 'dev',
        'force' => $force,
        'status' => 'failed',
        'current_step' => 'runtime_cleanup',
        'total' => 1,
        'completed' => 0,
        'remaining' => 1,
        'failed_step' => 'runtime_cleanup',
        'error_code' => 'instance.runtime_interrupted',
    ];
}

function instance_json(): string
{
    return json_encode([
        ...instance_payload(),
        'route' => [...instance_route_payload(), 'request_id' => instance_request_id()],
        'request_id' => instance_request_id(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function removal_json(): string
{
    return json_encode([
        ...removal_payload(),
        'request_id' => instance_request_id(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function instance_request_id(): string
{
    return '0198e15d-16c4-7855-8eb2-182b53ad28ba';
}
