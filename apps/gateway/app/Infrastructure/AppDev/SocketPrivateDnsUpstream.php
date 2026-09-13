<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\PrivateDnsUpstream;
use RuntimeException;

final readonly class SocketPrivateDnsUpstream implements PrivateDnsUpstream
{
    private const int MaxUdpDatagramBytes = 65535;

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
            stream_set_blocking($socket, false);
            $this->writeAll($socket, $message);
            if (! $this->await($socket, write: false)) {
                return null;
            }

            $response = fread($socket, self::MaxUdpDatagramBytes);

            return is_string($response) && $response !== '' ? $response : null;
        } catch (RuntimeException) {
            return null;
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
            stream_set_blocking($socket, false);
            $this->writeAll($socket, pack('n', strlen($message)).$message);
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
        $deadline = microtime(true) + $this->timeoutSeconds;

        while (strlen($buffer) < $bytes) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0 || ! $this->await($socket, write: false, timeoutSeconds: $remaining)) {
                throw new RuntimeException('The private DNS upstream TCP response timed out.');
            }

            $chunk = fread($socket, $bytes - strlen($buffer));
            if (! is_string($chunk) || $chunk === '') {
                throw new RuntimeException('The private DNS upstream TCP response was truncated.');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * @param  resource  $socket
     */
    private function writeAll($socket, string $payload): void
    {
        $written = 0;
        $deadline = microtime(true) + $this->timeoutSeconds;

        while ($written < strlen($payload)) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0 || ! $this->await($socket, write: true, timeoutSeconds: $remaining)) {
                throw new RuntimeException('The private DNS upstream write timed out.');
            }

            $chunk = fwrite($socket, substr($payload, $written));
            if (! is_int($chunk) || $chunk < 1) {
                throw new RuntimeException('The private DNS upstream write failed.');
            }

            $written += $chunk;
        }
    }

    /**
     * @param  resource  $socket
     */
    private function await($socket, bool $write, ?float $timeoutSeconds = null): bool
    {
        $timeout = $timeoutSeconds ?? $this->timeoutSeconds;
        $read = $write ? [] : [$socket];
        $writeables = $write ? [$socket] : [];
        $except = [];
        [$seconds, $microseconds] = $this->timeoutParts($timeout);
        $ready = @stream_select($read, $writeables, $except, $seconds, $microseconds);

        return $ready === 1;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function timeoutParts(float $seconds): array
    {
        $clamped = max(0.0, $seconds);
        $whole = (int) $clamped;
        $microseconds = (int) round(($clamped - $whole) * 1_000_000);
        if ($microseconds === 1_000_000) {
            return [$whole + 1, 0];
        }

        return [$whole, $microseconds];
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
