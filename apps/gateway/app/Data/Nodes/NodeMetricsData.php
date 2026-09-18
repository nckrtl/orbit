<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class NodeMetricsData extends Data
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
        public array $cores,
        public array $memory,
        public array $swap,
        public array $load,
        public int $uptimeSeconds,
        public array $pressure,
        public array $disks,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromRaw(array $raw): self
    {
        return new self(
            cores: self::floatList($raw['cores'] ?? null),
            memory: self::usedTotal($raw['memory'] ?? null),
            swap: self::usedTotal($raw['swap'] ?? null),
            load: self::load($raw['load'] ?? null),
            uptimeSeconds: is_int($raw['uptime_seconds'] ?? null) ? $raw['uptime_seconds'] : 0,
            pressure: self::pressure($raw['pressure'] ?? null),
            disks: self::disks($raw['disks'] ?? null),
        );
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
