<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Analytics;

use Orbit\Sdk\GatewayApiException;
use SensitiveParameter;

/**
 * The tracking hosts an App instance publishes for the analytics role, with what the operator
 * needs next: the DNS record for each host and the script tag for the App.
 */
final readonly class InstanceAnalyticsResponse
{
    /** @param list<array{host: string, route_id: int, status: string, publication: string, failed_step: ?string, error_code: ?string, script_url: string, event_url: string, dns: ?array{type: string, name: string, value: string}}> $hosts */
    public function __construct(
        public int $instanceId,
        public bool $enabled,
        public ?string $domain,
        public ?string $dashboardUrl,
        public array $hosts,
        public ?string $snippet,
        public string $requestId,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $instanceId = $data['instance_id'] ?? null;
        $enabled = $data['enabled'] ?? null;
        $domain = $data['domain'] ?? null;
        $dashboardUrl = $data['dashboard_url'] ?? null;
        $snippet = $data['snippet'] ?? null;
        $hosts = $data['hosts'] ?? null;

        if (
            ! is_int($instanceId)
            || ! is_bool($enabled)
            || ($domain !== null && ! is_string($domain))
            || ($dashboardUrl !== null && ! is_string($dashboardUrl))
            || ($snippet !== null && ! is_string($snippet))
            || ! is_array($hosts)
            || ! array_is_list($hosts)
        ) {
            throw self::invalid($requestId);
        }

        $normalized = [];

        foreach ($hosts as $host) {
            if (! is_array($host)) {
                throw self::invalid($requestId);
            }

            $dns = $host['dns'] ?? null;
            $failedStep = $host['failed_step'] ?? null;
            $errorCode = $host['error_code'] ?? null;

            if (
                ! is_string($host['host'] ?? null)
                || ! is_int($host['route_id'] ?? null)
                || ! is_string($host['status'] ?? null)
                || ! is_string($host['publication'] ?? null)
                || ($failedStep !== null && ! is_string($failedStep))
                || ($errorCode !== null && ! is_string($errorCode))
                || ! is_string($host['script_url'] ?? null)
                || ! is_string($host['event_url'] ?? null)
                || ($dns !== null && (
                    ! is_array($dns)
                    || ! is_string($dns['type'] ?? null)
                    || ! is_string($dns['name'] ?? null)
                    || ! is_string($dns['value'] ?? null)
                ))
            ) {
                throw self::invalid($requestId);
            }

            $normalized[] = [
                'host' => $host['host'],
                'route_id' => $host['route_id'],
                'status' => $host['status'],
                'publication' => $host['publication'],
                'failed_step' => $failedStep,
                'error_code' => $errorCode,
                'script_url' => $host['script_url'],
                'event_url' => $host['event_url'],
                // Null while the App instance has no domain to point the host at.
                'dns' => $dns === null ? null : ['type' => $dns['type'], 'name' => $dns['name'], 'value' => $dns['value']],
            ];
        }

        return new self(
            $instanceId,
            $enabled,
            $domain,
            $dashboardUrl,
            $normalized,
            $snippet,
            $requestId,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'instance_id' => $this->instanceId,
            'enabled' => $this->enabled,
            'domain' => $this->domain,
            'dashboard_url' => $this->dashboardUrl,
            'hosts' => $this->hosts,
            'snippet' => $this->snippet,
            'request_id' => $this->requestId,
        ];
    }

    private static function invalid(string $requestId): GatewayApiException
    {
        return new GatewayApiException(
            'Gateway response contains invalid instance analytics data.',
            requestId: $requestId,
        );
    }
}
