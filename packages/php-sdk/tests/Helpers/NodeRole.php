<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Saloon\Http\Faking\MockClient;

function node_role_gateway_connector(MockClient $mockClient): GatewayConnector
{
    $connector = new GatewayConnector(
        'https://10.44.0.1',
        requestIdResolver: static fn (): string => '11111111-1111-4111-8111-111111111111',
    );
    $connector->withMockClient($mockClient);

    return $connector;
}

/** @return array<string, mixed> */
function node_role_added_gateway_data(): array
{
    return [
        'node_id' => 7,
        'node_name' => 'app-1',
        'role' => 'app-dev',
        'assignment' => [
            'id' => 34,
            'role' => 'app-dev',
            'status' => 'active',
            'failed_step' => null,
            'error_code' => null,
        ],
        'removed' => false,
    ];
}

/** @return array<string, mixed> */
function node_role_removed_gateway_data(): array
{
    return [
        'node_id' => 7,
        'node_name' => 'app-1',
        'role' => 'app-dev',
        'assignment' => null,
        'removed' => true,
    ];
}

/** @return array<string, mixed> */
function node_role_removed_degraded_gateway_data(): array
{
    return [
        'node_id' => 7,
        'node_name' => 'app-1',
        'role' => 'app-dev',
        'assignment' => null,
        'removed' => true,
        'degradation' => 'unreachable',
        'retained_on_node' => [
            'Caddy site configuration and certificates for the app-dev role',
        ],
        'follow_up' => 'Run the node-local Metrics cleanup on the node once it boots, or discard the node.',
    ];
}

function node_role_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}

function node_role_secret(string $label): string
{
    return "{$label}-".substr(hash('sha256', $label), offset: 0, length: 12);
}

/** @param list<string> $rejected */
function assert_node_role_boundary_exception(callable $callback, string $message, array $rejected = []): void
{
    try {
        $callback();
        test()->fail('Expected GatewayApiException.');
    } catch (GatewayApiException $exception) {
        $diagnostics = implode("\n", [
            $exception->getMessage(),
            (string) $exception,
            print_r($exception, return: true),
            (string) json_encode($exception->__debugInfo()),
        ]);

        expect($exception->getMessage())
            ->toBe($message)
            ->and($exception->requestId())
            ->toBe(node_role_request_id());

        foreach ($rejected as $value) {
            expect($diagnostics)->not->toContain($value);
        }
    }
}
