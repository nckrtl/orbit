<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Logs\CreateLogStreamRequest;
use Orbit\Sdk\Requests\Logs\DestroyLogStreamRequest;
use Orbit\Sdk\Requests\Logs\InstanceLogStreamTarget;
use Orbit\Sdk\Requests\Logs\ProcessLogStreamTarget;
use Orbit\Sdk\Requests\Logs\RenewLogStreamRequest;
use Orbit\Sdk\Responses\Logs\LogStreamClosedResponse;
use Orbit\Sdk\Responses\Logs\LogStreamRenewalResponse;
use Orbit\Sdk\Responses\Logs\LogStreamResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe(CreateLogStreamRequest::class, function (): void {
    it('opens a stream for an Instance or a Process with the socket ID and line count', function (InstanceLogStreamTarget|ProcessLogStreamTarget $target, string $endpoint): void {
        $mock = new MockClient([CreateLogStreamRequest::class => MockResponse::make(log_stream_envelope(), 201)]);
        $request = new CreateLogStreamRequest($target, '123.456', 250);

        $response = log_stream_connector($mock)->send($request)->dto();

        expect($request->getMethod())->toBe(Method::POST)
            ->and($request->resolveEndpoint())->toBe($endpoint)
            ->and($request->body()->all())->toBe(['socket_id' => '123.456', 'lines' => 250])
            ->and($response)->toBeInstanceOf(LogStreamResponse::class)
            ->and($response->id)->toBe(log_stream_id())
            ->and($response->channel)->toBe('private-log-stream.'.log_stream_id())
            ->and($response->auth)->toBe('app-key:log-stream-signature-sentinel')
            ->and($response->lines)->toBe(100)
            ->and($response->leaseSeconds)->toBe(60)
            ->and($response->renewSeconds)->toBe(20)
            ->and($response->requestId)->toBe(log_stream_request_id());
    })->with([
        'instance' => [new InstanceLogStreamTarget(12), '/api/v1/instances/12/log-streams'],
        'process' => [new ProcessLogStreamTarget(41), '/api/v1/processes/41/log-streams'],
    ]);

    it('defaults to 100 lines', function (): void {
        expect(new CreateLogStreamRequest(new ProcessLogStreamTarget(41), '1.2')->body()->all())
            ->toBe(['socket_id' => '1.2', 'lines' => 100]);
    });

    it('keeps the subscription signature out of arrays, debug output, and serialization', function (): void {
        $mock = new MockClient([CreateLogStreamRequest::class => MockResponse::make(log_stream_envelope(), 201)]);
        $response = log_stream_connector($mock)->send(new CreateLogStreamRequest(new ProcessLogStreamTarget(41), '1.2'))->dto();

        expect(json_encode($response->toArray()))->not->toContain('log-stream-signature-sentinel')
            ->and(print_r($response, return: true))->not->toContain('log-stream-signature-sentinel')
            ->and(var_export($response->toArray(), return: true))->not->toContain('log-stream-signature-sentinel')
            ->and(fn (): string => serialize($response))->toThrow(LogicException::class);
    });

    it('rejects a stream whose fields do not match the contract', function (array $override): void {
        $envelope = log_stream_envelope();
        $envelope['data'] = [...$envelope['data'], ...$override];
        $mock = new MockClient([CreateLogStreamRequest::class => MockResponse::make($envelope, 201)]);

        log_stream_connector($mock)->send(new CreateLogStreamRequest(new ProcessLogStreamTarget(41), '1.2'))->dto();
    })->with([
        'short id' => [['id' => 'abc']],
        'uppercase id' => [['id' => strtoupper(log_stream_id())]],
        'foreign channel' => [['channel' => 'private-orbit']],
        'missing auth' => [['auth' => '']],
        'auth with whitespace' => [['auth' => "app-key:sig\nnext"]],
        'lines out of range' => [['lines' => 1001]],
        'string lease' => [['lease_seconds' => '60']],
        'zero renewal' => [['renew_seconds' => 0]],
    ])->throws(GatewayApiException::class, 'invalid log stream');

    it('exposes the live-unavailable reason from the error envelope', function (): void {
        $mock = new MockClient([CreateLogStreamRequest::class => MockResponse::make([
            'error' => [
                'code' => 'logs.live_unavailable',
                'message' => 'Live logs are not available for this Node.',
                'details' => ['reason' => 'agent_outdated'],
            ],
        ], 409, ['X-Orbit-Request-Id' => log_stream_request_id()])]);

        try {
            log_stream_connector($mock)->send(new CreateLogStreamRequest(new ProcessLogStreamTarget(41), '1.2'));
            $this->fail('Expected the Gateway refusal.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())->toBe('logs.live_unavailable')
                ->and($exception->details())->toBe(['reason' => 'agent_outdated'])
                ->and($exception->requestId())->toBe(log_stream_request_id());
        }
    });
});

describe(RenewLogStreamRequest::class, function (): void {
    it('renews one stream through the record it was opened for, without a body', function (InstanceLogStreamTarget|ProcessLogStreamTarget $target, string $endpoint): void {
        $mock = new MockClient([RenewLogStreamRequest::class => MockResponse::make([
            'data' => ['id' => log_stream_id(), 'lease_seconds' => 60],
            'meta' => ['request_id' => log_stream_request_id()],
        ])]);
        $request = new RenewLogStreamRequest($target, log_stream_id());

        $response = log_stream_connector($mock)->send($request)->dto();

        expect($request->getMethod())->toBe(Method::PUT)
            ->and($request->resolveEndpoint())->toBe($endpoint.'/'.log_stream_id())
            ->and($request)->not->toBeInstanceOf(HasBody::class)
            ->and($response)->toBeInstanceOf(LogStreamRenewalResponse::class)
            ->and($response->toArray())->toBe([
                'id' => log_stream_id(),
                'lease_seconds' => 60,
                'request_id' => log_stream_request_id(),
            ]);
    })->with([
        'instance' => [new InstanceLogStreamTarget(12), '/api/v1/instances/12/log-streams'],
        'process' => [new ProcessLogStreamTarget(41), '/api/v1/processes/41/log-streams'],
    ]);

    it('rejects a malformed renewal', function (): void {
        $mock = new MockClient([RenewLogStreamRequest::class => MockResponse::make([
            'data' => ['id' => log_stream_id(), 'lease_seconds' => null],
            'meta' => ['request_id' => log_stream_request_id()],
        ])]);

        log_stream_connector($mock)->send(new RenewLogStreamRequest(new ProcessLogStreamTarget(41), log_stream_id()))->dto();
    })->throws(GatewayApiException::class, 'invalid log stream renewal');

    it('keeps an unknown stream as a structured error', function (): void {
        $mock = new MockClient([RenewLogStreamRequest::class => MockResponse::make([
            'error' => ['code' => 'logs.stream_not_found', 'message' => 'The log stream does not exist.', 'details' => []],
        ], 404)]);

        try {
            log_stream_connector($mock)->send(new RenewLogStreamRequest(new InstanceLogStreamTarget(12), log_stream_id()));
            $this->fail('Expected the Gateway refusal.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())->toBe('logs.stream_not_found');
        }
    });

    it('encodes the stream ID as one path segment', function (): void {
        expect(new RenewLogStreamRequest(new ProcessLogStreamTarget(41), '../x')->resolveEndpoint())
            ->toBe('/api/v1/processes/41/log-streams/..%2Fx');
    });
});

describe(DestroyLogStreamRequest::class, function (): void {
    it('closes one stream without a body', function (InstanceLogStreamTarget|ProcessLogStreamTarget $target, string $endpoint): void {
        $mock = new MockClient([DestroyLogStreamRequest::class => MockResponse::make([
            'data' => ['id' => log_stream_id(), 'closed' => true],
            'meta' => ['request_id' => log_stream_request_id()],
        ])]);
        $request = new DestroyLogStreamRequest($target, log_stream_id());

        $response = log_stream_connector($mock)->send($request)->dto();

        expect($request->getMethod())->toBe(Method::DELETE)
            ->and($request->resolveEndpoint())->toBe($endpoint.'/'.log_stream_id())
            ->and($request)->not->toBeInstanceOf(HasBody::class)
            ->and($response)->toBeInstanceOf(LogStreamClosedResponse::class)
            ->and($response->toArray())->toBe([
                'id' => log_stream_id(),
                'closed' => true,
                'request_id' => log_stream_request_id(),
            ]);
    })->with([
        'instance' => [new InstanceLogStreamTarget(12), '/api/v1/instances/12/log-streams'],
        'process' => [new ProcessLogStreamTarget(41), '/api/v1/processes/41/log-streams'],
    ]);

    it('rejects a malformed closure', function (): void {
        $mock = new MockClient([DestroyLogStreamRequest::class => MockResponse::make([
            'data' => ['id' => log_stream_id(), 'closed' => 'yes'],
            'meta' => ['request_id' => log_stream_request_id()],
        ])]);

        log_stream_connector($mock)->send(new DestroyLogStreamRequest(new ProcessLogStreamTarget(41), log_stream_id()))->dto();
    })->throws(GatewayApiException::class, 'invalid log stream closure');
});

function log_stream_connector(MockClient $mock): GatewayConnector
{
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mock);

    return $connector;
}

function log_stream_id(): string
{
    return '3f9c2a6b0d1e4f5a8b7c6d5e4f3a2b1c';
}

function log_stream_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}

/** @return array{data: array<string, mixed>, meta: array{request_id: string}} */
function log_stream_envelope(): array
{
    return [
        'data' => [
            'id' => log_stream_id(),
            'channel' => 'private-log-stream.'.log_stream_id(),
            'auth' => 'app-key:log-stream-signature-sentinel',
            'lines' => 100,
            'lease_seconds' => 60,
            'renew_seconds' => 20,
        ],
        'meta' => ['request_id' => log_stream_request_id()],
    ];
}
