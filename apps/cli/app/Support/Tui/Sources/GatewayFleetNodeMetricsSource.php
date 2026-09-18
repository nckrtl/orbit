<?php

declare(strict_types=1);

namespace App\Support\Tui\Sources;

use App\Support\Tui\Sources\Concerns\LimitsBackgroundRequestTime;
use Closure;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\Metrics\ListMetricsNodesRequest;
use Orbit\Sdk\Responses\Metrics\FleetNodeMetricsResponse;
use Orbit\Sdk\Responses\Metrics\MetricsNodesResponse;

/** The fleet-wide metrics snapshot `orbit top`'s dashboard renders, from `GET /metrics/nodes`. */
final readonly class GatewayFleetNodeMetricsSource implements FleetNodeMetricsSource
{
    use LimitsBackgroundRequestTime;

    private const int BYTES_PER_GIB = 1024 ** 3;

    /** @param  Closure(object, string): object  $send  Same shape as GatewayCommand::sendOrThrow(). */
    public function __construct(private Closure $send) {}

    #[\Override]
    public function forFleet(): array
    {
        try {
            $response = ($this->send)(self::withBackgroundTimeout(new ListMetricsNodesRequest), MetricsNodesResponse::class);
        } catch (GatewayApiException) {
            return [];
        }

        assert($response instanceof MetricsNodesResponse);

        $metrics = [];

        foreach ($response->nodes as $node) {
            if ($node->available) {
                $metrics[$node->nodeId] = self::snapshot($node);
            }
        }

        return $metrics;
    }

    /**
     * @return array{
     *     cores: list<float>,
     *     mem: array{float, float},
     *     swap: array{float, float},
     *     uptime: string,
     *     disks: list<array{string, float, float}>,
     * }
     */
    private static function snapshot(FleetNodeMetricsResponse $node): array
    {
        return [
            'cores' => $node->cores,
            'mem' => [self::gib($node->memory['used']), self::gib($node->memory['total'])],
            'swap' => [self::gib($node->swap['used']), self::gib($node->swap['total'])],
            'uptime' => self::uptime($node->uptimeSeconds),
            'disks' => self::disksRootFirst($node->disks),
        ];
    }

    /**
     * Same root-first ordering `GatewayNodeMetricsSource` applies: put the `/` mount first when
     * reported, otherwise the largest filesystem that is not a pseudo mount.
     *
     * @param  list<array{mount: string, used: int, total: int}>  $disks
     * @return list<array{string, float, float}>
     */
    private static function disksRootFirst(array $disks): array
    {
        $mapped = array_map(
            static fn (array $disk): array => [$disk['mount'], self::gib($disk['used']), self::gib($disk['total'])],
            $disks,
        );

        $rootIndex = null;

        foreach ($mapped as $index => $disk) {
            if ($disk[0] === '/') {
                $rootIndex = $index;

                break;
            }
        }

        if ($rootIndex === null) {
            $largestTotal = -1.0;

            foreach ($mapped as $index => $disk) {
                if (self::isPseudoMount($disk[0])) {
                    continue;
                }

                if ($disk[2] > $largestTotal) {
                    $largestTotal = $disk[2];
                    $rootIndex = $index;
                }
            }
        }

        if ($rootIndex === null || $rootIndex === 0) {
            return $mapped;
        }

        $selected = $mapped[$rootIndex];
        unset($mapped[$rootIndex]);

        return [$selected, ...array_values($mapped)];
    }

    private static function isPseudoMount(string $mount): bool
    {
        return array_any(['/sys', '/proc', '/dev', '/run'], static fn (string $prefix): bool => $mount === $prefix || str_starts_with($mount, "{$prefix}/"));
    }

    private static function gib(int $bytes): float
    {
        return $bytes / self::BYTES_PER_GIB;
    }

    private static function uptime(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return match (true) {
            $days > 0 => "{$days}d {$hours}h {$minutes}m",
            $hours > 0 => "{$hours}h {$minutes}m",
            default => "{$minutes}m",
        };
    }
}
