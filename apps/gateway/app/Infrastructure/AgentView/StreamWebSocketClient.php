<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentView;

/**
 * A dependency-free RFC 6455 client on PHP streams, for the agent view subscriber.
 *
 * `connect()` blocks for at most its timeout. After the handshake the stream is non-blocking, and
 * `receive()` waits with `stream_select()`, so an idle subscriber sleeps in the kernel. A message
 * larger than `MaxMessageBytes` is dropped, and a frame that claims more than `MaxFrameBytes`
 * closes the connection, so one misbehaving peer cannot grow the process without limit.
 */
final class StreamWebSocketClient implements WebSocketClient
{
    public const int MaxMessageBytes = 65_536;

    private const int MaxFrameBytes = 1_048_576;

    private const int OPCODE_CONTINUATION = 0x0;

    private const int OPCODE_TEXT = 0x1;

    private const int OPCODE_CLOSE = 0x8;

    private const int OPCODE_PING = 0x9;

    private const int OPCODE_PONG = 0xA;

    private const string HANDSHAKE_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** @var resource|null */
    private mixed $stream = null;

    private string $buffer = '';

    /** Text of a fragmented message, or null when no fragmented message is open. */
    private ?string $fragments = null;

    /** Whether the open fragmented message passed the size limit and is being dropped. */
    private bool $discarding = false;

