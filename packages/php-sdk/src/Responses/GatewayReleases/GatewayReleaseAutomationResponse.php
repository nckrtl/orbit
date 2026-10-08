<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\GatewayReleases;

use InvalidArgumentException;

/**
 * The state of automatic Gateway releases. `pause` and `lastTick` keep the Gateway's fields:
 * `pause` has `reason`, `since`, `record`, `release`, `sha`, `error_code`, and `snapshot`; `lastTick` has
 * `checked_at`, `result`, `sha`, `record`, `error_code`, and `message`.
 */
final readonly class GatewayReleaseAutomationResponse
{
    /**
     * @param  array<string, mixed>|null  $pause
     * @param  array<string, mixed>|null  $lastTick
     */
    public function __construct(
        public bool $enabled,
        public bool $paused,
        public ?array $pause,
        public ?string $currentRelease,
        public ?string $currentSha,
        public ?array $lastTick,
        public ?string $stalledSince,
        public ?string $branchHead,
        public ?string $behindSince,
        public string $branch,
        public string $check,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        $enabled = $data['enabled'] ?? null;
        $paused = $data['paused'] ?? null;

        if (! is_bool($enabled) || ! is_bool($paused)) {
            throw new InvalidArgumentException('Gateway response contains invalid automatic release state.');
        }

        return new self(
            enabled: $enabled,
            paused: $paused,
            pause: self::map($data['pause'] ?? null),
            currentRelease: self::text($data, 'current_release'),
            currentSha: self::text($data, 'current_sha'),
            lastTick: self::map($data['last_tick'] ?? null),
            stalledSince: self::text($data, 'stalled_since'),
            branchHead: self::text($data, 'branch_head'),
            behindSince: self::text($data, 'behind_since'),
            branch: self::text($data, 'branch') ?? '',
            check: self::text($data, 'check') ?? '',
            requestId: $requestId,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'paused' => $this->paused,
            'pause' => $this->pause,
            'current_release' => $this->currentRelease,
            'current_sha' => $this->currentSha,
            'last_tick' => $this->lastTick,
            'stalled_since' => $this->stalledSince,
            'branch_head' => $this->branchHead,
            'behind_since' => $this->behindSince,
            'branch' => $this->branch,
            'check' => $this->check,
            'request_id' => $this->requestId,
        ];
    }

    /** @param array<string, mixed> $data */
    private static function text(array $data, string $key): ?string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : null;
    }

    /** @return array<string, mixed>|null */
    private static function map(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $map = [];

        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }
}
