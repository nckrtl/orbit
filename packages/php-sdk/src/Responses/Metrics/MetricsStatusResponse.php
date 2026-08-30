<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Metrics;

use Orbit\Sdk\GatewayApiException;
use SensitiveParameter;

/**
 * @mago-expect lint:cyclomatic-complexity Bounded wire DTO parser
 * @mago-expect lint:excessive-parameter-list
 * @mago-expect lint:too-many-methods Bounded wire DTO parser
 */
final readonly class MetricsStatusResponse
{
    /**
     * @param array{id:int,node_id:int,node_name:string,status:string,failed_step:?string,error_code:?string}|null $assignment
     * @param list<array{id:int,name:string,desired:bool,actual:string,reason:string,degraded_reason:?string}> $exporters
     */
    private function __construct(
        public bool $enabled,
        public ?string $url,
        public ?array $assignment,
        public string $prometheus,
        public string $grafana,
        public array $exporters,
        public string $requestId,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        if (! is_bool($data['enabled'] ?? null)) {
            throw new GatewayApiException('Gateway response contains invalid metrics status.', requestId: $requestId);
        }

        $enabled = $data['enabled'];
        $url =
            is_string($data['url'] ?? null)
            && trim($data['url']) !== ''
            && strlen($data['url']) <= 2048
            && filter_var($data['url'], FILTER_VALIDATE_URL) !== false
                ? $data['url']
                : null;
        $assignment = self::assignment($data['assignment'] ?? null, $requestId);
        $prometheus = self::health($data['prometheus'] ?? null, $requestId);
        $grafana = self::health($data['grafana'] ?? null, $requestId);
        $exporters = self::exporters($data['exporters'] ?? [], $requestId);

        if (
            $enabled
            && ($url === null
            || $assignment === null
            || $prometheus === 'disabled'
            || $grafana === 'disabled')
            || ! $enabled
            && ($url !== null
            || $assignment !== null
            || $prometheus !== 'disabled'
            || $grafana !== 'disabled'
            || $exporters !== [])
        ) {
            throw new GatewayApiException('Gateway response contains invalid metrics status.', requestId: $requestId);
        }

        return new self(
            $enabled,
            $url,
            $assignment,
            $prometheus,
            $grafana,
            $exporters,
            $requestId,
        );
    }

    /**
     * @mago-expect lint:inline-variable-return Typed intermediate keeps static analysis precise.
     * @return array{id:int,node_id:int,node_name:string,status:string,failed_step:?string,error_code:?string}|null
     */
    private static function assignment(mixed $value, string $requestId): ?array
    {
        if ($value === null) {
            return null;
        }
        if (
            ! is_array($value)
            || ! self::positiveId($value['id'] ?? null)
            || ! self::positiveId($value['node_id'] ?? null)
            || ! is_string($value['node_name'] ?? null)
            || $value['node_name'] === ''
            || strlen($value['node_name']) > 255
            || ! self::validAssignmentStatus($value['status'] ?? null)
            || ! self::nullableText($value['failed_step'] ?? null)
            || ! self::nullableText($value['error_code'] ?? null)
        ) {
            throw new GatewayApiException(
                'Gateway response contains invalid metrics assignment.',
                requestId: $requestId,
            );
        }

        /** @var array{id:int,node_id:int,node_name:string,status:string,failed_step:?string,error_code:?string} $assignment */
        $assignment = [
            'id' => $value['id'],
            'node_id' => $value['node_id'],
            'node_name' => $value['node_name'],
            'status' => $value['status'],
            'failed_step' => $value['failed_step'] ?? null,
            'error_code' => $value['error_code'] ?? null,
        ];

        return $assignment;
    }

    /** @mago-expect analysis:mixed-assignment Gateway health values remain mixed until validated. */
    private static function health(mixed $value, string $requestId): string
    {
        if (is_string($value)) {
            return self::validatedHealth($value, $requestId);
        }

        if (is_array($value)) {
            $status = $value['status'] ?? null;

            if (is_string($status)) {
                return self::validatedHealth($status, $requestId);
            }
        }

        throw new GatewayApiException(
            'Gateway response contains invalid metrics service health.',
            requestId: $requestId,
        );
    }

    /**
     * @mago-expect analysis:mixed-assignment Gateway exporter values remain mixed until validated.
     * @return list<array{id:int,name:string,desired:bool,actual:string,reason:string,degraded_reason:?string}>
     */
    private static function exporters(mixed $value, string $requestId): array
    {
        if (! is_array($value) || count($value) > 10_000) {
            throw new GatewayApiException(
                'Gateway response contains invalid metrics exporters.',
                requestId: $requestId,
            );
        }
        $rows = [];
        foreach ($value as $row) {
            $id = is_array($row) ? $row['id'] ?? null : null;
            $name = is_array($row) ? $row['name'] ?? null : null;
            $desired = is_array($row) ? $row['desired'] ?? null : null;
            $actual = is_array($row) ? $row['actual'] ?? null : null;
            $reason = is_array($row) ? $row['reason'] ?? null : null;
            $degradedReason = is_array($row) ? $row['degraded_reason'] ?? null : null;

            if (
                ! is_array($row)
                || ! self::positiveId($id)
                || ! is_string($name)
                || $name === ''
                || strlen($name) > 255
                || ! is_bool($desired)
                || ! is_string($actual)
                || ! self::validActual($actual)
                || ! is_string($reason)
                || ! self::validReason($reason)
                || ! self::validDegradedReason($degradedReason)
            ) {
                throw new GatewayApiException(
                    'Gateway response contains invalid metrics exporter.',
                    requestId: $requestId,
                );
            }

            /** @var int $id */
            /** @var ?string $degradedReason */
            $rows[] = [
                'id' => $id,
                'name' => $name,
                'desired' => $desired,
                'actual' => $actual,
                'reason' => $reason,
                'degraded_reason' => $degradedReason,
            ];
        }

        return $rows;
    }

    private static function validAssignmentStatus(mixed $value): bool
    {
        return (
            is_string($value)
            && in_array(
                $value,
                ['active', 'failed', 'provisioning', 'removing'],
                strict: true,
            )
        );
    }

    private static function validActual(mixed $value): bool
    {
        return is_string($value) && in_array($value, ['active', 'inactive', 'drift', 'unknown'], strict: true);
    }

    private static function positiveId(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    private static function nullableText(mixed $value): bool
    {
        return $value === null || is_string($value) && $value !== '' && strlen($value) <= 255;
    }

    private static function validReason(mixed $value): bool
    {
        return (
            is_string($value)
            && in_array(
                $value,
                ['metrics_node', 'role_default', 'roleless_default_excluded', 'explicit_enabled', 'explicit_disabled'],
                strict: true,
            )
        );
    }

    private static function validDegradedReason(mixed $value): bool
    {
        return (
            $value === null
            || is_string($value) && in_array($value, ['unreachable', 'firewall_inactive'], strict: true)
        );
    }

    private static function validatedHealth(string $status, string $requestId): string
    {
        if (in_array($status, ['healthy', 'unhealthy', 'disabled', 'unknown'], strict: true)) {
            return $status;
        }

        throw new GatewayApiException(
            'Gateway response contains invalid metrics service health.',
            requestId: $requestId,
        );
    }

    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'url' => $this->url,
            'assignment' => $this->assignment,
            'prometheus' => $this->prometheus,
            'grafana' => $this->grafana,
            'exporters' => $this->exporters,
            'request_id' => $this->requestId,
        ];
    }
}
