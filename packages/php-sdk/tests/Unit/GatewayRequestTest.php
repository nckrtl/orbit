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
                ->toBe(gateway_redacted_details())
                ->and($serialized)
                ->not->toContain(
                    $urlCredential,
                    $queryCredential,
                    $nestedCredential,
                    $messageCredential,
                    $authorizationHeader,
                );
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
