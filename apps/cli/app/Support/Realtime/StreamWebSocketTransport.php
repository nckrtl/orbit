<?php

declare(strict_types=1);

namespace App\Support\Realtime;

/**
 * A dependency-free RFC 6455 WebSocket client built on PHP streams. It performs the client
 * handshake, masks outgoing frames and unmasks incoming ones, answers ping frames with pong,
 * and acknowledges a close frame from the peer.
 *
 * connect() and the handshake it performs are the only blocking calls this class makes, each
 * bounded by $timeoutSeconds. Once connected the socket switches to non-blocking mode, so
 * receive() always returns immediately: it reads whatever bytes are currently available,
 * parses as many complete frames as it can, and leaves a partial frame buffered for the next
 * call rather than waiting for the rest of it to arrive.
 */
final class StreamWebSocketTransport implements WebSocketTransport
{
    private const int OPCODE_TEXT = 0x1;

    private const int OPCODE_CLOSE = 0x8;

    private const int OPCODE_PING = 0x9;

    private const int OPCODE_PONG = 0xA;

    private const string HANDSHAKE_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** @var resource|null */
    private mixed $stream = null;

    private string $buffer = '';

    private bool $connected = false;

    #[\Override]
    public function connect(string $url, ?string $caPath = null, float $timeoutSeconds = 10.0): void
    {
        $this->close();

        $target = $this->parseUrl($url);
        $scheme = $target['scheme'] === 'wss' ? 'tls' : 'tcp';
        $context = $this->streamContext($target, $caPath);

        $error = '';
        $errno = 0;

        set_error_handler(static function (int $_severity, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $stream = stream_socket_client(
                "{$scheme}://{$target['host']}:{$target['port']}",
                $errno,
                $error,
                $timeoutSeconds,
                STREAM_CLIENT_CONNECT,
                $context,
            );
        } finally {
            restore_error_handler();
        }

        if (! is_resource($stream)) {
            throw new RealtimeConnectionException("Could not connect to the realtime endpoint: {$error} ({$errno}).");
        }

        stream_set_timeout($stream, (int) max(1.0, $timeoutSeconds));
        $this->stream = $stream;
        $this->buffer = '';
        $this->connected = true;

        try {
            $this->handshake($target);
        } catch (RealtimeConnectionException $exception) {
            $this->close();

            throw $exception;
        }

        stream_set_blocking($stream, false);
    }

    #[\Override]
    public function send(array $message): void
    {
        $payload = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->writeFrame(self::OPCODE_TEXT, $payload);
    }

    #[\Override]
    public function receive(): ?array
    {
        if (! $this->connected || ! is_resource($this->stream)) {
            return null;
        }

        $this->fillBuffer();

        while (true) {
            $frame = $this->readFrame();

            if ($frame === null) {
                return null;
            }

            [$opcode, $payload] = $frame;

            if ($opcode === self::OPCODE_PING) {
                $this->writeFrame(self::OPCODE_PONG, $payload);

                continue;
            }

            if ($opcode === self::OPCODE_PONG) {
                continue;
            }

            if ($opcode === self::OPCODE_CLOSE) {
                $this->acknowledgeClose($payload);
                $this->close();

                return null;
            }

            if ($opcode !== self::OPCODE_TEXT) {
                continue;
            }

            $decoded = json_decode($payload, associative: true);

            if (! is_array($decoded)) {
                throw new RealtimeProtocolException('The realtime socket returned a frame that is not valid JSON.');
            }

            /** @var array<string, mixed> $decoded */
            return $decoded;
        }
    }

    #[\Override]
    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
        $this->buffer = '';
        $this->connected = false;
    }

    #[\Override]
    public function isConnected(): bool
    {
        return $this->connected && is_resource($this->stream);
    }

