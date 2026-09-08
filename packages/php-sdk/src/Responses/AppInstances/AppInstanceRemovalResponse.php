<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\AppInstances;

use SensitiveParameter;

final readonly class AppInstanceRemovalResponse
{
    public function __construct(
        public AppInstanceRemovalProgressResponse $removal,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        return new self(AppInstanceRemovalProgressResponse::fromGatewayData($data), $requestId);
    }

    /** @return array<string, bool|int|string|null> */
    public function toArray(): array
    {
        return [...$this->removal->toArray(), 'request_id' => $this->requestId];
    }
}
