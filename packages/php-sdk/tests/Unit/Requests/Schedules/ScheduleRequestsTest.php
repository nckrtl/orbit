<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Schedules\ActivateScheduleRequest;
use Orbit\Sdk\Requests\Schedules\AddScheduleRequest;
use Orbit\Sdk\Requests\Schedules\AppInstanceScheduleTarget;
use Orbit\Sdk\Requests\Schedules\CompleteScheduleRequest;
use Orbit\Sdk\Requests\Schedules\ListSchedulesRequest;
use Orbit\Sdk\Requests\Schedules\NodeScheduleTarget;
use Orbit\Sdk\Requests\Schedules\RemoveScheduleRequest;
use Orbit\Sdk\Requests\Schedules\RunScheduleRequest;
use Orbit\Sdk\Requests\Schedules\ScheduleLogsRequest;
use Orbit\Sdk\Requests\Schedules\ShowScheduleRequest;
use Orbit\Sdk\Responses\Schedules\ScheduleCompletionResponse;
use Orbit\Sdk\Responses\Schedules\ScheduleLogsResponse;
use Orbit\Sdk\Responses\Schedules\ScheduleResponse;
use Orbit\Sdk\Responses\Schedules\SchedulesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('serializes distinct typed targets and preserves omitted versus explicit add values', function (): void {
    $omitted = new AddScheduleRequest(
        target: new AppInstanceScheduleTarget(7),
        name: 'daily-report',
        calendar: 'daily',
        command: 'php artisan report:send',
    );
    $explicit = new AddScheduleRequest(
        target: new NodeScheduleTarget(0),
        name: '',
        calendar: '',
        command: '',
        timeoutSeconds: 0,
        start: false,
    );

    expect($omitted->getMethod())
        ->toBe(Method::POST)
        ->and($omitted->resolveEndpoint())
        ->toBe('/api/v1/schedules')
        ->and($omitted->query()->all())
        ->toBe([])
        ->and($omitted->body()->all())
        ->toBe([
            'target_type' => 'instance',
            'target_id' => 7,
            'name' => 'daily-report',
            'calendar' => 'daily',
            'command' => 'php artisan report:send',
        ])
        ->and($explicit->body()->all())
        ->toBe([
            'target_type' => 'node',
            'target_id' => 0,
            'name' => '',
            'calendar' => '',
            'command' => '',
            'timeout_seconds' => 0,
            'start' => false,
        ]);

    $targetType = new ReflectionParameter([AddScheduleRequest::class, '__construct'], 'target')->getType();
    expect($targetType)
        ->toBeInstanceOf(ReflectionUnionType::class)
        ->and(array_map(
            static fn (ReflectionNamedType $type): string => $type->getName(),
            $targetType instanceof ReflectionUnionType ? $targetType->getTypes() : [],
        ))
        ->toEqualCanonicalizing([NodeScheduleTarget::class, AppInstanceScheduleTarget::class]);
});

it('maps every UUID operation to the exact method path query and body', function (
    GatewayRequest $request,
    Method $method,
    string $suffix,
    array $query,
    ?array $body,
): void {
    $id = 'schedule/id?token=route-secret';

    expect($request->getMethod())
        ->toBe($method)
        ->and($request->resolveEndpoint())
        ->toBe('/api/v1/schedules/'.rawurlencode($id).$suffix)
        ->and($request->query()->all())
        ->toBe($query);

    if ($body !== null) {
        expect($request->body()->all())->toBe($body);
    }
})->with(function (): array {
    $id = 'schedule/id?token=route-secret';

    return [
        'show' => [new ShowScheduleRequest($id), Method::GET, '', [], null],
        'run' => [new RunScheduleRequest($id), Method::POST, '/run', [], null],
        'logs with explicit invalid lines' => [new ScheduleLogsRequest($id, 0), Method::GET, '/logs', ['lines' => 0], null],
        'complete with explicit invalid status' => [new CompleteScheduleRequest($id, 'invalid'), Method::POST, '/complete', [], ['status' => 'invalid']],
        'remove' => [new RemoveScheduleRequest($id), Method::DELETE, '', [], null],
        'activate' => [new ActivateScheduleRequest($id), Method::POST, '/activate', [], null],
    ];
});

it('omits the optional log line query and sends lifecycle requests without bodies', function (): void {
    $requests = [
        new ListSchedulesRequest,
        new ShowScheduleRequest(schedule_request_uuid()),
        new RunScheduleRequest(schedule_request_uuid()),
        new ScheduleLogsRequest(schedule_request_uuid()),
        new RemoveScheduleRequest(schedule_request_uuid()),
        new ActivateScheduleRequest(schedule_request_uuid()),
    ];

    foreach ($requests as $request) {
        $mock = new MockClient([MockResponse::make(schedule_request_response_for($request))]);
        schedule_request_connector($mock)->send($request);
        $pending = $mock->getLastPendingRequest();

        expect($request->query()->all())->toBe([])
            ->and($pending?->body())->toBeNull()
            ->and($pending?->headers()->all())->not->toHaveKey('Content-Type')
            ->and((string) $pending?->createPsrRequest()->getBody())->toBeEmpty();
    }
});

