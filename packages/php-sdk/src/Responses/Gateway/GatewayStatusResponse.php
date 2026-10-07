<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Gateway;

final readonly class GatewayStatusResponse
{
    public function __construct(
        public string $name,
        public string $status,
        public string $version,
        public string $phpVersion,
        public string $laravelVersion,
        public string $requestId,
        /** Shown to an active WireGuard peer only; null for any other caller and for an older Gateway. */
        public ?DesiredFleetStateResponse $desiredFleetState = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status,
            'version' => $this->version,
            'php_version' => $this->phpVersion,
            'laravel_version' => $this->laravelVersion,
            'desired_fleet_state' => $this->desiredFleetState?->toArray(),
            'request_id' => $this->requestId,
        ];
    }
}
