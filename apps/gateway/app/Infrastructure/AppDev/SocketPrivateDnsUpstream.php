<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\PrivateDnsUpstream;
use RuntimeException;

final readonly class SocketPrivateDnsUpstream implements PrivateDnsUpstream
{
    public function __construct(
        private string $host,
        private int $port,
        private float $timeoutSeconds = 2.0,
    ) {}

    public function resolve(string $message): string
    {
        $udp = $this->queryUdp($message);
        if ($udp !== null && ! $this->truncated($udp)) {
            return $udp;
        }

        return $this->queryTcp($message);
    }

    private function queryUdp(string $message): ?string
    {
        $socket = @stream_socket_client(
            'udp://'.$this->host.':'.$this->port,
            $error,
            $errorMessage,
            $this->timeoutSeconds,
        );
        if (! is_resource($socket)) {
            return null;
        }

        try {
            stream_set_timeout($socket, (int) $this->timeoutSeconds, (int) (($this->timeoutSeconds - (int) $this->timeoutSeconds) * 1_000_000));
            fwrite($socket, $message);
            $response = stream_get_contents($socket);

            return is_string($response) && $response !== '' ? $response : null;
        } finally {
            fclose($socket);
        }
    }

    private function queryTcp(string $message): string
    {
        $socket = @stream_socket_client(
            'tcp://'.$this->host.':'.$this->port,
            $error,
            $errorMessage,
            $this->timeoutSeconds,
        );
        if (! is_resource($socket)) {
            throw new RuntimeException($errorMessage !== '' ? $errorMessage : 'Could not reach the private DNS upstream.');
        }

        try {
            stream_set_timeout($socket, (int) $this->timeoutSeconds, (int) (($this->timeoutSeconds - (int) $this->timeoutSeconds) * 1_000_000));
            fwrite($socket, pack('n', strlen($message)).$message);
            $lengthBytes = $this->readExact($socket, 2);
            $length = unpack('nlen', $lengthBytes);
            if ($length === false) {
                throw new RuntimeException('The private DNS upstream TCP response was truncated.');
            }

            return $this->readExact($socket, $length['len']);
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param  resource  $socket
     */
    private function readExact($socket, int $bytes): string
    {
        $buffer = '';

        while (strlen($buffer) < $bytes) {
            $chunk = fread($socket, $bytes - strlen($buffer));
            if (! is_string($chunk) || $chunk === '') {
                throw new RuntimeException('The private DNS upstream TCP response was truncated.');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function truncated(string $message): bool
    {
        if (strlen($message) < 4) {
            return true;
        }

        $header = unpack('nid/nflags', substr($message, 0, 4));

        return $header !== false && ($header['flags'] & 0x0200) === 0x0200;
    }
}
