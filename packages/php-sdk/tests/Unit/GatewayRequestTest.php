<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Gateway\ShowGatewayStatusRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/** @mago-expect lint:halstead Security-sensitive envelope assertions stay visible together. */
describe('gateway error envelope', function (): void {
    it('throws a typed exception with stable error details', function (): void {
        expect(class_exists(GatewayApiException::class))->toBeTrue();

        $mockClient = new MockClient([
            ShowGatewayStatusRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'gateway.unavailable',
                    'message' => 'The gateway is unavailable.',
                    'details' => ['service' => 'php-fpm'],
                ],
            ], 503),
        ]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mockClient);

        try {
            $connector->send(new ShowGatewayStatusRequest)->dto();
            $this->fail('Expected GatewayApiException.');
        } catch (GatewayApiException $exception) {
            expect($exception->getMessage())
                ->toBe('The gateway is unavailable.')
                ->and($exception->errorCode())
                ->toBe('gateway.unavailable')
                ->and($exception->details())
                ->toBe(['service' => 'php-fpm'])
                ->and($exception->requestId())
                ->toBeNull();
        }
    });

    it('treats a blank response request ID header as absent', function (): void {
        $mockClient = new MockClient([
            ShowGatewayStatusRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'gateway.unavailable',
                        'message' => 'The gateway is unavailable.',
                        'details' => [],
                    ],
                ],
                503,
                ['X-Orbit-Request-Id' => ''],
            ),
        ]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mockClient);

        try {
            $connector->send(new ShowGatewayStatusRequest)->dto();
            $this->fail('Expected GatewayApiException.');
        } catch (GatewayApiException $exception) {
            expect($exception->requestId())->toBeNull();
        }
    });

    it('captures the response request ID header on failed requests', function (): void {
        $mockClient = new MockClient([
            ShowGatewayStatusRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'gateway.unavailable',
                        'message' => 'The gateway is unavailable.',
                        'details' => ['service' => 'php-fpm'],
                    ],
                ],
                503,
                [
                    'x-orbit-request-id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
                ],
            ),
        ]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mockClient);

        try {
            $connector->send(new ShowGatewayStatusRequest)->dto();
            $this->fail('Expected GatewayApiException.');
        } catch (GatewayApiException $exception) {
            expect($exception->requestId())->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844');
        }
    });

    it('redacts credentials from error state and debug output', function (): void {
        $urlCredential = gateway_test_secret('url');
        $queryCredential = gateway_test_secret('query');
        $nestedCredential = gateway_test_secret('nested');
        $messageCredential = gateway_test_secret('message');
        $authorizationHeader = 'Bearer '.gateway_test_secret('authorization');
        $requestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
        $mockClient = new MockClient([
            ShowGatewayStatusRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'firewall.backend_inactive',
                        'message' => "UFW failed for https://alice:{$urlCredential}@example.test and password={$messageCredential}",
                        'details' => [
                            'step' => 'status',
                            'repository_url' => "https://alice:{$urlCredential}@example.test/orbit.git?token={$queryCredential}&branch=main",
                            'defaults' => [
                                'services' => [
                                    ['name' => 'gateway', 'token' => $nestedCredential],
                                ],
                            ],
                            'debug' => "Authorization: {$authorizationHeader}",
                        ],
                    ],
                ],
                503,
                ['X-Orbit-Request-Id' => $requestId],
            ),
        ]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mockClient);

        try {
            $connector->send(new ShowGatewayStatusRequest)->dto();
            $this->fail('Expected GatewayApiException.');
        } catch (GatewayApiException $exception) {
            $serialized = implode('\n', [
                $exception->getMessage(),
                (string) json_encode($exception->details()),
                (string) $exception,
                print_r($exception, return: true),
            ]);

            expect($exception->errorCode())
                ->toBe('firewall.backend_inactive')
                ->and($exception->requestId())
                ->toBe($requestId)
                ->and($exception->getMessage())
                ->toBe('UFW failed for https://[REDACTED]@example.test and password=[REDACTED]')
                ->and($exception->details())
                ->toBe(gateway_redacted_details());
            expect($serialized)->not->toContain($urlCredential);
            expect($serialized)->not->toContain($queryCredential);
            expect($serialized)->not->toContain($nestedCredential);
            expect($serialized)->not->toContain($messageCredential);
            expect($serialized)->not->toContain($authorizationHeader);
        }
    });

    it('redacts credentials from a previous transport exception', function (): void {
        $credential = gateway_test_secret('transport');
        $exception = new GatewayApiException(
            message: 'The gateway is unavailable.',
            errorCode: 'gateway.unavailable',
            previous: new \RuntimeException("Connection failed for https://alice:{$credential}@example.test."),
        );

        expect($exception->getPrevious())
            ->toBeInstanceOf(\RuntimeException::class)
            ->and($exception->getPrevious()?->getMessage())
            ->toBe('Connection failed for https://[REDACTED]@example.test.')
            ->and((string) $exception)
            ->not->toContain($credential)->and((string) $exception->getPrevious())
            ->not->toContain($credential)->and(print_r($exception, return: true))
            ->not->toContain($credential);
    });

    it('normalizes every frozen macOS error contract', function (
        int $status,
        string $code,
        array $details,
        array $expected,
    ): void {
        $exception = gateway_exception_from_response($status, $code, $details);

        expect($exception->errorCode())
            ->toBe($code)
            ->and($exception->details())
            ->toBe($expected);
    })->with([
        'unknown node' => [404, 'http.404', [], []],
        'validation' => [422, 'validation.failed', [], []],
        'role conflict' => [422, 'node.role_conflict', [], []],
        'peer unknown' => [403, 'peer.identity_unknown', [], []],
        'role setup not assigned' => [409, 'node.role_setup_not_assigned', [], []],
        'role setup not ready' => [
            409,
            'node.role_setup_not_ready',
            ['failed_step' => 'wireguard-projection'],
            ['failed_step' => 'wireguard-projection'],
        ],
        'role setup not required' => [409, 'node.role_setup_not_required', [], []],
        'local setup failed' => [
            422,
            'macos.setup_failed',
            ['failed_step' => 'local-setup'],
            ['failed_step' => 'local-setup'],
        ],
        'node unreachable' => [
            502,
            'node.unreachable',
            ['check' => 'caddy'],
            [],
        ],
        'verification failed' => [
            502,
            'macos.verification_failed',
            ['check' => 'caddy'],
            ['check' => 'caddy'],
        ],
        'protected drift' => [
            409,
            'macos.local_action_required',
            ['check' => 'root-ca-trust', 'local_command' => 'orbit gateway:trust'],
            ['check' => 'root-ca-trust', 'local_command' => 'orbit gateway:trust'],
        ],
        'missing user session' => [
            409,
            'macos.user_session_unavailable',
            ['runtime' => 'launchd'],
            ['runtime' => 'launchd'],
        ],
        'runtime unsupported' => [422, 'process.runtime_unsupported', [], []],
        'runtime unavailable' => [502, 'process.runtime_unavailable', [], []],
    ]);

    it('accepts every closed verification check', function (string $check): void {
        $exception = gateway_exception_from_response(
            status: 502,
            code: 'macos.verification_failed',
            details: ['check' => $check],
        );

        expect($exception->details())->toBe(['check' => $check]);
    })->with([
        'ssh host key' => ['ssh-host-key'],
        'identity' => ['identity'],
        'architecture' => ['architecture'],
        'restricted key' => ['restricted-key'],
        'homebrew' => ['homebrew'],
        'toolchain' => ['toolchain'],
        'caddy' => ['caddy'],
        'php fpm' => ['php-fpm'],
    ]);

    it('accepts only the check-specific protected command', function (
        string $check,
        ?string $command,
        ?string $expected,
    ): void {
        $exception = gateway_exception_from_response(
            status: 409,
            code: 'macos.local_action_required',
            details: ['check' => $check, 'local_command' => $command],
        );

        expect($exception->details())->toBe([
            'check' => $check,
            'local_command' => $expected,
        ]);
    })->with([
        'remote login' => ['remote-login', null, null],
        'PF anchor' => ['pf-anchor', 'orbit gateway:trust', null],
        'resolver' => ['resolver', null, null],
        'dnsmasq' => ['dnsmasq', 'arbitrary-command', null],
        'root trust without command' => ['root-ca-trust', null, null],
        'root trust with command' => ['root-ca-trust', 'orbit gateway:trust', 'orbit gateway:trust'],
    ]);

    it('omits invalid new error details without normalization', function (
        string $code,
        array $details,
    ): void {
        $exception = gateway_exception_from_response(502, $code, $details);
        $diagnostics = implode("\n", [
            (string) json_encode($exception->details()),
            (string) $exception,
            print_r($exception, return: true),
            gateway_request_sdk_trace($exception),
        ]);

        expect($exception->details())->toBeEmpty();

        foreach ($details as $value) {
            if (! is_string($value)) {
                continue;
            }

            expect($diagnostics)->not->toContain($value);
        }
    })->with([
        'unknown setup step' => ['macos.setup_failed', ['failed_step' => 'unknown-step']],
        'unknown enrollment step' => ['node.role_setup_not_ready', ['failed_step' => 'unknown-step']],
        'unknown verification check' => ['macos.verification_failed', ['check' => 'unknown-check']],
        'oversized verification check' => ['macos.verification_failed', ['check' => str_repeat('c', times: 129)]],
        'control-bearing verification check' => ['macos.verification_failed', ['check' => "caddy\n"]],
        'credential-bearing verification check' => [
            'macos.verification_failed',
            ['check' => 'token=macos-error-detail-credential'],
        ],
        'unknown protected check' => [
            'macos.local_action_required',
            ['check' => 'unknown-check', 'local_command' => 'orbit gateway:trust'],
        ],
        'wrong missing-session runtime' => [
            'macos.user_session_unavailable',
            ['runtime' => 'systemd'],
        ],
        'unreachable verification detail' => ['node.unreachable', ['check' => 'caddy']],
    ]);

    it('omits unknown detail keys for new errors before redaction', function (): void {
        $credential = gateway_test_secret('unknown-detail');
        $exception = gateway_exception_from_response(
            status: 422,
            code: 'macos.setup_failed',
            details: [
                'failed_step' => 'local-setup',
                'debug' => "token={$credential}",
            ],
        );
        $diagnostics = json_encode($exception->__debugInfo(), JSON_THROW_ON_ERROR).(string) $exception;

        expect($exception->details())
            ->toBe(['failed_step' => 'local-setup'])
            ->and($diagnostics)
            ->not->toContain($credential);
    });

    it('rejects malformed or credential-shaped failed request IDs', function (string $requestId): void {
        $exception = gateway_exception_from_response(
            status: 502,
            code: 'node.unreachable',
            details: [],
            requestId: $requestId,
        );
        $diagnostics = (string) $exception.print_r($exception, return: true);

        expect($exception->requestId())
            ->toBeNull()
            ->and($diagnostics)
            ->not->toContain($requestId);
    })->with([
        'malformed' => ['not-a-request-id'],
        'credential-shaped' => ['token=failed-request-id-credential'],
    ]);
});

