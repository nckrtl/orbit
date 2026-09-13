<?php

declare(strict_types=1);

use Orbit\Sdk\Responses\Schedules\ScheduleCompletionResponse;
use Orbit\Sdk\Responses\Schedules\ScheduleLogsResponse;
use Orbit\Sdk\Responses\Schedules\ScheduleResponse;
use Orbit\Sdk\Responses\Schedules\SchedulesResponse;

it('preserves one bounded item while redacting command credentials and independent timer state', function (): void {
    $credential = 'schedule-command-secret';
    $data = schedule_response_data();
    $data['command'] = "php report.php --token={$credential}";
    $data['desired_timer_state'] = 'disabled';
    $data['status'] = 'active';

    $response = ScheduleResponse::fromGatewayData($data, schedule_response_request_id());

    expect($response->id)
        ->toBe(schedule_response_uuid())
        ->and($response->command)
        ->toBe('php report.php --token=[REDACTED]')
        ->and($response->desiredTimerState)
        ->toBe('disabled')
        ->and($response->status)
        ->toBe('active')
        ->and($response->requestId)
        ->toBe(schedule_response_request_id())
        ->and($response->toArray())
        ->toBe([
            'id' => schedule_response_uuid(),
            'target_type' => 'instance',
            'target_id' => 7,
            'name' => 'daily-report',
            'calendar' => 'daily',
            'command' => 'php report.php --token=[REDACTED]',
            'timeout_seconds' => 3600,
            'desired_timer_state' => 'disabled',
            'status' => 'active',
            'failed_step' => null,
            'error_code' => null,
            'last_run_at' => null,
            'last_run_status' => null,
            'request_id' => schedule_response_request_id(),
        ])
        ->and(json_encode($response->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain($credential);
});

it('creates strict collection items without command text or nested request IDs', function (): void {
    $item = schedule_response_data(includeCommand: false);
    $response = SchedulesResponse::fromGatewayData([$item], schedule_response_request_id());

    expect($response->schedules)->toHaveCount(1)
        ->and($response->toArray())
        ->toBe([
            'schedules' => [$item],
            'request_id' => schedule_response_request_id(),
        ]);
});

it('rejects malformed nested collection data', function (array $data): void {
    expect(fn (): SchedulesResponse => SchedulesResponse::fromGatewayData($data, schedule_response_request_id()))
        ->toThrow(InvalidArgumentException::class, 'Invalid Schedule collection response.');
})->with([
    'map instead of list' => [['schedule' => schedule_response_data(includeCommand: false)]],
    'scalar item' => [[schedule_response_data(includeCommand: false), 'bad']],
    'item with command' => [[schedule_response_data()]],
]);

it('bounds and redacts Schedule log output', function (): void {
    $credential = 'schedule-log-secret';
    $response = ScheduleLogsResponse::fromGatewayData([
        'id' => schedule_response_uuid(),
        'name' => 'daily-report',
        'lines' => 25,
        'output' => "Authorization: Bearer {$credential}\n",
        'truncated' => true,
    ], schedule_response_request_id());

    expect($response->output)
        ->toBe("Authorization: [REDACTED]\n")
        ->and($response->truncated)->toBeTrue()
        ->and(json_encode($response->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain($credential);
});

it('accepts every current target timer lifecycle and last-run token independently', function (): void {
    foreach (['node', 'instance'] as $targetType) {
        foreach (['enabled', 'disabled'] as $desiredTimerState) {
            foreach (['provisioning', 'active', 'failed', 'removing'] as $status) {
                foreach ([null, 'success', 'error'] as $lastRunStatus) {
                    $data = schedule_response_data();
                    $data['target_type'] = $targetType;
                    $data['desired_timer_state'] = $desiredTimerState;
                    $data['status'] = $status;
                    $data['last_run_status'] = $lastRunStatus;

                    $response = ScheduleResponse::fromGatewayData($data, schedule_response_request_id());

                    expect([$response->targetType, $response->desiredTimerState, $response->status, $response->lastRunStatus])
                        ->toBe([$targetType, $desiredTimerState, $status, $lastRunStatus]);
                }
            }
        }
    }
});

it('redacts credentials before applying command and log output bounds', function (): void {
    $oversizedCredential = str_repeat('x', 1_048_577);
    $data = schedule_response_data();
    $data['command'] = "token={$oversizedCredential}";

    $item = ScheduleResponse::fromGatewayData($data, schedule_response_request_id());
    $logs = ScheduleLogsResponse::fromGatewayData([
        'id' => schedule_response_uuid(),
        'name' => 'daily-report',
        'lines' => 1_000,
        'output' => "token={$oversizedCredential}",
        'truncated' => false,
    ], schedule_response_request_id());

    expect($item->command)
        ->toBe('token=[REDACTED]')
        ->and($logs->output)
        ->toBe('token=[REDACTED]');
});

it('rejects overlong command and log values after redaction', function (string $type): void {
    if ($type === 'command') {
        $data = schedule_response_data();
        $data['command'] = str_repeat('x', 4097);

        expect(fn (): ScheduleResponse => ScheduleResponse::fromGatewayData($data, schedule_response_request_id()))
            ->toThrow(InvalidArgumentException::class, 'Invalid Schedule response.');

        return;
    }

    expect(fn (): ScheduleLogsResponse => ScheduleLogsResponse::fromGatewayData([
        'id' => schedule_response_uuid(),
        'name' => 'daily-report',
        'lines' => 25,
        'output' => str_repeat('x', 1_048_577),
        'truncated' => true,
    ], schedule_response_request_id()))
        ->toThrow(InvalidArgumentException::class, 'Invalid Schedule logs response.');
})->with(['command', 'logs']);

it('rejects malformed Schedule log values', function (string $key, mixed $value): void {
    $data = [
        'id' => schedule_response_uuid(),
        'name' => 'daily-report',
        'lines' => 25,
        'output' => "complete\n",
        'truncated' => false,
    ];
    $data[$key] = $value;

    expect(fn (): ScheduleLogsResponse => ScheduleLogsResponse::fromGatewayData($data, schedule_response_request_id()))
        ->toThrow(InvalidArgumentException::class, 'Invalid Schedule logs response.');
})->with([
    'identifier' => ['id', 'not-a-uuid'],
    'name' => ['name', str_repeat('x', 64)],
    'lines lower bound' => ['lines', 0],
    'lines upper bound' => ['lines', 1_001],
    'output type' => ['output', []],
    'truncated type' => ['truncated', 0],
]);

it('rejects malformed required item values', function (string $key, mixed $value): void {
    $data = schedule_response_data();
    $data[$key] = $value;

    expect(fn (): ScheduleResponse => ScheduleResponse::fromGatewayData($data, schedule_response_request_id()))
        ->toThrow(InvalidArgumentException::class, 'Invalid Schedule response.');
})->with([
    'identifier' => ['id', 'not-a-uuid'],
    'target type' => ['target_type', 'workspace'],
    'target id' => ['target_id', 0],
    'name' => ['name', str_repeat('x', 64)],
    'calendar' => ['calendar', str_repeat('x', 256)],
    'command type' => ['command', []],
    'timeout' => ['timeout_seconds', 0],
    'desired timer state' => ['desired_timer_state', 'starting'],
    'lifecycle state' => ['status', 'disabled'],
    'failed step' => ['failed_step', str_repeat('x', 256)],
    'last run time' => ['last_run_at', str_repeat('x', 256)],
    'last run status' => ['last_run_status', 'unknown'],
]);

it('bounds error and request identifiers without retaining invalid values', function (): void {
    $credential = 'schedule-boundary-secret';
    $data = schedule_response_data();
    $data['error_code'] = "token={$credential}";
    $response = ScheduleResponse::fromGatewayData($data, "token={$credential}");
    $completion = ScheduleCompletionResponse::fromRequestId("token={$credential}");

    expect($response->errorCode)->toBeNull()
        ->and($response->requestId)->toBeEmpty()
        ->and($completion->requestId)->toBeEmpty()
        ->and(json_encode([$response->toArray(), $completion->toArray()], JSON_THROW_ON_ERROR))
        ->not->toContain($credential);
});

it('keeps Schedule response values immutable behind private constructors', function (): void {
    $item = ScheduleResponse::fromGatewayData(schedule_response_data(), schedule_response_request_id());
    $logs = ScheduleLogsResponse::fromGatewayData([
        'id' => schedule_response_uuid(),
        'name' => 'daily-report',
        'lines' => 25,
        'output' => '',
        'truncated' => false,
    ], schedule_response_request_id());
    $collection = SchedulesResponse::fromGatewayData([], schedule_response_request_id());
    $completion = ScheduleCompletionResponse::fromRequestId(schedule_response_request_id());

    expect(fn (): mixed => $item->name = 'changed')->toThrow(Error::class);

    foreach ([$item, $logs, $collection, $completion] as $response) {
        expect(new ReflectionMethod($response, '__construct')->isPrivate())->toBeTrue();
    }
});

/** @return array<string, mixed> */
function schedule_response_data(bool $includeCommand = true): array
{
    $data = [
        'id' => schedule_response_uuid(),
        'target_type' => 'instance',
        'target_id' => 7,
        'name' => 'daily-report',
        'calendar' => 'daily',
        'timeout_seconds' => 3600,
        'desired_timer_state' => 'enabled',
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

function schedule_response_uuid(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}

function schedule_response_request_id(): string
{
    return '0198e15d-16c4-7855-8eb2-182b53ad28ba';
}