    #[\Override]
    public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
    {
        $this->close();
        $context = stream_context_create(['ssl' => [
            'SNI_enabled' => true,
            'peer_name' => $endpoint->serverName,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'cafile' => $endpoint->caPath,
        ]]);
        $error = '';
        $errno = 0;
        set_error_handler(static function (int $_severity, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $stream = stream_socket_client(
                "tls://{$endpoint->address}:{$endpoint->port}",
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
            throw new WebSocketException("Could not connect to Reverb: {$error} ({$errno}).");
        }

        stream_set_timeout($stream, (int) max(1.0, $timeoutSeconds));
        $this->stream = $stream;

        try {
            $this->handshake($endpoint);
        } catch (WebSocketException $exception) {
            $this->close();

            throw $exception;
        }

        stream_set_blocking($stream, false);
    }

    #[\Override]
    public function send(array $message): void
    {
        $this->writeFrame(self::OPCODE_TEXT, json_encode($message, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    #[\Override]
    public function receive(float $timeoutSeconds): array
    {
        $stream = $this->stream;

        if (! is_resource($stream)) {
            return [];
        }

        $messages = $this->drainFrames();

        if ($messages !== [] || ! is_resource($this->stream)) {
            return $messages;
        }

        $read = [$stream];
        $write = null;
        $except = null;
        $seconds = (int) floor($timeoutSeconds);
        $ready = @stream_select($read, $write, $except, $seconds, (int) (($timeoutSeconds - $seconds) * 1_000_000));

        if ($ready === false || $ready === 0) {
            return [];
        }

        while (is_resource($this->stream)) {
            $chunk = fread($this->stream, 65_536);

            if ($chunk === false || $chunk === '') {
                if (feof($this->stream)) {
                    $this->close();
                }

                break;
            }

            $this->buffer .= $chunk;
        }

        return $this->drainFrames();
    }

    #[\Override]
    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
        $this->buffer = '';
        $this->fragments = null;
        $this->discarding = false;
    }

    #[\Override]
    public function isConnected(): bool
    {
        return is_resource($this->stream);
    }

    /** @return list<array<string, mixed>> */
    private function drainFrames(): array
    {
        $messages = [];

        while (is_resource($this->stream)) {
            $frame = $this->readFrame();

            if ($frame === null) {
                break;
            }

            [$final, $opcode, $payload] = $frame;

            if ($opcode === self::OPCODE_PING) {
                $this->writeFrame(self::OPCODE_PONG, $payload);

                continue;
            }

            if ($opcode === self::OPCODE_CLOSE) {
                $this->acknowledgeClose($payload);
                $this->close();

                break;
            }

            if ($opcode === self::OPCODE_TEXT && ! $final) {
                $this->fragments = $payload;
                $this->discarding = strlen($payload) > self::MaxMessageBytes;

                continue;
            }

            if ($opcode === self::OPCODE_CONTINUATION) {
                if ($this->fragments === null) {
                    continue;
                }

                // A message over the limit is dropped whole: nothing of it is parsed, up to its final frame.
                $this->discarding = $this->discarding || strlen($this->fragments) + strlen($payload) > self::MaxMessageBytes;
                $this->fragments = $this->discarding ? '' : $this->fragments.$payload;

                if (! $final) {
                    continue;
                }

                $payload = $this->discarding ? '' : $this->fragments;
                $this->fragments = null;
                $this->discarding = false;
                $opcode = self::OPCODE_TEXT;
            }

            if ($opcode !== self::OPCODE_TEXT || $payload === '' || strlen($payload) > self::MaxMessageBytes) {
                continue;
            }

            $decoded = json_decode($payload, associative: true);

            if (is_array($decoded) && ! array_is_list($decoded)) {
                /** @var array<string, mixed> $decoded */
                $messages[] = $decoded;
            }
        }

        return $messages;
    }

    private function handshake(WebSocketEndpoint $endpoint): void
    {
        $key = base64_encode(random_bytes(16));
        $host = $endpoint->port === 443 ? $endpoint->serverName : "{$endpoint->serverName}:{$endpoint->port}";
        $request = implode("\r\n", [
            "GET {$endpoint->path} HTTP/1.1",
            "Host: {$host}",
            'Upgrade: websocket',
            'Connection: Upgrade',
            "Sec-WebSocket-Key: {$key}",
            'Sec-WebSocket-Version: 13',
            "\r\n",
        ]);

        if (fwrite($this->stream(), $request) === false) {
            throw new WebSocketException('Could not send the WebSocket handshake.');
        }

        $response = '';

        while (! str_contains($response, "\r\n\r\n") && strlen($response) < 16_384) {
            $line = fgets($this->stream());

            if ($line === false) {
                break;
            }

            $response .= $line;
        }

        if (! str_starts_with($response, 'HTTP/1.1 101')) {
            throw new WebSocketException('Reverb refused the WebSocket handshake.');
        }

        $expected = base64_encode(sha1($key.self::HANDSHAKE_GUID, binary: true));

        if (preg_match('/^Sec-WebSocket-Accept:\s*(\S+)/mi', $response, $matches) !== 1 || ! hash_equals($expected, trim($matches[1]))) {
            throw new WebSocketException('Reverb returned an unexpected WebSocket accept key.');
        }
    }

    /** @return array{0: bool, 1: int, 2: string}|null */
    private function readFrame(): ?array
    {
        $length = strlen($this->buffer);

        if ($length < 2) {
            return null;
        }

        $first = ord($this->buffer[0]);
        $second = ord($this->buffer[1]);
        $lengthCode = $second & 0x7F;
        $masked = ($second & 0x80) === 0x80;
        $offset = 2;
        $payloadLength = $lengthCode;

        if ($lengthCode === 126) {
            if ($length < 4) {
                return null;
            }

            $payloadLength = (ord($this->buffer[2]) << 8) | ord($this->buffer[3]);
            $offset = 4;
        } elseif ($lengthCode === 127) {
            if ($length < 10) {
                return null;
            }

            /** @var array{1: int} $unpacked */
            $unpacked = unpack('J', substr($this->buffer, 2, 8));
            $payloadLength = $unpacked[1];
            $offset = 10;
        }

        if ($payloadLength < 0 || $payloadLength > self::MaxFrameBytes) {
            $this->close();

            return null;
        }

        $mask = null;

        if ($masked) {
            if ($length < $offset + 4) {
                return null;
            }

            $mask = substr($this->buffer, $offset, 4);
            $offset += 4;
        }

        if ($length < $offset + $payloadLength) {
            return null;
        }

        $payload = substr($this->buffer, $offset, $payloadLength);
        $this->buffer = substr($this->buffer, $offset + $payloadLength);

        return [($first & 0x80) === 0x80, $first & 0x0F, $mask === null ? $payload : $this->mask($payload, $mask)];
    }

    private function writeFrame(int $opcode, string $payload): void
    {
        $length = strlen($payload);
        $head = chr(0x80 | $opcode);
        $head .= match (true) {
            $length <= 125 => chr(0x80 | $length),
            $length <= 65_535 => chr(0x80 | 126).pack('n', $length),
            default => chr(0x80 | 127).pack('J', $length),
        };
        $mask = random_bytes(4);
        $frame = $head.$mask.$this->mask($payload, $mask);
        $stream = $this->stream();
        $deadline = microtime(true) + 5.0;

        while ($frame !== '') {
            $written = @fwrite($stream, $frame);

            if ($written === false || ($written === 0 && microtime(true) > $deadline)) {
                $this->close();

                throw new WebSocketException('Could not write to the Reverb socket.');
            }

            if ($written === 0) {
                usleep(1_000);

                continue;
            }

            $frame = substr($frame, $written);
        }
    }

    private function mask(string $payload, string $mask): string
    {
        $length = strlen($payload);

        return $length === 0 ? '' : $payload ^ substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length);
    }

    private function acknowledgeClose(string $payload): void
    {
        try {
            $this->writeFrame(self::OPCODE_CLOSE, substr($payload, 0, 2));
        } catch (WebSocketException) {
            // The peer already went away.
        }
    }

    /** @return resource */
    private function stream(): mixed
    {
        if (! is_resource($this->stream)) {
            throw new WebSocketException('The Reverb socket is not connected.');
        }

        return $this->stream;
    }
}
