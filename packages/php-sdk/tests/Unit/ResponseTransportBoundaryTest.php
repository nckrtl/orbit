<?php

declare(strict_types=1);

use Orbit\Sdk\Responses\Activities\ActivityResponse;
use Orbit\Sdk\Responses\Apps\AppResponse;
use Orbit\Sdk\Responses\Firewall\FirewallRuleResponse;
use Orbit\Sdk\Responses\Instances\InstanceResponse;
use Orbit\Sdk\Responses\Nodes\AddedNodeAccessResponse;
use Orbit\Sdk\Responses\Nodes\NodeAccessNodeResponse;
use Orbit\Sdk\Responses\Nodes\NodeAccessResponse;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\RemovedNodeAccessResponse;
use Orbit\Sdk\Responses\Nodes\RemovedNodeResponse;
use Orbit\Sdk\Responses\Processes\ProcessResponse;
use Orbit\Sdk\Responses\Workspaces\WorkspaceResponse;

it('rejects unsafe success error codes across every response surface', function (): void {
    $credential = substr(hash('sha256', __METHOD__), offset: 0, length: 20);
    $unsafeCode = "token={$credential}\r\nX-Orbit-Control: {$credential}";
    $requestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
    $responses = [
        ActivityResponse::fromGatewayData(['error_code' => $unsafeCode], $requestId),
        FirewallRuleResponse::fromGatewayData(['error_code' => $unsafeCode], $requestId),
        InstanceResponse::fromGatewayData(['error_code' => $unsafeCode], $requestId),
        NodeResponse::fromGatewayData(['error_code' => $unsafeCode], $requestId),
        ProcessResponse::fromGatewayData(['error_code' => $unsafeCode], $requestId),
        WorkspaceResponse::fromGatewayData(['error_code' => $unsafeCode], $requestId),
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
        FirewallRuleResponse::class => ['fromGatewayData'],
        InstanceResponse::class => ['fromGatewayData'],
        AddedNodeAccessResponse::class => ['fromGatewayData'],
        NodeAccessNodeResponse::class => ['tryFromGatewayData'],
        NodeAccessResponse::class => ['fromGatewayData'],
        NodeResponse::class => ['fromGatewayData'],
        RemovedNodeAccessResponse::class => ['fromGatewayData'],
        RemovedNodeResponse::class => ['fromGatewayData'],
        ProcessResponse::class => ['fromGatewayData'],
        WorkspaceResponse::class => ['fromGatewayData'],
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
