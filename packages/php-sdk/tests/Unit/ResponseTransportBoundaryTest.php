<?php

declare(strict_types=1);

use Orbit\Sdk\Responses\Activities\ActivityResponse;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;
use Orbit\Sdk\Responses\Apps\AppResponse;
use Orbit\Sdk\Responses\Doctor\DoctorFamilyResponse;
use Orbit\Sdk\Responses\Doctor\DoctorIssueResponse;
use Orbit\Sdk\Responses\Doctor\DoctorNodeResponse;
use Orbit\Sdk\Responses\Doctor\DoctorReportResponse;
use Orbit\Sdk\Responses\Firewall\FirewallRuleResponse;
use Orbit\Sdk\Responses\Nodes\AddedNodeAccessResponse;
use Orbit\Sdk\Responses\Nodes\NodeAccessNodeResponse;
use Orbit\Sdk\Responses\Nodes\NodeAccessResponse;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\RemovedNodeAccessResponse;
use Orbit\Sdk\Responses\Nodes\RemovedNodeResponse;
use Orbit\Sdk\Responses\Processes\ProcessResponse;
use Orbit\Sdk\Responses\Tools\ToolManagerResponse;
use Orbit\Sdk\Responses\Tools\ToolManagersResponse;
use Orbit\Sdk\Responses\Tools\ToolResponse;
use Orbit\Sdk\Responses\Tools\ToolsResponse;
use Orbit\Sdk\Responses\Workspaces\WorkspaceResponse;

it('rejects unsafe success error codes across every response surface', function (): void {
    $credential = substr(hash('sha256', __METHOD__), offset: 0, length: 20);
    $unsafeCode = "token={$credential}\r\nX-Orbit-Control: {$credential}";
    $requestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
    $responses = [
        ActivityResponse::fromGatewayData(['error_code' => $unsafeCode], $requestId),
        FirewallRuleResponse::fromGatewayData(['error_code' => $unsafeCode], $requestId),
        AppInstanceResponse::fromGatewayData(['error_code' => $unsafeCode], $requestId),
        NodeResponse::fromGatewayData(['error_code' => $unsafeCode], $requestId),
        ProcessResponse::fromGatewayData(['error_code' => $unsafeCode], $requestId),
        WorkspaceResponse::fromGatewayData(['error_code' => $unsafeCode], $requestId),
        ToolManagerResponse::fromGatewayData([
            'id' => 1,
            'node_id' => 1,
            'name' => 'composer',
            'status' => 'ready',
            'error_code' => $unsafeCode,
        ], $requestId),
        ToolResponse::fromGatewayData([
            'id' => 1,
            'node_id' => 1,
            'manager' => 'composer',
            'package' => 'vendor/package',
            'protected' => false,
            'status' => 'installed',
            'error_code' => $unsafeCode,
        ], $requestId),
    ];

    foreach ($responses as $response) {
        $diagnostics = implode("\n", [
            print_r($response, return: true),
            serialize($response),
            (string) json_encode($response->toArray(), JSON_THROW_ON_ERROR),
        ]);

        expect($response->errorCode)->toBeNull();
        expect($diagnostics)->not->toContain($credential, $unsafeCode);
    }
});

it('preserves valid success error codes', function (): void {
    $response = NodeResponse::fromGatewayData(
        ['error_code' => 'vpn.server_config_invalid'],
        '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
    );

    expect($response->errorCode)->toBe('vpn.server_config_invalid');
});

it('preserves every app default accepted by the Gateway array contract', function (): void {
    $defaults = [
        ['name' => 'worker'],
        'php_version' => '8.5',
    ];
    $response = AppResponse::fromGatewayData(
        ['defaults' => $defaults],
        '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
    );

    expect($response->defaults)
        ->toBe($defaults)
        ->and($response->toArray()['defaults'])
        ->toBe($defaults);
});

it('redacts credentials from nested success payloads and response diagnostics', function (): void {
    $credential = substr(hash('sha256', __FILE__), offset: 0, length: 20);
    $credentialUrl = "https://operator:{$credential}@git.example.test/orbit.git?access_token={$credential}";
    $requestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
    $responses = [
        AppResponse::fromGatewayData([
            'repository_url' => $credentialUrl,
            'defaults' => [
                ['api_token' => $credential],
                'repository_url' => $credentialUrl,
            ],
        ], $requestId),
        ActivityResponse::fromGatewayData([
            'properties' => ['defaults' => ['api_token' => $credential]],
        ], $requestId),
        ProcessResponse::fromGatewayData([
            'runtime_config' => ['repository_url' => $credentialUrl],
        ], $requestId),
    ];

    foreach ($responses as $response) {
        $diagnostics = implode("\n", [
            print_r($response, return: true),
            serialize($response),
            (string) json_encode($response->toArray(), JSON_THROW_ON_ERROR),
        ]);

        expect($diagnostics)
            ->toContain('[REDACTED]')
            ->not->toContain($credential, $credentialUrl);
    }
});

