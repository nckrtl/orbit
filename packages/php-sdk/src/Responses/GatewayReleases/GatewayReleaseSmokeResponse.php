<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\GatewayReleases;

use InvalidArgumentException;

/**
 * One smoke run against the live Gateway. `outcome` is `passed` or `failed`. `report` keeps the JSON
 * of `bin/gateway-smoke`: `summary`, `checks` with each check's `status`, `error`, `message`, and
 * `duration_ms`, and on failure `failed_checks`, `message`, and `next`.
 */
final readonly class GatewayReleaseSmokeResponse
{
    /** @param array<string, mixed>|null $report */
    public function __construct(
        public ?string $release,
        public string $sha,
        public string $outcome,
        public ?array $report,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        $sha = $data['sha'] ?? null;
        $outcome = $data['outcome'] ?? null;

        if (! is_string($sha) || ! in_array($outcome, ['passed', 'failed'], true)) {
            throw new InvalidArgumentException('Gateway response contains an invalid smoke result.');
        }

        return new self(
            release: is_string($data['release'] ?? null) ? $data['release'] : null,
            sha: $sha,
            outcome: $outcome,
            report: self::map($data['report'] ?? null),
            requestId: $requestId,
        );
    }

    public function passed(): bool
    {
        return $this->outcome === 'passed';
    }

    /**
     * Each check of the report by name, with its status and message.
     *
     * @return array<string, array{status: string, error: string|null, message: string|null, duration_ms: int|null}>
     */
    public function checks(): array
    {
        $checks = [];

        foreach (self::map($this->report['checks'] ?? null) ?? [] as $name => $check) {
            if (! is_array($check) || ! is_string($check['status'] ?? null)) {
                continue;
            }

            $checks[$name] = [
                'status' => $check['status'],
                'error' => is_string($check['error'] ?? null) ? $check['error'] : null,
                'message' => is_string($check['message'] ?? null) ? $check['message'] : null,
                'duration_ms' => is_int($check['duration_ms'] ?? null) ? $check['duration_ms'] : null,
            ];
        }

        return $checks;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'release' => $this->release,
            'sha' => $this->sha,
            'outcome' => $this->outcome,
            'report' => $this->report,
            'request_id' => $this->requestId,
        ];
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