    /** @return array{scheme: string, host: string, port: int, path: string} */
    private function parseUrl(string $url): array
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) && is_string($parts['scheme'] ?? null) ? strtolower($parts['scheme']) : null;
        $host = is_array($parts) && is_string($parts['host'] ?? null) ? $parts['host'] : null;

        if (! in_array($scheme, ['ws', 'wss'], strict: true) || $host === null || $host === '') {
            throw new RealtimeConnectionException("The realtime URL [{$url}] is not a valid ws:// or wss:// endpoint.");
        }

        $port = is_numeric($parts['port'] ?? null)
            ? (int) $parts['port']
            : ($scheme === 'wss' ? 443 : 80);

        $path = is_string($parts['path'] ?? null) && $parts['path'] !== '' ? $parts['path'] : '/';

        if (is_string($parts['query'] ?? null) && $parts['query'] !== '') {
            $path .= '?'.$parts['query'];
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'path' => $path];
    }

    /**
     * @param  array{scheme: string, host: string, port: int, path: string}  $target
     * @return resource
     */
    private function streamContext(array $target, ?string $caPath): mixed
    {
        $options = [];

        if ($target['scheme'] === 'wss') {
            $options['ssl'] = [
                'SNI_enabled' => true,
                'peer_name' => $target['host'],
                'verify_peer' => true,
                'verify_peer_name' => true,
            ];

            if ($caPath !== null) {
                if (! is_file($caPath) || ! is_readable($caPath)) {
                    throw new RealtimeConnectionException('The selected realtime CA certificate is unavailable.');
                }

                $options['ssl']['cafile'] = $caPath;
            }
        }

        return stream_context_create($options);
    }

    /** @param  array{scheme: string, host: string, port: int, path: string}  $target */
    private function handshake(array $target): void
    {
        $key = base64_encode(random_bytes(16));
        $defaultPort = $target['scheme'] === 'wss' ? 443 : 80;
        $hostHeader = $target['port'] === $defaultPort ? $target['host'] : "{$target['host']}:{$target['port']}";

        $request = implode("\r\n", [
            "GET {$target['path']} HTTP/1.1",
            "Host: {$hostHeader}",
            'Upgrade: websocket',
            'Connection: Upgrade',
            "Sec-WebSocket-Key: {$key}",
            'Sec-WebSocket-Version: 13',
            "\r\n",
        ]);

        if (fwrite($this->stream(), $request) === false) {
            throw new RealtimeConnectionException('Could not send the realtime handshake request.');
        }

        $response = '';

        while (! str_contains($response, "\r\n\r\n")) {
            $chunk = fgets($this->stream());

            if ($chunk === false) {
                break;
            }

            $response .= $chunk;
        }

        if (! str_starts_with($response, 'HTTP/1.1 101') && ! str_starts_with($response, 'HTTP/1.0 101')) {
            throw new RealtimeConnectionException('The realtime handshake was refused.');
        }

        $expectedAccept = base64_encode(sha1($key.self::HANDSHAKE_GUID, binary: true));

        if (
            preg_match('/^Sec-WebSocket-Accept:\s*(\S+)/mi', $response, $matches) !== 1
            || ! hash_equals($expectedAccept, trim($matches[1]))
        ) {
            throw new RealtimeConnectionException('The realtime handshake returned an unexpected accept key.');
        }
    }

    private function fillBuffer(): void
    {
        $stream = $this->stream;

        if (! is_resource($stream)) {
            return;
        }

        while (true) {
            $read = [$stream];
            $write = null;
            $except = null;

            $ready = @stream_select($read, $write, $except, 0, 0);

            if ($ready === false || $ready === 0) {
                return;
            }

            $chunk = fread($stream, 65536);

            if ($chunk === false || $chunk === '') {
                if (feof($stream)) {
                    $this->close();
                }

                return;
            }

            $this->buffer .= $chunk;
        }
    }

    /** @return array{0: int, 1: string}|null */
    private function readFrame(): ?array
    {
        $length = strlen($this->buffer);

        if ($length < 2) {
            return null;
        }

        $first = ord($this->buffer[0]);
        $second = ord($this->buffer[1]);
        $opcode = $first & 0x0F;
        $masked = ($second & 0x80) === 0x80;
        $lengthCode = $second & 0x7F;
        $offset = 2;
        $payloadLength = $lengthCode;

        if ($lengthCode === 126) {
            if ($length < $offset + 2) {
                return null;
            }

            /** @var array<int, int> $unpacked */
            $unpacked = unpack('n', substr($this->buffer, $offset, 2)) ?: [];
            $payloadLength = $unpacked[1] ?? 0;
            $offset += 2;
        } elseif ($lengthCode === 127) {
            if ($length < $offset + 8) {
                return null;
            }

            /** @var array<int, int> $unpacked */
            $unpacked = unpack('N2', substr($this->buffer, $offset, 8)) ?: [];
            $payloadLength = (($unpacked[1] ?? 0) << 32) + ($unpacked[2] ?? 0);
            $offset += 8;
        }

        $maskKey = null;

        if ($masked) {
            if ($length < $offset + 4) {
                return null;
            }

            $maskKey = substr($this->buffer, $offset, 4);
            $offset += 4;
        }

        if ($length < $offset + $payloadLength) {
            return null;
        }

        $payload = substr($this->buffer, $offset, $payloadLength);
        $this->buffer = substr($this->buffer, $offset + $payloadLength);

        if ($maskKey !== null) {
            $payload = $this->applyMask($payload, $maskKey);
        }

        return [$opcode, $payload];
    }

    private function writeFrame(int $opcode, string $payload): void
    {
        $length = strlen($payload);
        $head = chr(0x80 | $opcode);

        if ($length <= 125) {
            $head .= chr(0x80 | $length);
        } elseif ($length <= 65535) {
            $head .= chr(0x80 | 126).pack('n', $length);
        } else {
            $head .= chr(0x80 | 127).pack('J', $length);
        }

        $mask = random_bytes(4);

        if (fwrite($this->stream(), $head.$mask.$this->applyMask($payload, $mask)) === false) {
            throw new RealtimeConnectionException('Could not write to the realtime socket.');
        }
    }

    private function applyMask(string $payload, string $maskKey): string
    {
        $length = strlen($payload);
        $unmasked = '';

        for ($i = 0; $i < $length; $i++) {
            $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
        }

        return $unmasked;
    }

    private function acknowledgeClose(string $payload): void
    {
        try {
            $this->writeFrame(self::OPCODE_CLOSE, substr($payload, 0, 2));
        } catch (RealtimeConnectionException) {
            // The peer already went away; there is nothing left to acknowledge.
        }
    }

    /** @return resource */
    private function stream(): mixed
    {
        if (! is_resource($this->stream)) {
            throw new RealtimeConnectionException('The realtime socket is not connected.');
        }

        return $this->stream;
    }
}
