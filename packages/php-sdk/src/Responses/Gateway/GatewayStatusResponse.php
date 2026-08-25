<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Gateway;

/** @mago-expect lint:excessive-parameter-list */
final readonly class GatewayStatusResponse
{
    public function __construct(
        public string $name,
        public string $status,
        public string $version,
        public string $phpVersion,
        public string $laravelVersion,
        public string $requestId,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status,
            'version' => $this->version,
            'php_version' => $this->phpVersion,
            'laravel_version' => $this->laravelVersion,
            'request_id' => $this->requestId,
        ];
    }
}
