<?php

declare(strict_types=1);

namespace App\Support\Tui\Sources;

use App\Support\Tui\Sources\Concerns\LimitsBackgroundRequestTime;
use Closure;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\Nodes\ShowNodeMetricsRequest;
use Orbit\Sdk\Responses\Nodes\NodeMetricsResponse;

/** The compact CPU, memory, swap, and disk snapshot for one node, from `GET /nodes/{node}/metrics`. */
final readonly class GatewayNodeMetricsSource implements NodeMetricsSource
{
    use LimitsBackgroundRequestTime;

    private const int BYTES_PER_GIB = 1024 ** 3;

    /** @param  Closure(object, string): object  $send  Same shape as GatewayCommand::sendOrThrow(). */
    public function __construct(private Closure $send) {}

    #[\Override]
    public function forNode(int $nodeId): ?array
    {
        try {
            $response = ($this->send)(self::withBackgroundTimeout(new ShowNodeMetricsRequest($nodeId)), NodeMetricsResponse::class);
        } catch (GatewayApiException) {
            return null;
        }

        assert($response instanceof NodeMetricsResponse);

        return [
            'cores' => $response->cores,
            'mem' => [self::gib($response->memory['used']), self::gib($response->memory['total'])],
            'swap' => [self::gib($response->swap['used']), self::gib($response->swap['total'])],
            'uptime' => self::uptime($response->uptimeSeconds),
            'disks' => self::disksRootFirst($response->disks),
        ];
    }

    /**
     * Screen only ever shows `disks[0]` as "the" disk. `df` (what the Node's metrics probe
     * shells out to) lists mounts in kernel mount order, not by size or significance, so the
     * root filesystem is not reliably first — on a real Node it can follow pseudo-filesystems
     * like `/sys/firmware/efi/efivars`. Put the `/` mount first when the Node reports one;
     * otherwise fall back to the largest filesystem that is not a pseudo mount under
     * `/sys`, `/proc`, `/dev`, or `/run`.
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
        foreach (['/sys', '/proc', '/dev', '/run'] as $prefix) {
            if ($mount === $prefix || str_starts_with($mount, "{$prefix}/")) {
                return true;
            }
        }

        return false;
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
