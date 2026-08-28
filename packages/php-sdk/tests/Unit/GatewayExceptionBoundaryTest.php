<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Gateway\ShowGatewayStatusRequest;
use Orbit\Sdk\Requests\Tools\InstallToolRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/** @mago-expect lint:halstead Security-boundary assertions stay visible together. */
describe('gateway exception boundary', function (): void {
    it('preserves Tool error outcomes through the transport boundary', function (
        string $errorCode,
        string $outcome,
    ): void {
        $credential = gateway_boundary_credential($errorCode);
        $requestId = '11111111-1111-4111-8111-111111111111';
        $mockClient = new MockClient([
            InstallToolRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => $errorCode,
                        'message' => "Tool install failed with password={$credential}",
                        'details' => [
                            'step' => 'install',
                            'outcome' => $outcome,
                        ],
                    ],
                ],
                422,
                ['X-Orbit-Request-Id' => $requestId],
            ),
        ]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mockClient);

        try {
            $connector->send(new InstallToolRequest(12, 'vp', '@openai/codex', '^0.150'))->dto();
            $this->fail('Expected a Tool GatewayApiException.');
        } catch (GatewayApiException $exception) {
            $diagnostics = implode("\n", [
                $exception->getMessage(),
                (string) $exception,
                print_r($exception, return: true),
                gateway_owned_trace_output($exception),
            ]);

            expect($exception->errorCode())
                ->toBe($errorCode)
                ->and($exception->requestId())
                ->toBe($requestId)
                ->and($exception->getMessage())
                ->toBe('Tool install failed with password=[REDACTED]')
                ->and($exception->details())
                ->toBe([
                    'step' => 'install',
                    'outcome' => $outcome,
                ])
                ->and($diagnostics)
                ->not->toContain($credential);
        }
    })->with([
        'invalid constraint' => ['tool.constraint_invalid', 'constraint_invalid'],
        'unparseable candidate' => ['tool.candidate_version_unparseable', 'candidate_version_unparseable'],
        'blocked candidate' => ['tool.version_constraint_blocked', 'blocked_by_constraint'],
        'unparseable installed version' => ['tool.installed_version_unparseable', 'manager_failed'],
        'installed version outside constraint' => [
            'tool.installed_version_constraint_violated',
            'manager_failed',
        ],
    ]);

    it('drops unsafe identifiers and removes credentials from SDK-owned state and traces', function (): void {
        $messageCredential = gateway_boundary_credential('message');
        $codeCredential = gateway_boundary_credential('code');
        $requestIdCredential = gateway_boundary_credential('request-id');
        $urlCredential = gateway_boundary_credential('url');
        $queryCredential = gateway_boundary_credential('query');
        $nestedCredential = gateway_boundary_credential('nested');
        $transportCredential = gateway_boundary_credential('transport');
        $mockClient = new MockClient([
            ShowGatewayStatusRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => "gateway.password={$codeCredential}",
                        'message' => "Gateway failed with password={$messageCredential}",
                        'details' => [
                            'repository_url' => "https://alice:{$urlCredential}@example.test/orbit.git?token={$queryCredential}",
                            'defaults' => [
                                'services' => [
                                    ['name' => 'gateway', 'token' => $nestedCredential],
                                ],
                            ],
                        ],
                    ],
                ],
                503,
                ['X-Orbit-Request-Id' => "request-token={$requestIdCredential}"],
            ),
        ]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mockClient);

        try {
            $connector->send(new ShowGatewayStatusRequest)->dto();
            $this->fail('Expected GatewayApiException.');
        } catch (GatewayApiException $exception) {
            $response = $mockClient->getLastResponse();
            $responseBody = $response?->body();

            if ($response === null || ! is_string($responseBody)) {
                $this->fail('Expected a recorded gateway response.');
            }

            $translated = new ShowGatewayStatusRequest()->getRequestException(
                $response,
                new RuntimeException("Connection failed for https://alice:{$transportCredential}@example.test."),
            );

            expect($translated)->toBeInstanceOf(GatewayApiException::class);

            if (! $translated instanceof GatewayApiException) {
                $this->fail('Expected a translated GatewayApiException.');
            }

            $redacted = '[REDACTED]';
            $expectedDetails = [
                'repository_url' => 'https://[REDACTED]@example.test/orbit.git?token=[REDACTED]',
                'defaults' => [
                    'services' => [
                        ['name' => 'gateway', 'token' => $redacted],
                    ],
                ],
            ];
            $diagnostics = implode('\n', [
                $exception->getMessage(),
                (string) json_encode($exception->details()),
                (string) $exception,
                print_r($exception, return: true),
                (string) json_encode($exception->__debugInfo()),
                gateway_owned_trace_output($exception),
                $translated->getPrevious()?->getMessage() ?? '',
                gateway_owned_trace_output($translated),
            ]);

            expect(fn (): string => serialize($exception))
                ->toThrow(Exception::class, "Serialization of 'SensitiveParameterValue' is not allowed");

            expect($exception->errorCode())
                ->toBeNull()
                ->and($exception->requestId())
                ->toBeNull()
                ->and($exception->getMessage())
                ->toBe('Gateway failed with password=[REDACTED]')
                ->and($exception->details())
                ->toBe($expectedDetails)
                ->and($exception->__debugInfo())
                ->toBe([
                    'message' => 'Gateway failed with password=[REDACTED]',
                    'error_code' => null,
                    'details' => $expectedDetails,
                    'request_id' => null,
                    'previous' => null,
                ])
                ->and($translated->getPrevious()?->getMessage())
                ->toBe('Connection failed for https://[REDACTED]@example.test.')
                ->and($diagnostics)
                ->toContain('SensitiveParameterValue')
                ->not->toContain(
                    $messageCredential,
                    $codeCredential,
                    $requestIdCredential,
                    $urlCredential,
                    $queryCredential,
                    $nestedCredential,
                    $transportCredential,
                    $responseBody,
                );
        }
    });

    it('does not retain a malformed response body in SDK-owned traces', function (): void {
        $credential = gateway_boundary_credential('malformed-body');
        $mockClient = new MockClient([
            ShowGatewayStatusRequest::class => MockResponse::make("invalid password={$credential}", 200),
        ]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mockClient);

        try {
            $connector->send(new ShowGatewayStatusRequest)->dto();
            $this->fail('Expected GatewayApiException.');
        } catch (GatewayApiException $exception) {
            expect($exception->getMessage())
                ->toBe('Gateway response is not valid JSON.')
                ->and(gateway_owned_trace_output($exception))
                ->toContain('SensitiveParameterValue')
                ->not->toContain($credential, "invalid password={$credential}");
        }
    });

    it('redacts a CA bundle path from sender exception diagnostics', function (): void {
        $credential = gateway_boundary_credential('ca-path');
        $caPemPath = "/private/{$credential}/gateway-root.pem";
        $mockClient = new MockClient([
            ShowGatewayStatusRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'gateway.unavailable',
                    'message' => 'The gateway is unavailable.',
                    'details' => [],
                ],
            ], 503),
        ]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mockClient);

        try {
            $connector->send(new ShowGatewayStatusRequest);
            $this->fail('Expected GatewayApiException.');
        } catch (GatewayApiException) {
            $response = $mockClient->getLastResponse();

            if ($response === null) {
                $this->fail('Expected a recorded gateway response.');
            }

            $translated = new ShowGatewayStatusRequest()->getRequestException(
                $response,
                new RuntimeException("SSL CA bundle not found: {$caPemPath}"),
            );

            expect($translated)
                ->toBeInstanceOf(GatewayApiException::class);

            if (! $translated instanceof GatewayApiException) {
                $this->fail('Expected a translated GatewayApiException.');
            }

            $diagnostics = implode("\n", [
                $translated->getPrevious()?->getMessage() ?? '',
                (string) $translated,
                print_r($translated, return: true),
                gateway_owned_trace_output($translated),
            ]);

            expect($translated->getPrevious()?->getMessage())
                ->toBe('SSL CA bundle not found: [REDACTED]')
                ->and($diagnostics)
                ->not->toContain($credential, $caPemPath);
        }
    });

    it('redacts credential variants from structured details and diagnostic text', function (): void {
        $appKey = gateway_boundary_credential('app-key');
        $apiToken = gateway_boundary_credential('api-token');
        $password = gateway_boundary_credential('password');
        $privateKey = gateway_boundary_credential('private-key');
        $confirmation = gateway_boundary_credential('confirmation');
        $authorization = gateway_boundary_credential('authorization');
        $bearer = 'tiny';
        $pem = gateway_boundary_credential('pem');
        $exception = new GatewayApiException(
            'Safe gateway failure.',
            details: [
                'APP_KEY' => $appKey,
                'API_TOKEN' => $apiToken,
                'PASSWORD' => $password,
                'PRIVATE_KEY' => $privateKey,
                'password_confirmation' => $confirmation,
                'authorization_debug' => "Authorization: Digest username=alice, realm=orbit, response={$authorization}",
                'bearer_debug' => "Bearer {$bearer}",
                'pem_debug' => "-----BEGIN PRIVATE KEY-----\n{$pem}",
            ],
        );
        $diagnostics = implode('\n', [
            (string) json_encode($exception->details()),
            (string) $exception,
            print_r($exception, return: true),
            (string) json_encode($exception->__debugInfo()),
            gateway_owned_trace_output($exception),
        ]);

        expect($diagnostics)
            ->not->toContain(
            $appKey,
            $apiToken,
            $password,
            $privateKey,
            $confirmation,
            $authorization,
            $bearer,
            $pem,
        );
    });

    it('uses null for an invalid error code', function (?string $errorCode): void {
        $exception = new GatewayApiException('Safe gateway failure.', errorCode: $errorCode);

        expect($exception->errorCode())->toBeNull();
    })->with([
        'empty' => [''],
        'upper-case' => ['Gateway.Unavailable'],
        'spaces' => ['gateway unavailable'],
        'control data' => ["gateway.unavailable\nInjected"],
        'credential assignment' => ['gateway.password=credential'],
        'over 128 bytes' => [str_repeat('a', times: 129)],
    ]);

    it('redacts credentials from recursive detail keys without unsafe collisions', function (): void {
        $credential = substr(hash('sha256', __METHOD__), offset: 0, length: 20);
        $exception = new GatewayApiException(
            'Safe gateway failure.',
            details: [
                "token={$credential}" => 'first',
                "token={$credential}-duplicate" => 'second',
                'nested' => [
                    "https://operator:{$credential}@gateway.test" => 'value',
                ],
            ],
        );
        $diagnostics = implode("\n", [
            (string) json_encode($exception->details(), JSON_THROW_ON_ERROR),
            print_r($exception, return: true),
            (string) $exception,
        ]);

        expect($diagnostics)
            ->toContain('[REDACTED]')
            ->not
            ->toContain($credential)
            ->and($exception->details())
            ->toHaveCount(2);
    });

    it('preserves preview dependents while redacting nested secret-shaped details', function (): void {
        $credential = gateway_boundary_credential('preview-detail');
        $secretKey = 'api_token';
        $exception = new GatewayApiException(
            'Use --force to remove this node role.',
            errorCode: 'validation.failed',
            details: [
                'field' => 'force',
                'reason' => 'destructive_consent_required',
                'role' => 'app-dev',
                'dependents' => [
                    '1 development instance record',
                    '1 workspace record',
                    '1 process record',
                ],
                'nested' => [
                    $secretKey => $credential,
                ],
            ],
            requestId: '0198e15d-16c4-7855-8eb2-182b53ad28ba',
        );
        $diagnostics = implode("\n", [
            (string) json_encode($exception->details(), JSON_THROW_ON_ERROR),
            (string) $exception,
            print_r($exception, return: true),
            (string) json_encode($exception->__debugInfo(), JSON_THROW_ON_ERROR),
            gateway_owned_trace_output($exception),
        ]);

        expect($exception->details())
            ->toBe([
                'field' => 'force',
                'reason' => 'destructive_consent_required',
                'role' => 'app-dev',
                'dependents' => [
                    '1 development instance record',
                    '1 workspace record',
                    '1 process record',
                ],
                'nested' => [
                    $secretKey => '[REDACTED]',
                ],
            ])
            ->and($diagnostics)
            ->not->toContain($credential);
    });

    it('uses null for an invalid request ID', function (?string $requestId): void {
        $exception = new GatewayApiException('Safe gateway failure.', requestId: $requestId);

        expect($exception->requestId())->toBeNull();
    })->with([
        'empty' => [''],
        'malformed' => ['not-a-uuid'],
        'control data' => ["0198e15c-bf97-7c23-8f1f-61b8fe67a844\nInjected"],
        'credential assignment' => ['request-token=credential'],
        'invalid version' => ['0198e15c-bf97-0c23-8f1f-61b8fe67a844'],
        'invalid variant' => ['0198e15c-bf97-7c23-1f1f-61b8fe67a844'],
    ]);

    it('preserves valid contract identifiers', function (string $errorCode, string $requestId): void {
        $exception = new GatewayApiException(
            'Safe gateway failure.',
            errorCode: $errorCode,
            requestId: $requestId,
        );

        expect($exception->errorCode())
            ->toBe($errorCode)
            ->and($exception->requestId())
            ->toBe($requestId)
            ->and((string) $exception)
            ->toContain($errorCode, $requestId);
    })->with([
        'gateway UUID v7' => ['gateway.unavailable', '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        'client UUID v4' => ['node.removal_failed', '11111111-1111-4111-8111-111111111111'],
    ]);
});

function gateway_boundary_credential(string $label): string
{
    return "{$label}-credential";
}

function gateway_owned_trace_output(Throwable $exception): string
{
    $traces = [$exception->getTrace()];

    if ($exception->getPrevious() instanceof Throwable) {
        $traces[] = $exception->getPrevious()->getTrace();
    }

    $ownedFrames = [];

    foreach ($traces as $trace) {
        foreach ($trace as $frame) {
            $class = $frame['class'] ?? null;

            if (! is_string($class) || ! str_starts_with($class, 'Orbit\\Sdk\\')) {
                continue;
            }

            $ownedFrames[] = $frame;
        }
    }

    return print_r($ownedFrames, return: true);
}
