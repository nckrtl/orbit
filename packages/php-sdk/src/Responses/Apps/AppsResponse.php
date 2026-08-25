<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Apps;

final readonly class AppsResponse
{
    /** @param list<AppResponse> $apps */
    public function __construct(
        public array $apps,
        public string $requestId,
    ) {}

    /** @return array{apps: list<array<string, mixed>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'apps' => array_map(
                static function (AppResponse $app): array {
                    $data = $app->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->apps,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
