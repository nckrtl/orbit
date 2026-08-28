<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tools;

final readonly class ToolManagersResponse
{
    /** @param list<ToolManagerResponse> $managers */
    public function __construct(
        public array $managers,
        public string $requestId,
    ) {}

    /** @return array{managers: list<array<string, mixed>>, request_id: string} */
    public function toArray(): array
    {
        return ['managers' => array_map(static function (ToolManagerResponse $manager): array {
                $data = $manager->toArray();
                unset($data['request_id']);

                return $data;
            }, $this->managers), 'request_id' => $this->requestId];
    }
}
