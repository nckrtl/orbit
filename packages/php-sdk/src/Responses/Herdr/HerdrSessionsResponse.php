<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Herdr;

final readonly class HerdrSessionsResponse
{
    /** @param list<HerdrSessionResponse> $sessions */
    public function __construct(
        public array $sessions,
        public string $requestId,
    ) {}

    /** @return array{sessions: list<array<string, mixed>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'sessions' => array_map(
                static function (HerdrSessionResponse $session): array {
                    $data = $session->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->sessions,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
