<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Instances;

use SensitiveParameter;

final readonly class InstanceRemovalResponse
{
    public function __construct(
        public InstanceRemovalProgressResponse $removal,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        return new self(InstanceRemovalProgressResponse::fromGatewayData($data), $requestId);
    }

    /** @return array<string, bool|int|string|null> */
    public function toArray(): array
    {
        return [...$this->removal->toArray(), 'request_id' => $this->requestId];
    }
}
