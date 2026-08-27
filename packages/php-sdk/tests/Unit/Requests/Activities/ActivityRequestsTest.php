<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Activities\ListActivitiesRequest;
use Orbit\Sdk\Requests\Activities\ShowActivityRequest;
use Orbit\Sdk\Responses\Activities\ActivitiesResponse;
use Orbit\Sdk\Responses\Activities\ActivityResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('lists a bounded typed activity collection', function (): void {
    $mock = new MockClient([
        ListActivitiesRequest::class => MockResponse::make([
            'data' => [activity_gateway_data()],
            'meta' => [
                'limit' => 10,
                'count' => 1,
                'request_id' => activity_request_id(),
            ],
        ]),
    ]);
    $request = new ListActivitiesRequest(10, '33333333-3333-4333-8333-333333333333');
    $response = activity_connector($mock)->send($request)->dto();

    expect($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->resolveEndpoint())
        ->toBe('/api/v1/activities')
        ->and($request->query()->all())
        ->toBe([
            'limit' => 10,
            'request_id' => '33333333-3333-4333-8333-333333333333',
        ])
        ->and($response)
        ->toBeInstanceOf(ActivitiesResponse::class)
        ->and($response->activities)
        ->toHaveCount(1)
        ->and($response->activities[0])
        ->toBeInstanceOf(ActivityResponse::class)
        ->and($response->activities[0]->command)
        ->toBe('process:start')
        ->and($response->requestId)
        ->toBe(activity_request_id());
});

it('shows one typed activity', function (): void {
    $mock = new MockClient([
        ShowActivityRequest::class => MockResponse::make([
            'data' => activity_gateway_data(),
            'meta' => ['request_id' => activity_request_id()],
        ]),
    ]);
    $request = new ShowActivityRequest(42);
    $response = activity_connector($mock)->send($request)->dto();

    expect($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->resolveEndpoint())
        ->toBe('/api/v1/activities/42')
        ->and($response)
        ->toBeInstanceOf(ActivityResponse::class)
        ->and($response->properties)
        ->toBe(['output_truncated' => true])
        ->and($response->toArray())
        ->toMatchArray([
            'id' => 42,
            'request_id' => '33333333-3333-4333-8333-333333333333',
            'command' => 'process:start',
            'subject_type' => 'process',
            'gateway_request_id' => activity_request_id(),
        ]);
});

it('drops unsafe embedded activity request IDs from response and trace state', function (): void {
    $credential = 'embedded-activity-request-id-credential';
    $unsafeRequestId = "request-token={$credential}\r\nX-Orbit-Control: {$credential}";
    $data = activity_gateway_data();
    $data['request_id'] = $unsafeRequestId;
    $mock = new MockClient([
        ShowActivityRequest::class => MockResponse::make([
            'data' => $data,
            'meta' => ['request_id' => activity_request_id()],
        ]),
    ]);
    $response = activity_connector($mock)->send(new ShowActivityRequest(42))->dto();

    expect($response)->toBeInstanceOf(ActivityResponse::class);

    if (! $response instanceof ActivityResponse) {
        $this->fail('Expected an activity response.');
    }

    $diagnostics = implode("\n", [
        (string) json_encode($response->toArray()),
        print_r($response, return: true),
    ]);

    expect($response->activityRequestId)
        ->toBeEmpty()
        ->and($response->toArray()['request_id'])
        ->toBeEmpty()
        ->and($diagnostics)
        ->not->toContain($credential, $unsafeRequestId);

    $traceException = activity_response_trace_exception($data);
    $sdkTrace = activity_response_sdk_trace($traceException);

    expect($sdkTrace)
        ->toContain('SensitiveParameterValue')
        ->not->toContain($credential, $unsafeRequestId);
});

function activity_connector(MockClient $mock): GatewayConnector
{
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mock);

    return $connector;
}

/** @return array<string, mixed> */
function activity_gateway_data(): array
{
    return [
        'id' => 42,
        'request_id' => '33333333-3333-4333-8333-333333333333',
        'command' => 'process:start',
        'caller_node_id' => 2,
        'target_node_id' => 3,
        'caller_ip' => '10.44.0.2',
        'status' => 'failed',
        'duration_ms' => 12,
        'exit_code' => 1,
        'error_code' => 'process.start_failed',
        'subject_type' => 'process',
        'subject_id' => 7,
        'properties' => ['output_truncated' => true],
        'occurred_at' => '2026-08-25T12:00:00+00:00',
    ];
}

function activity_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}

/** @param array<string, mixed> $data */
function activity_response_trace_exception(array $data): TypeError
{
    try {
        ActivityResponse::fromGatewayData($data, []);
    } catch (TypeError $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected the activity response factory to reject an invalid gateway request ID.');
}

function activity_response_sdk_trace(Throwable $exception): string
{
    $frames = array_values(array_filter(
        $exception->getTrace(),
        static fn (array $frame): bool => (
            array_key_exists('class', $frame)
            && is_string($frame['class'])
            && str_starts_with($frame['class'], 'Orbit\\Sdk\\')
        ),
    ));

    return print_r($frames, return: true);
}
