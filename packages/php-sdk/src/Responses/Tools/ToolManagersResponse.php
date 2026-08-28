<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tools;

use InvalidArgumentException;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class ToolManagersResponse
{
    /** @var list<ToolManagerResponse> */
    public array $managers;

    public string $requestId;

    /**
     * @mago-expect analysis:mixed-assignment Collection members remain mixed until validated.
     * @param array<array-key, mixed> $managers
     */
    public function __construct(
        #[SensitiveParameter]
        array $managers,
        #[SensitiveParameter]
        string $requestId,
    ) {
        if (! array_is_list($managers)) {
            throw new InvalidArgumentException('Invalid Tool manager response collection.');
        }

        foreach ($managers as $manager) {
            if (! $manager instanceof ToolManagerResponse) {
                throw new InvalidArgumentException('Invalid Tool manager response collection member.');
            }
        }

        /** @var list<ToolManagerResponse> $managers */
        $this->managers = $managers;
        $this->requestId = GatewayRequestId::fromTransport($requestId) ?? '';
    }

    /** @return array{managers: list<array<string, int|string|null>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'managers' => array_map(
                static function (ToolManagerResponse $manager): array {
                    $data = $manager->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->managers,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
