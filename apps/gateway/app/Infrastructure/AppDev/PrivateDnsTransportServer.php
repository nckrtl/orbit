<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use Closure;
use RuntimeException;
use Throwable;

final class PrivateDnsTransportServer
{
    private const int EphemeralBindAttempts = 5;

    private const int MaxUdpDatagramsPerWake = 64;

    private const int MaxUdpDatagramBytes = 4096;

    /** @var resource|null */
    private $udp;

    /** @var resource|null */
    private $tcp;

    public function __construct(
        private readonly PrivateDnsRequestHandler $handler,
        private readonly string $listenAddress = '127.0.0.1',
        private int $port = 0,
        private readonly ?Closure $onIdle = null,
        private readonly float $ioTimeoutSeconds = 2.0,
    ) {}

    public function start(): void
    {
        $requested = $this->port;

        // An ephemeral UDP port can already be in use for TCP on a busy host, so pick a new pair.
        for ($attempt = 1; ; $attempt++) {
            $udp = @stream_socket_server(
                'udp://'.$this->listenAddress.':'.$requested,
                $udpError,
                $udpMessage,
                STREAM_SERVER_BIND,
            );
            if (! is_resource($udp)) {
                throw new RuntimeException($udpMessage !== '' ? $udpMessage : 'Could not bind the private DNS UDP socket.');
            }

            $name = stream_socket_get_name($udp, false);
            if (! is_string($name) || ! str_contains($name, ':')) {
                fclose($udp);

                throw new RuntimeException('Could not determine the private DNS UDP port.');
            }

            $this->port = (int) substr($name, strrpos($name, ':') + 1);
            $tcp = @stream_socket_server(
                'tcp://'.$this->listenAddress.':'.$this->port,
                $tcpError,
                $tcpMessage,
            );
            if (is_resource($tcp)) {
                break;
            }

            fclose($udp);
            $this->port = $requested;
            if ($requested !== 0 || $attempt >= self::EphemeralBindAttempts) {
                throw new RuntimeException($tcpMessage !== '' ? $tcpMessage : 'Could not bind the private DNS TCP socket.');
            }
        }

        stream_set_blocking($udp, false);
        stream_set_blocking($tcp, false);
        $this->udp = $udp;
        $this->tcp = $tcp;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function listening(): bool
    {
        return is_resource($this->udp) && is_resource($this->tcp);
    }

    public function serveOnce(float $timeoutSeconds = 0.2): void
    {
        if (! is_resource($this->udp) || ! is_resource($this->tcp)) {
            throw new RuntimeException('The private DNS transport is not listening.');
        }

        $read = [$this->udp, $this->tcp];
        $write = null;
        $except = null;
        $ready = stream_select($read, $write, $except, (int) $timeoutSeconds, (int) (($timeoutSeconds - (int) $timeoutSeconds) * 1_000_000));
        if ($ready === false || $ready === 0) {
            $this->onIdle?->__invoke();

            return;
        }

        foreach ($read as $socket) {
            if ($socket === $this->udp) {
                $this->handleUdp();

                continue;
            }

            $this->handleTcp();
        }
    }

    public function stop(): void
    {
        if (is_resource($this->udp)) {
            fclose($this->udp);
        }

        if (is_resource($this->tcp)) {
            fclose($this->tcp);
        }

        $this->udp = null;
        $this->tcp = null;
    }

    private function handleUdp(): void
    {
        if (! is_resource($this->udp)) {
            return;
        }

        for ($drained = 0; $drained < self::MaxUdpDatagramsPerWake; $drained++) {
            $peer = '';
            $message = @stream_socket_recvfrom($this->udp, self::MaxUdpDatagramBytes, 0, $peer);
            if (! is_string($message) || $message === '' || $peer === '') {
                return;
            }

            try {
                $response = $this->handler->handle($this->peerAddress($peer), $message);
                stream_socket_sendto($this->udp, $response, 0, $peer);
            } catch (Throwable) {
                continue;
            }
        }
    }

    private function handleTcp(): void
    {
        if (! is_resource($this->tcp)) {
            return;
        }

        $connection = @stream_socket_accept($this->tcp, 0);
        if (! is_resource($connection)) {
            return;
        }

        try {
            stream_set_blocking($connection, false);
            $peer = stream_socket_get_name($connection, true);
            $source = is_string($peer) ? $this->peerAddress($peer) : '';
            $lengthBytes = $this->readExact($connection, 2);
            $length = unpack('nlen', $lengthBytes);
            if ($length === false) {
                return;
            }

            $message = $this->readExact($connection, $length['len']);
            $response = $this->handler->handle($source, $message);
            $this->writeAll($connection, pack('n', strlen($response)).$response);
        } catch (RuntimeException) {
            return;
        } finally {
            fclose($connection);
        }
    }

    private function peerAddress(string $peer): string
    {
        $separator = strrpos($peer, ':');
        if ($separator === false) {
            return $peer;
        }

        return trim(substr($peer, 0, $separator), '[]');
    }

    /**
     * @param  resource  $connection
     */
    private function readExact($connection, int $bytes): string
    {
        $buffer = '';
        $deadline = microtime(true) + $this->ioTimeoutSeconds;

        while (strlen($buffer) < $bytes) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0 || ! $this->await($connection, write: false, timeoutSeconds: $remaining)) {
                throw new RuntimeException('The private DNS TCP query timed out.');
            }

            $chunk = fread($connection, $bytes - strlen($buffer));
            if (! is_string($chunk) || $chunk === '') {
                throw new RuntimeException('The private DNS TCP query was truncated.');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * @param  resource  $connection
     */
    private function writeAll($connection, string $payload): void
    {
        $written = 0;
        $deadline = microtime(true) + $this->ioTimeoutSeconds;

        while ($written < strlen($payload)) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0 || ! $this->await($connection, write: true, timeoutSeconds: $remaining)) {
                throw new RuntimeException('The private DNS TCP response write timed out.');
            }

            $chunk = fwrite($connection, substr($payload, $written));
            if (! is_int($chunk) || $chunk < 1) {
                throw new RuntimeException('The private DNS TCP response write failed.');
            }

            $written += $chunk;
        }
    }

    /**
     * @param  resource  $socket
     */
    private function await($socket, bool $write, ?float $timeoutSeconds = null): bool
    {
        $timeout = $timeoutSeconds ?? $this->ioTimeoutSeconds;
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
}
