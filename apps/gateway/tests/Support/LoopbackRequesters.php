<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Socket;

/**
 * Picks two distinct loopback source addresses that reach one private DNS listener.
 *
 * Private DNS tells requesters apart by source address, so its transport tests send from two addresses. Linux
 * routes all of 127.0.0.0/8 to loopback, so they use 127.0.0.2 and 127.0.0.3 to a listener on 127.0.0.1. macOS
 * assigns only 127.0.0.1 to lo0 but also ::1 and fe80::1, so there they use those to a listener on ::1. Both
 * pairs stay on the loopback interface and need no host setup.
 */
final readonly class LoopbackRequesters
{
    private function __construct(
        /** The listener address in stream URI form, such as `127.0.0.1` or `[::1]`. */
        public string $listen,
        /** The address a query socket sends to. */
        public string $destination,
        /** The address the listener sees for the first source. */
        public string $first,
        /** The address the listener sees for the second source. */
        public string $second,
        private string $firstBind,
        private string $secondBind,
        private int $family,
    ) {}

    public static function detect(): self
    {
        if (self::bindable(AF_INET, '127.0.0.2') && self::bindable(AF_INET, '127.0.0.3')) {
            return new self('127.0.0.1', '127.0.0.1', '127.0.0.2', '127.0.0.3', '127.0.0.2', '127.0.0.3', AF_INET);
        }

        if (self::bindable(AF_INET6, '::1') && self::bindable(AF_INET6, 'fe80::1%lo0')) {
            return new self('[::1]', '::1', 'fe80::1', '::1', 'fe80::1%lo0', '::1', AF_INET6);
        }

        throw new RuntimeException(
            'The private DNS transport tests need two loopback source addresses: 127.0.0.2 and 127.0.0.3, '
            .'or ::1 and fe80::1 on lo0. The test host offers neither pair.',
        );
    }

    /** Returns a bound, unconnected socket for the first or second source. */
    public function socket(string $source, int $type): Socket
    {
        $bind = match ($source) {
            $this->first => $this->firstBind,
            $this->second => $this->secondBind,
            default => throw new RuntimeException("[{$source}] is not one of this pair's sources."),
        };
        $socket = socket_create($this->family, $type, $type === SOCK_STREAM ? SOL_TCP : SOL_UDP);

        if ($socket === false || ! socket_bind($socket, $bind, 0)) {
            throw new RuntimeException("Could not bind a query socket to [{$bind}].");
        }

        return $socket;
    }

    private static function bindable(int $family, string $address): bool
    {
        $socket = socket_create($family, SOCK_DGRAM, SOL_UDP);

        if ($socket === false) {
            return false;
        }

        try {
            return @socket_bind($socket, $address, 0);
        } finally {
            socket_close($socket);
        }
    }
}
