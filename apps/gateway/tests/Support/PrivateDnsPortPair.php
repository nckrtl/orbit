<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Holds a UDP and a TCP socket on one port, as the private DNS listener binds them. The kernel picks the
 * port, so no other process can hold it: a port chosen and closed first could be taken before it is bound.
 * The holder process of a test requires this file on its own, so it uses nothing else.
 */
final class PrivateDnsPortPair
{
    /** @return array{resource, resource, int} */
    public static function hold(string $address): array
    {
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $udp = stream_socket_server("udp://{$address}:0", $errorCode, $error, STREAM_SERVER_BIND);

            if (! is_resource($udp)) {
                throw new RuntimeException("Could not bind a UDP port: {$error}");
            }

            $name = (string) stream_socket_get_name($udp, false);
            $port = (int) substr($name, strrpos($name, ':') + 1);
            // The UDP port can already be in use for TCP on a busy host, so take a new pair then.
            $tcp = @stream_socket_server("tcp://{$address}:{$port}", $errorCode, $error);

            if (is_resource($tcp)) {
                return [$udp, $tcp, $port];
            }

            fclose($udp);
        }

        throw new RuntimeException('Could not hold a UDP and TCP port pair.');
    }
}
