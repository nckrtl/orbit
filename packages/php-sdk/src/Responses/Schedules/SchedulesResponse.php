<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Schedules;

use InvalidArgumentException;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class SchedulesResponse
{
    /** @param list<ScheduleResponse> $schedules */
    private function __construct(
        public array $schedules,
        public string $requestId,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        if (! array_is_list($data)) {
            throw new InvalidArgumentException('Invalid Schedule collection response.');
        }

        $schedules = [];
        foreach ($data as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('Invalid Schedule collection response.');
            }

            try {
                $schedules[] = ScheduleResponse::fromGatewayData($item, '', includeCommand: false);
            } catch (InvalidArgumentException) {
                throw new InvalidArgumentException('Invalid Schedule collection response.');
            }
        }

        return new self(
            schedules: $schedules,
            requestId: GatewayRequestId::fromTransport($requestId) ?? '',
        );
    }

    /** @return array{schedules: list<array<string, bool|int|string|null>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'schedules' => array_map(
                static function (ScheduleResponse $schedule): array {
                    $data = $schedule->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->schedules,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
