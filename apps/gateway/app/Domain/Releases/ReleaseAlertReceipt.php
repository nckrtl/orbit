<?php

declare(strict_types=1);

namespace App\Domain\Releases;

/**
 * What an alert recorded, for the caller's release record. A null Activity id means the entry could not be written.
 */
final readonly class ReleaseAlertReceipt
{
    public function __construct(
        public string $requestId,
        public ?int $activityId,
        public ReleaseAlertStep $problem,
        public ReleaseAlertStep $webhook,
    ) {}

    /** @return array{request_id: string, activity_id: int|null, problem: array<string, int|string>, webhook: array<string, int|string>} */
    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'activity_id' => $this->activityId,
            'problem' => $this->problem->toArray(),
            'webhook' => $this->webhook->toArray(),
        ];
    }
}