it('marks every public gateway DTO factory ingress as sensitive', function (): void {
    $responseFactories = [
        ActivityResponse::class => ['fromGatewayData'],
        AppResponse::class => ['fromGatewayData'],
        DoctorFamilyResponse::class => ['fromGatewayData'],
        DoctorIssueResponse::class => ['fromGatewayData'],
        DoctorNodeResponse::class => ['fromGatewayData'],
        DoctorReportResponse::class => ['fromGatewayData'],
        FirewallRuleResponse::class => ['fromGatewayData'],
        AppInstanceResponse::class => ['fromGatewayData'],
        AddedNodeAccessResponse::class => ['fromGatewayData'],
        NodeAccessNodeResponse::class => ['tryFromGatewayData'],
        NodeAccessResponse::class => ['fromGatewayData'],
        NodeResponse::class => ['fromGatewayData'],
        RemovedNodeAccessResponse::class => ['fromGatewayData'],
        RemovedNodeResponse::class => ['fromGatewayData'],
        ProcessResponse::class => ['fromGatewayData'],
        WorkspaceResponse::class => ['fromGatewayData'],
        ToolManagerResponse::class => ['fromGatewayData'],
        ToolManagersResponse::class => ['__construct'],
        ToolResponse::class => ['fromGatewayData'],
        ToolsResponse::class => ['__construct'],
    ];

    foreach ($responseFactories as $responseClass => $methodNames) {
        foreach ($methodNames as $methodName) {
            $method = new ReflectionMethod($responseClass, $methodName);

            foreach ($method->getParameters() as $parameter) {
                expect($parameter->getAttributes(SensitiveParameter::class))
                    ->toHaveCount(
                        1,
                        "{$responseClass}::{$methodName} $".$parameter->getName().' is not sensitive.',
                    );
            }
        }
    }
});

it('does not retain Doctor credentials in state or SDK trace arguments', function (): void {
    $credential = 'doctor-response-transport-credential';
    $data = [
        'healthy' => false,
        'nodes' => [[
            'node_id' => 7,
            'node_name' => "token={$credential}",
            'healthy' => false,
            'families' => [[
                'family' => 'node',
                'status' => 'drift',
                'checked' => 1,
                'issues' => [[
                    'code' => "token={$credential}",
                    'kind' => 'drift',
                    'resource_type' => 'node',
                    'resource_id' => "token={$credential}",
                    'resource_name' => "token={$credential}",
                    'summary' => "token={$credential}",
                    'expected' => "token={$credential}",
                    'observed' => "token={$credential}",
                ]],
            ]],
        ]],
        'summary' => ['nodes' => 1, 'families' => 1, 'checks' => 1, 'drift' => 1, 'unverifiable' => 0],
    ];
    $response = DoctorReportResponse::fromGatewayData($data, '');
    $diagnostics = implode("\n", [
        print_r($response, return: true),
        serialize($response),
        json_encode($response->toArray(), JSON_THROW_ON_ERROR),
    ]);

    expect($diagnostics)->toContain('[REDACTED]');
    expect($diagnostics)->not->toContain($credential);

    try {
        /** @phpstan-ignore argument.type */
        DoctorReportResponse::fromGatewayData($data, ['request_id' => $credential]);
        $this->fail('Expected malformed request ID rejection.');
    } catch (TypeError $exception) {
        $sdkTrace = array_values(array_filter(
            $exception->getTrace(),
            static fn (array $frame): bool => (
                is_string($frame['class'] ?? null) && str_starts_with($frame['class'], 'Orbit\\Sdk\\')
            ),
        ));
        expect(print_r($sdkTrace, return: true))->toContain('SensitiveParameterValue');
        expect(print_r($sdkTrace, return: true))->not->toContain($credential);
    }
});

it('does not retain malformed response arguments in SDK-owned trace frames', function (): void {
    $credential = substr(hash('sha256', __FUNCTION__), offset: 0, length: 20);
    $data = ['defaults' => ['api_token' => $credential]];

    try {
        /** @phpstan-ignore argument.type */
        AppResponse::fromGatewayData($data, ['request_id' => $credential]);
        $this->fail('Expected malformed request ID rejection.');
    } catch (TypeError $exception) {
        $sdkTrace = print_r(
            array_values(array_filter(
                $exception->getTrace(),
                static fn (array $frame): bool => (
                    is_string($frame['class'] ?? null) && str_starts_with($frame['class'], 'Orbit\\Sdk\\')
                ),
            )),
            return: true,
        );

        expect($sdkTrace)
            ->toContain('SensitiveParameterValue')
            ->not->toContain($credential);
    }
});
