<?php

declare(strict_types=1);

namespace App\Support\Tui\Sources;

use Closure;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\Nodes\ShowNodeMetricsRequest;
use Orbit\Sdk\Responses\Nodes\NodeMetricsResponse;

/** The compact CPU, memory, swap, and disk snapshot for one node, from `GET /nodes/{node}/metrics`. */
final readonly class GatewayNodeMetricsSource implements NodeMetricsSource
{
    private const int BYTES_PER_GIB = 1024 ** 3;

    /** @param  Closure(object, string): object  $send  Same shape as GatewayCommand::sendOrThrow(). */
    public function __construct(private Closure $send) {}

    #[\Override]
    public function forNode(int $nodeId): ?array
    {
        try {
            $response = ($this->send)(new ShowNodeMetricsRequest($nodeId), NodeMetricsResponse::class);
        } catch (GatewayApiException) {
            return null;
        }

        assert($response instanceof NodeMetricsResponse);

        return [
            'cores' => $response->cores,
            'mem' => [self::gib($response->memory['used']), self::gib($response->memory['total'])],
            'swap' => [self::gib($response->swap['used']), self::gib($response->swap['total'])],
            'uptime' => self::uptime($response->uptimeSeconds),
            'disks' => array_map(
                static fn (array $disk): array => [$disk['mount'], self::gib($disk['used']), self::gib($disk['total'])],
                $response->disks,
            ),
        ];
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
