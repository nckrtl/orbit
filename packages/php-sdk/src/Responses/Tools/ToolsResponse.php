<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tools;

final readonly class ToolsResponse
{
    /** @param list<ToolResponse> $tools */
    public function __construct(
        public array $tools,
        public string $requestId,
    ) {}

    /** @return array{tools: list<array<string, mixed>>, request_id: string} */
    public function toArray(): array
    {
        return ['tools' => array_map(static function (ToolResponse $tool): array {
                $data = $tool->toArray();
                unset($data['request_id']);

                return $data;
            }, $this->tools), 'request_id' => $this->requestId];
    }
}
