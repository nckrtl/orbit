<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Deployments\DeployAppInstanceRequest;
use Orbit\Sdk\Requests\Deployments\RollbackAppInstanceRequest;
use Orbit\Sdk\Requests\Deployments\ShowAppInstanceDeploymentConfigRequest;
use Orbit\Sdk\Responses\Deployments\DeploymentStream;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('deployment transport', function (): void {
    it('uses request-local streaming and the bounded Gateway budget without weakening TLS', function (): void {
        $requestId = deployment_transport_request_id();
        $mock = new MockClient([
            DeployAppInstanceRequest::class => MockResponse::make(
                deployment_transport_result(),
                headers: deployment_transport_headers(),
            ),
            RollbackAppInstanceRequest::class => MockResponse::make(
                deployment_transport_result(),
                headers: deployment_transport_headers(),
            ),
        ]);
        $connector = new GatewayConnector(
            'https://gateway.test',
            caPemPath: '/etc/orbit/gateway-ca.pem',
            timeout: 17,
            requestIdResolver: static fn (): string => $requestId,
        );
        $connector->withMockClient($mock);

        foreach ([
            new DeployAppInstanceRequest(17),
            new RollbackAppInstanceRequest(17, 'release-a'),
        ] as $request) {
            $response = $connector->send($request);
            $pending = $mock->getLastPendingRequest();
            $stream = $response->dto();

            expect($pending?->config()->all())->toMatchArray([
                'allow_redirects' => false,
                'connect_timeout' => 10,
                'timeout' => 4_500,
                'verify' => '/etc/orbit/gateway-ca.pem',
                'stream' => true,
            ])->and($pending?->headers()->get('Accept'))->toBe('application/x-ndjson')
                ->and($pending?->headers()->get('Content-Type'))->toBe('application/json')
                ->and($pending?->headers()->get('X-Orbit-Request-Id'))->toBe($requestId)
                ->and($stream)->toBeInstanceOf(DeploymentStream::class);

            $stream->close();
        }
    });

    it('does not change ordinary JSON transport defaults', function (): void {
        $mock = new MockClient([
            ShowAppInstanceDeploymentConfigRequest::class => MockResponse::make([
                'data' => ['branch' => 'main', 'steps' => []],
                'meta' => ['request_id' => deployment_transport_request_id()],
            ]),
        ]);
        $connector = new GatewayConnector(
            'https://gateway.test',
            caPemPath: '/etc/orbit/gateway-ca.pem',
            timeout: 17,
        );
        $connector->withMockClient($mock);
        $connector->send(new ShowAppInstanceDeploymentConfigRequest(17))->dto();
        $config = $mock->getLastPendingRequest()?->config()->all() ?? [];

        expect($config)->toMatchArray([
            'allow_redirects' => false,
            'connect_timeout' => 10,
            'timeout' => 17,
            'verify' => '/etc/orbit/gateway-ca.pem',
        ])->and($config)->not->toHaveKey('stream');
    });

    it('closes the actual HTTP response when the consumer cancels', function (): void {
        $mock = new MockClient([
            DeployAppInstanceRequest::class => MockResponse::make(
                deployment_transport_result(),
                headers: deployment_transport_headers(),
            ),
        ]);
        $connector = deployment_transport_connector($mock);
        $response = $connector->send(new DeployAppInstanceRequest(17));
        $stream = $response->dto();

        expect($stream)->toBeInstanceOf(DeploymentStream::class)
            ->and($response->stream()->isReadable())->toBeTrue();

        $stream->close();
        expect($response->stream()->isReadable())->toBeFalse();
    });

    it('keeps pre-admission JSON errors and performs no retry or replay', function (): void {
        $mock = new MockClient([
            DeployAppInstanceRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'deployment.busy',
                    'message' => 'The deployment operation is busy.',
                    'details' => [],
                ],
            ], 409, ['X-Orbit-Request-Id' => deployment_transport_request_id()]),
        ]);
        $connector = deployment_transport_connector($mock);

        try {
            $connector->send(new DeployAppInstanceRequest(17));
            $this->fail('Expected a structured Gateway error.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())->toBe('deployment.busy')
                ->and($exception->requestId())->toBe(deployment_transport_request_id());
        }

        $mock->assertSentCount(1, DeployAppInstanceRequest::class);
    });

    it('rejects an invalid stream response boundary and closes it', function (array $headers): void {
        $mock = new MockClient([
            DeployAppInstanceRequest::class => MockResponse::make(
                deployment_transport_result(),
                headers: $headers,
            ),
        ]);
        $connector = deployment_transport_connector($mock);
        $response = $connector->send(new DeployAppInstanceRequest(17));

        expect(fn (): mixed => $response->dto())
            ->toThrow(GatewayApiException::class, 'Gateway response is not a valid deployment stream.')
            ->and($response->stream()->isReadable())->toBeFalse();
    })->with([
        'wrong content type' => [[
            'Content-Type' => 'application/json',
            'X-Orbit-Request-Id' => deployment_transport_request_id(),
        ]],
        'missing request ID' => [[
            'Content-Type' => 'application/x-ndjson',
        ]],
        'invalid request ID' => [[
            'Content-Type' => 'application/x-ndjson',
            'X-Orbit-Request-Id' => 'invalid-request-id',
        ]],
    ]);

    it('does not resend or replay a truncated admitted stream', function (): void {
        $mock = new MockClient([
            DeployAppInstanceRequest::class => MockResponse::make(
                rtrim(deployment_transport_result(), "\n"),
                headers: deployment_transport_headers(),
            ),
        ]);
        $connector = deployment_transport_connector($mock);
        $stream = $connector->send(new DeployAppInstanceRequest(17))->dto();

        expect($stream)->toBeInstanceOf(DeploymentStream::class)
            ->and(fn (): array => iterator_to_array($stream))
            ->toThrow(GatewayApiException::class, 'Gateway deployment stream is invalid.');
        $mock->assertSentCount(1, DeployAppInstanceRequest::class);
    });
});

function deployment_transport_connector(MockClient $mock): GatewayConnector
{
    $connector = new GatewayConnector(
        'https://gateway.test',
        requestIdResolver: static fn (): string => deployment_transport_request_id(),
    );
    $connector->withMockClient($mock);

    return $connector;
}

/** @return array<string, string> */
function deployment_transport_headers(): array
{
    return [
        'Content-Type' => 'application/x-ndjson',
        'X-Orbit-Request-Id' => deployment_transport_request_id(),
    ];
}

function deployment_transport_result(): string
{
    return json_encode([
        'type' => 'result',
        'sequence' => 1,
        'request_id' => deployment_transport_request_id(),
        'status' => 'succeeded',
        'failed_step' => null,
        'error_code' => null,
        'selected_release' => 'release-a',
    ], JSON_THROW_ON_ERROR)."\n";
}

function deployment_transport_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a846';
}
