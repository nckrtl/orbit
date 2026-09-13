<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Schedules;

use InvalidArgumentException;
use Orbit\Sdk\Support\CredentialRedactor;
use Orbit\Sdk\Support\GatewayErrorCode;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class ScheduleResponse
{
    private const int MAX_NAME_LENGTH = 63;

    private const int MAX_CALENDAR_LENGTH = 255;

    private const int MAX_COMMAND_LENGTH = 4_096;

    private const int MAX_STATUS_DETAIL_LENGTH = 255;

    private const array TARGET_TYPES = ['node', 'instance'];

    private const array DESIRED_TIMER_STATES = ['enabled', 'disabled'];

    private const array STATUSES = ['provisioning', 'active', 'failed', 'removing'];

    private const array LAST_RUN_STATUSES = [null, 'success', 'error'];

    private const string UUID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD';

    private function __construct(
        public string $id,
        public string $targetType,
        public int $targetId,
        public string $name,
        public string $calendar,
        public ?string $command,
        public int $timeoutSeconds,
        public string $desiredTimerState,
        public string $status,
        public ?string $failedStep,
        public ?string $errorCode,
        public ?string $lastRunAt,
        public ?string $lastRunStatus,
        public string $requestId,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
        #[SensitiveParameter]
        bool $includeCommand = true,
    ): self {
        $id = $data['id'] ?? null;
        $targetType = $data['target_type'] ?? null;
        $targetId = $data['target_id'] ?? null;
        $name = self::boundedString($data['name'] ?? null, self::MAX_NAME_LENGTH);
        $calendar = self::boundedString($data['calendar'] ?? null, self::MAX_CALENDAR_LENGTH);
        $timeoutSeconds = $data['timeout_seconds'] ?? null;
        $desiredTimerState = $data['desired_timer_state'] ?? null;
        $status = $data['status'] ?? null;
        $failedStep = self::boundedNullableString($data, 'failed_step', self::MAX_STATUS_DETAIL_LENGTH);
        $lastRunAt = self::boundedNullableString($data, 'last_run_at', self::MAX_STATUS_DETAIL_LENGTH);
        $lastRunStatus = $data['last_run_status'] ?? null;
        $command = $includeCommand
            ? self::boundedString($data['command'] ?? null, self::MAX_COMMAND_LENGTH)
            : null;

        if (
            ! is_string($id)
            || preg_match(self::UUID_PATTERN, $id) !== 1
            || ! is_string($targetType)
            || ! in_array($targetType, self::TARGET_TYPES, strict: true)
            || ! is_int($targetId)
            || $targetId < 1
            || $name === null
            || $name === ''
            || $calendar === null
            || $calendar === ''
            || ($includeCommand && ($command === null || $command === ''))
            || (! $includeCommand && array_key_exists('command', $data))
            || ! is_int($timeoutSeconds)
            || $timeoutSeconds < 1
            || $timeoutSeconds > 86_400
            || ! is_string($desiredTimerState)
            || ! in_array($desiredTimerState, self::DESIRED_TIMER_STATES, strict: true)
            || ! is_string($status)
            || ! in_array($status, self::STATUSES, strict: true)
            || $failedStep === false
            || $lastRunAt === false
            || ! in_array($lastRunStatus, self::LAST_RUN_STATUSES, strict: true)
        ) {
            throw new InvalidArgumentException('Invalid Schedule response.');
        }

        return new self(
            id: $id,
            targetType: $targetType,
            targetId: $targetId,
            name: $name,
            calendar: $calendar,
            command: $command,
            timeoutSeconds: $timeoutSeconds,
            desiredTimerState: $desiredTimerState,
            status: $status,
            failedStep: $failedStep,
            errorCode: GatewayErrorCode::fromTransport($data['error_code'] ?? null),
            lastRunAt: $lastRunAt,
            lastRunStatus: $lastRunStatus,
            requestId: GatewayRequestId::fromTransport($requestId) ?? '',
        );
    }

    /** @return array<string, bool|int|string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'name' => $this->name,
            'calendar' => $this->calendar,
            ...($this->command === null ? [] : ['command' => $this->command]),
            'timeout_seconds' => $this->timeoutSeconds,
            'desired_timer_state' => $this->desiredTimerState,
            'status' => $this->status,
            'failed_step' => $this->failedStep,
            'error_code' => $this->errorCode,
            'last_run_at' => $this->lastRunAt,
            'last_run_status' => $this->lastRunStatus,
            'request_id' => $this->requestId,
        ];
    }

    private static function boundedString(#[SensitiveParameter] mixed $value, int $maximum): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $redacted = new CredentialRedactor()->redactText($value);

        return strlen($redacted) <= $maximum ? $redacted : null;
    }

    /** @param array<array-key, mixed> $data */
    private static function boundedNullableString(
        #[SensitiveParameter]
        array $data,
        string $key,
        int $maximum,
    ): string|false|null {
        if (! array_key_exists($key, $data)) {
            return false;
        }

        if ($data[$key] === null) {
            return null;
        }

        return self::boundedString($data[$key], $maximum) ?? false;
    }
}