function gateway_test_secret(string $label): string
{
    return "{$label}-credential";
}

/**
 * @return array<string, mixed>
 */
function gateway_redacted_details(): array
{
    return [
        'step' => 'status',
        'repository_url' => 'https://[REDACTED]@example.test/orbit.git?token=[REDACTED]&branch=main',
        'defaults' => [
            'services' => [
                gateway_redacted_service(),
            ],
        ],
        'debug' => 'Authorization: [REDACTED]',
    ];
}

/**
 * @return array{name: string, token: string}
 */
function gateway_redacted_service(): array
{
    $redacted = '[REDACTED]';

    return [
        'name' => 'gateway',
        'token' => $redacted,
    ];
}

/** @param array<string, mixed> $details */
function gateway_exception_from_response(
    int $status,
    string $code,
    array $details,
    ?string $requestId = null,
): GatewayApiException {
    $headers = $requestId === null ? [] : ['X-Orbit-Request-Id' => $requestId];
    $mockClient = new MockClient([
        ShowGatewayStatusRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => $code,
                    'message' => 'The Gateway operation failed.',
                    'details' => $details,
                ],
            ],
            $status,
            $headers,
        ),
    ]);
    $connector = new GatewayConnector('https://10.70.0.1');
    $connector->withMockClient($mockClient);

    try {
        $connector->send(new ShowGatewayStatusRequest)->dto();
    } catch (GatewayApiException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected GatewayApiException.');
}

function gateway_request_sdk_trace(Throwable $exception): string
{
    $frames = array_values(array_filter(
        $exception->getTrace(),
        static fn (array $frame): bool => (
            is_string($frame['class'] ?? null) && str_starts_with($frame['class'], 'Orbit\\Sdk\\')
        ),
    ));

    return print_r($frames, return: true);
}
