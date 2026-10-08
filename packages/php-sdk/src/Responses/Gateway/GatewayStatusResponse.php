<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Gateway;

/**
 * Gateway status. `release` and `releaseSha` name the release the Gateway runs from, or are null
 * for a Gateway outside the release layout. `autoRelease` holds `enabled`, `paused`,
 * `last_checked_at`, and `last_result`, or is null when the Gateway could not read that state.
 */
final readonly class GatewayStatusResponse
{
    /** @param array{enabled: bool, paused: bool, last_checked_at: string|null, last_result: string|null}|null $autoRelease */
    public function __construct(
        public string $name,
        public string $status,
        public string $version,
        public string $phpVersion,
        public string $laravelVersion,
        public string $requestId,
        /** Shown to an active WireGuard peer only; null for any other caller and for an older Gateway. */
        public ?DesiredFleetStateResponse $desiredFleetState = null,
        public ?string $release = null,
        public ?string $releaseSha = null,
        public ?array $autoRelease = null,
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
            'release' => $this->release,
            'release_sha' => $this->releaseSha,
            'auto_release' => $this->autoRelease,
            'request_id' => $this->requestId,
        ];
    }
}