it('returns typed DTOs for the seven JSON envelopes', function (): void {
    $requests = [
        [new ListSchedulesRequest, SchedulesResponse::class, ['data' => [schedule_request_data(includeCommand: false)], 'meta' => ['request_id' => schedule_request_id()]]],
        [new AddScheduleRequest(new NodeScheduleTarget(3), 'daily', 'daily', 'true'), ScheduleResponse::class, schedule_request_envelope()],
        [new ShowScheduleRequest(schedule_request_uuid()), ScheduleResponse::class, schedule_request_envelope()],
        [new RunScheduleRequest(schedule_request_uuid()), ScheduleResponse::class, schedule_request_envelope()],
        [new ScheduleLogsRequest(schedule_request_uuid(), 25), ScheduleLogsResponse::class, schedule_logs_envelope()],
        [new RemoveScheduleRequest(schedule_request_uuid()), ScheduleResponse::class, schedule_request_envelope()],
        [new ActivateScheduleRequest(schedule_request_uuid()), ScheduleResponse::class, schedule_request_envelope()],
    ];

    foreach ($requests as [$request, $expectedClass, $response]) {
        $mock = new MockClient([MockResponse::make($response)]);
        $dto = schedule_request_connector($mock)->send($request)->dto();

        expect($dto)->toBeInstanceOf($expectedClass);
    }
});

it('keeps completion bodyless and exposes only its validated response header request ID', function (): void {
    $mock = new MockClient([
        MockResponse::make('', 204, ['X-Orbit-Request-Id' => schedule_request_id()]),
    ]);
    $request = new CompleteScheduleRequest(schedule_request_uuid(), 'success');
    $dto = schedule_request_connector($mock)->send($request)->dto();

    expect($dto)
        ->toBeInstanceOf(ScheduleCompletionResponse::class)
        ->and($dto->toArray())
        ->toBe(['request_id' => schedule_request_id()])
        ->and($mock->getLastPendingRequest()?->body()?->all())
        ->toBe(['status' => 'success']);
});

it('rejects malformed nested collection members instead of inventing responses', function (): void {
    $mock = new MockClient([
        MockResponse::make([
            'data' => [schedule_request_data(includeCommand: false), 'malformed'],
            'meta' => ['request_id' => schedule_request_id()],
        ]),
    ]);

    expect(fn (): object => schedule_request_connector($mock)->send(new ListSchedulesRequest)->dto())
        ->toThrow(GatewayApiException::class, 'Gateway response contains invalid collection data.');
});

function schedule_request_connector(MockClient $mock): GatewayConnector
{
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mock);

    return $connector;
}

function schedule_request_response_for(GatewayRequest $request): array
{
    if ($request instanceof ListSchedulesRequest) {
        return ['data' => [], 'meta' => ['request_id' => schedule_request_id()]];
    }

    if ($request instanceof ScheduleLogsRequest) {
        return schedule_logs_envelope();
    }

    return schedule_request_envelope();
}

/** @return array<string, mixed> */
function schedule_request_envelope(): array
{
    return ['data' => schedule_request_data(), 'meta' => ['request_id' => schedule_request_id()]];
}

/** @return array<string, mixed> */
function schedule_logs_envelope(): array
{
    return [
        'data' => [
            'id' => schedule_request_uuid(),
            'name' => 'daily-report',
            'lines' => 25,
            'output' => "complete\n",
            'truncated' => false,
        ],
        'meta' => ['request_id' => schedule_request_id()],
    ];
}

/** @return array<string, mixed> */
function schedule_request_data(bool $includeCommand = true): array
{
    $data = [
        'id' => schedule_request_uuid(),
        'target_type' => 'instance',
        'target_id' => 7,
        'name' => 'daily-report',
        'calendar' => 'daily',
        'timeout_seconds' => 3600,
        'desired_timer_state' => 'disabled',
        'status' => 'active',
        'failed_step' => null,
        'error_code' => null,
        'last_run_at' => null,
        'last_run_status' => null,
    ];

    if ($includeCommand) {
        $data['command'] = 'php artisan report:send';
    }

    return $data;
}

function schedule_request_uuid(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}

function schedule_request_id(): string
{
    return '0198e15d-16c4-7855-8eb2-182b53ad28ba';
}
