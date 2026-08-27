<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Activities;

final readonly class ActivitiesResponse
{
    /** @param list<ActivityResponse> $activities */
    public function __construct(
        public array $activities,
        public string $requestId,
    ) {}

    /** @return array{activities: list<array<string, mixed>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'activities' => array_map(
                static function (ActivityResponse $activity): array {
                    $data = $activity->toArray();
                    unset($data['gateway_request_id']);

                    return $data;
                },
                $this->activities,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
