<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use RuntimeException;

final class PrivateDnsTransportServer
{
    /** @var resource|null */
    private $udp;

    /** @var resource|null */
    private $tcp;

    public function __construct(
        private readonly PrivateDnsRequestHandler $handler,
        private readonly string $listenAddress = '127.0.0.1',
        private int $port = 0,
    ) {}

    public function start(): void
    {
        $udp = stream_socket_server(
            'udp://'.$this->listenAddress.':'.$this->port,
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
        $tcp = stream_socket_server(
            'tcp://'.$this->listenAddress.':'.$this->port,
            $tcpError,
            $tcpMessage,
        );
        if (! is_resource($tcp)) {
            fclose($udp);

            throw new RuntimeException($tcpMessage !== '' ? $tcpMessage : 'Could not bind the private DNS TCP socket.');
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

        $peer = '';
        $message = stream_socket_recvfrom($this->udp, 4096, 0, $peer);
        if (! is_string($message) || $message === '' || $peer === '') {
            return;
        }

        $source = $this->peerAddress($peer);
        $response = $this->handler->handle($source, $message);
        stream_socket_sendto($this->udp, $response, 0, $peer);
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
            $peer = stream_socket_get_name($connection, true);
            $source = is_string($peer) ? $this->peerAddress($peer) : '';
            $lengthBytes = $this->readExact($connection, 2);
            $length = unpack('nlen', $lengthBytes);
            if ($length === false) {
                return;
            }

            $message = $this->readExact($connection, $length['len']);
            $response = $this->handler->handle($source, $message);
            fwrite($connection, pack('n', strlen($response)).$response);
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

        while (strlen($buffer) < $bytes) {
            $chunk = fread($connection, $bytes - strlen($buffer));
            if (! is_string($chunk) || $chunk === '') {
                throw new RuntimeException('The private DNS TCP query was truncated.');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }
}
