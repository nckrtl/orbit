<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tools;

use InvalidArgumentException;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class ToolsResponse
{
    /** @var list<ToolResponse> */
    public array $tools;

    public string $requestId;

    /**
     * @mago-expect analysis:mixed-assignment Collection members remain mixed until validated.
     * @param array<array-key, mixed> $tools
     */
    public function __construct(
        #[SensitiveParameter]
        array $tools,
        #[SensitiveParameter]
        string $requestId,
    ) {
        if (! array_is_list($tools)) {
            throw new InvalidArgumentException('Invalid Tool response collection.');
        }

        foreach ($tools as $tool) {
            if (! $tool instanceof ToolResponse) {
                throw new InvalidArgumentException('Invalid Tool response collection member.');
            }
        }

        /** @var list<ToolResponse> $tools */
        $this->tools = $tools;
        $this->requestId = GatewayRequestId::fromTransport($requestId) ?? '';
    }

    /** @return array{tools: list<array<string, bool|int|string|null>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'tools' => array_map(
                static function (ToolResponse $tool): array {
                    $data = $tool->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->tools,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
