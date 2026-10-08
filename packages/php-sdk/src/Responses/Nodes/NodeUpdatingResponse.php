<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

/**
 * The update the Gateway runs on a Node now: `fleet_rollout` with the rollout id, or `gateway_release` with the
 * release record id. `since` is when it started, as ISO 8601.
 */
final readonly class NodeUpdatingResponse
{
    public const string FleetRollout = 'fleet_rollout';

    public const string GatewayRelease = 'gateway_release';

    public function __construct(
        public string $kind,
        public string $since,
        public ?int $rollout = null,
        public ?int $release = null,
    ) {}

    public static function tryFromGatewayData(mixed $data): ?self
    {
        if (
            ! is_array($data)
            || ! in_array($data['kind'] ?? null, [self::FleetRollout, self::GatewayRelease], true)
            || ! is_string($data['since'] ?? null)
            || $data['since'] === ''
        ) {
            return null;
        }

        return new self(
            kind: $data['kind'],
            since: $data['since'],
            rollout: is_int($data['rollout'] ?? null) ? $data['rollout'] : null,
            release: is_int($data['release'] ?? null) ? $data['release'] : null,
        );
    }

    /** @return array{kind: string, since: string, rollout: int|null, release: int|null} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'since' => $this->since,
            'rollout' => $this->rollout,
            'release' => $this->release,
        ];
    }
}
