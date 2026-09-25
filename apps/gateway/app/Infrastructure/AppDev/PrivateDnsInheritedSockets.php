<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use RuntimeException;

/**
 * Reads the UDP and TCP sockets that `orbit-private-dns.socket` passes to the listener (systemd socket activation).
 */
final class PrivateDnsInheritedSockets
{
    private const int FirstDescriptor = 3;

    /**
     * @param  array<string, string|false>|null  $environment
     * @return array{0: resource, 1: resource}|null The UDP and TCP streams, or null when systemd passed none.
     */
    public static function fromEnvironment(?array $environment = null, ?int $pid = null): ?array
    {
        $count = self::value($environment, 'LISTEN_FDS');
        $owner = self::value($environment, 'LISTEN_PID');

        if ($count === null || $owner === null || (int) $owner !== ($pid ?? getmypid())) {
            return null;
        }

        $udp = null;
        $tcp = null;
        for ($descriptor = self::FirstDescriptor; $descriptor < self::FirstDescriptor + (int) $count; $descriptor++) {
            [$type, $stream] = self::import($descriptor);
            if ($type === SOCK_DGRAM) {
                $udp = $stream;
            } elseif ($type === SOCK_STREAM) {
                $tcp = $stream;
            }
        }

        if (! is_resource($udp) || ! is_resource($tcp)) {
            throw new RuntimeException('systemd did not pass one UDP and one TCP socket to the private DNS listener.');
        }

        return [$udp, $tcp];
    }

    /**
     * @return array{0: int, 1: resource}
     */
    private static function import(int $descriptor): array
    {
        $raw = @fopen('php://fd/'.$descriptor, 'r+');
        $socket = is_resource($raw) ? @socket_import_stream($raw) : false;
        if ($socket === false || $socket === null) {
            throw new RuntimeException("Could not import inherited socket descriptor {$descriptor}.");
        }

        $type = socket_get_option($socket, SOL_SOCKET, SO_TYPE);
        $stream = socket_export_stream($socket);
        if (! is_int($type) || ! is_resource($stream)) {
            throw new RuntimeException("Could not use inherited socket descriptor {$descriptor}.");
        }

        return [$type, $stream];
    }

    /**
     * @param  array<string, string|false>|null  $environment
     */
    private static function value(?array $environment, string $name): ?string
    {
        $value = $environment === null ? getenv($name) : ($environment[$name] ?? false);

        return is_string($value) && ctype_digit($value) ? $value : null;
    }
}
