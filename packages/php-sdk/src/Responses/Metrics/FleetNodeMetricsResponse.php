<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Metrics;

/**
 * One Node's row in `GET /api/v1/metrics/nodes`: the same fields `NodeMetricsResponse` carries
 * for one Node, plus whether the Metrics role's Prometheus had samples for it. `available` is
 * false, and `reason` names why (`no_exporter` or `no_samples`), instead of the row being
 * omitted; the metric fields are zeroed in that case and must not be read.
 */
final readonly class FleetNodeMetricsResponse
{
    /**
     * @param  list<float>  $cores  Per-core busy ratio from 0 to 1.
     * @param  array{used: int, total: int}  $memory  Bytes.
     * @param  array{used: int, total: int}  $swap  Bytes.
     * @param  array{one: float, five: float, fifteen: float}  $load
     * @param  array{cpu: array{some_avg10: float}, memory: array{some_avg10: float}, io: array{some_avg10: float}}  $pressure
     * @param  list<array{mount: string, used: int, total: int}>  $disks  Bytes.
     */
    public function __construct(
        public int $nodeId,
        public string $nodeName,
        public bool $available,
        public ?string $reason,
        public array $cores,
        public array $memory,
        public array $swap,
        public array $load,
        public int $uptimeSeconds,
        public array $pressure,
        public array $disks,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data): self
    {
        return new self(
            nodeId: is_int($data['node_id'] ?? null) ? $data['node_id'] : 0,
            nodeName: is_string($data['node_name'] ?? null) ? $data['node_name'] : '',
            available: (bool) ($data['available'] ?? false),
            reason: is_string($data['reason'] ?? null) ? $data['reason'] : null,
            cores: self::floatList($data['cores'] ?? null),
            memory: self::usedTotal($data['memory'] ?? null),
            swap: self::usedTotal($data['swap'] ?? null),
            load: self::load($data['load'] ?? null),
            uptimeSeconds: is_int($data['uptime_seconds'] ?? null) ? $data['uptime_seconds'] : 0,
            pressure: self::pressure($data['pressure'] ?? null),
            disks: self::disks($data['disks'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'node_name' => $this->nodeName,
            'available' => $this->available,
            'reason' => $this->reason,
            'cores' => $this->cores,
            'memory' => $this->memory,
            'swap' => $this->swap,
            'load' => $this->load,
            'uptime_seconds' => $this->uptimeSeconds,
            'pressure' => $this->pressure,
            'disks' => $this->disks,
        ];
    }

    /** @return list<float> */
    private static function floatList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $item): float => is_numeric($item) ? (float) $item : 0.0,
            $value,
        ));
    }

    /** @return array{used: int, total: int} */
    private static function usedTotal(mixed $value): array
    {
        if (! is_array($value)) {
            return ['used' => 0, 'total' => 0];
        }

        return [
            'used' => is_int($value['used'] ?? null) ? $value['used'] : 0,
            'total' => is_int($value['total'] ?? null) ? $value['total'] : 0,
        ];
    }

    /** @return array{one: float, five: float, fifteen: float} */
    private static function load(mixed $value): array
    {
        if (! is_array($value)) {
            return ['one' => 0.0, 'five' => 0.0, 'fifteen' => 0.0];
        }

        return [
            'one' => is_numeric($value['one'] ?? null) ? (float) $value['one'] : 0.0,
            'five' => is_numeric($value['five'] ?? null) ? (float) $value['five'] : 0.0,
            'fifteen' => is_numeric($value['fifteen'] ?? null) ? (float) $value['fifteen'] : 0.0,
        ];
    }

    /** @return array{cpu: array{some_avg10: float}, memory: array{some_avg10: float}, io: array{some_avg10: float}} */
    private static function pressure(mixed $value): array
    {
        $section = static function (mixed $data): array {
            $avg10 = is_array($data) ? ($data['some_avg10'] ?? null) : null;

            return ['some_avg10' => is_numeric($avg10) ? (float) $avg10 : 0.0];
        };

        return [
            'cpu' => $section(is_array($value) ? ($value['cpu'] ?? null) : null),
            'memory' => $section(is_array($value) ? ($value['memory'] ?? null) : null),
            'io' => $section(is_array($value) ? ($value['io'] ?? null) : null),
        ];
    }

    /** @return list<array{mount: string, used: int, total: int}> */
    private static function disks(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $disks = [];

        foreach ($value as $disk) {
            if (! is_array($disk)) {
                continue;
            }

            $disks[] = [
                'mount' => is_string($disk['mount'] ?? null) ? $disk['mount'] : '',
                'used' => is_int($disk['used'] ?? null) ? $disk['used'] : 0,
                'total' => is_int($disk['total'] ?? null) ? $disk['total'] : 0,
            ];
        }

        return $disks;
    }
}
