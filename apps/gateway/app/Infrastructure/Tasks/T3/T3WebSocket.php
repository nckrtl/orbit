<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\T3;

use LogicException;

/**
 * Bounded RFC 6455 text-frame client for the T3 session relay.
 */
final class T3WebSocket
{
    /** @var resource|null */
    private $stream = null;

    private string $buffer = '';

    private string $fragments = '';

    /** @var list<array<string, mixed>> */
    private array $messages = [];

    /**
     * @param  array<string, string>  $headers
     */
    public function connect(string $url, array $headers = [], float $timeout = 10.0): void
    {
        $this->close();
        if (str_starts_with($url, 'unix://')) {
            $this->open('unix://'.substr($url, 7), '/', 'localhost', $headers, $timeout);

            return;
        }
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new LogicException('Invalid WebSocket URL.');
        }
        $scheme = strtolower($parts['scheme']);
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'wss' || $scheme === 'https' ? 443 : 80);
        $target = match ($scheme) {
            'ws', 'http' => 'tcp://'.$host.':'.$port,
            'wss', 'https' => 'ssl://'.$host.':'.$port,
            default => throw new LogicException('Unsupported WebSocket scheme.'),
        };
        $handshakeHost = $host.($port === 80 || $port === 443 ? '' : ':'.$port);
        $this->open($target, $path, $handshakeHost, $headers, $timeout);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function open(string $target, string $handshakePath, string $handshakeHost, array $headers, float $timeout): void
    {
        $stream = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if ($stream === false) {
            throw new LogicException('WebSocket connect failed.');
        }
        stream_set_timeout($stream, (int) $timeout, (int) (($timeout - (int) $timeout) * 1_000_000));
        $key = base64_encode(random_bytes(16));
        $request = "GET {$handshakePath} HTTP/1.1\r\nHost: {$handshakeHost}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n";
        foreach ($headers as $name => $value) {
            $request .= $name.': '.$value."\r\n";
        }
        $request .= "\r\n";
        if (@fwrite($stream, $request) !== strlen($request)) {
            fclose($stream);
            throw new LogicException('WebSocket handshake write failed.');
        }
        $response = '';
        while (! str_contains($response, "\r\n\r\n")) {
            $chunk = fread($stream, 8192);
            if ($chunk === false || $chunk === '') {
                fclose($stream);
                throw new LogicException('WebSocket handshake failed.');
            }
            $response .= $chunk;
            if (strlen($response) > 65536) {
                fclose($stream);
                throw new LogicException('WebSocket handshake too large.');
            }
        }
        if (! str_contains($response, ' 101 ')) {
            fclose($stream);
            throw new LogicException('WebSocket upgrade was rejected.');
        }
        [$head, $remaining] = explode("\r\n\r\n", $response, 2);
        $accept = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (! preg_match('/^Sec-WebSocket-Accept:\s*'.preg_quote($accept, '/').'\s*$/mi', $head)) {
            fclose($stream);
            throw new LogicException('Invalid WebSocket handshake.');
        }
        $this->buffer = $remaining;
        $this->stream = $stream;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload): void
    {
        $this->write($this->frame(json_encode($payload, JSON_THROW_ON_ERROR)));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function receive(float $timeout): ?array
    {
        $stream = $this->stream();
        $deadline = microtime(true) + $timeout;
        while (true) {
            if ($this->messages !== []) {
                return array_shift($this->messages);
            }
            $decoded = $this->nextFrame();
            if ($decoded !== null) {
                foreach (explode("\n", $decoded) as $line) {
                    if (trim($line) === '') {
                        continue;
                    }
                    $payload = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    if (! is_array($payload)) {
                        throw new LogicException('Invalid WebSocket message.');
                    }
                    /** @var array<string, mixed> $payload */
                    $this->messages[] = $payload;
                }

                continue;
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return null;
            }
            $read = [$stream];
            $write = [];
            $except = [];
            $seconds = (int) $remaining;
            $ready = @stream_select($read, $write, $except, $seconds, (int) (($remaining - $seconds) * 1_000_000));
            if ($ready === false) {
                throw new LogicException('WebSocket read failed.');
            }
            if ($ready === 0) {
                return null;
            }
            $chunk = fread($stream, 65536);
            if ($chunk === false || ($chunk === '' && feof($stream))) {
                throw new LogicException('WebSocket closed.');
            }
            $this->buffer .= $chunk;
        }
    }

    public function close(): void
    {
        if ($this->stream === null) {
            return;
        }
        @fwrite($this->stream, $this->frame('', 0x8));
        fclose($this->stream);
        $this->stream = null;
        $this->buffer = '';
        $this->fragments = '';
        $this->messages = [];
    }

    /** @return resource */
    private function stream()
    {
        if ($this->stream === null) {
            throw new LogicException('WebSocket is not connected.');
        }

        return $this->stream;
    }

    private function write(string $frame): void
    {
        if (@fwrite($this->stream(), $frame) !== strlen($frame)) {
            throw new LogicException('WebSocket write failed.');
        }
    }

    private function frame(string $payload, int $opcode = 0x1): string
    {
        $length = strlen($payload);
        $mask = random_bytes(4);
        $header = chr(0x80 | $opcode);
        if ($length < 126) {
            $header .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $header .= chr(0x80 | 126).pack('n', $length);
        } else {
            $header .= chr(0x80 | 127).pack('J', $length);
        }
        $masked = '';
        for ($index = 0; $index < $length; $index++) {
            $masked .= $payload[$index] ^ $mask[$index % 4];
        }

        return $header.$mask.$masked;
    }

    private function nextFrame(): ?string
    {
        if (strlen($this->buffer) < 2) {
            return null;
        }
        $byte1 = ord($this->buffer[0]);
        $byte2 = ord($this->buffer[1]);
        $opcode = $byte1 & 0x0F;
        $masked = ($byte2 & 0x80) === 0x80;
        $length = $byte2 & 0x7F;
        $offset = 2;
        if ($length === 126) {
            if (strlen($this->buffer) < 4) {
                return null;
            }
            $unpacked = unpack('n', substr($this->buffer, 2, 2));
            $length = is_array($unpacked) ? (int) $unpacked[1] : 0;
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($this->buffer) < 10) {
                return null;
            }
            $unpacked = unpack('J', substr($this->buffer, 2, 8));
            $length = is_array($unpacked) ? (int) $unpacked[1] : 0;
            $offset = 10;
        }
        if ($length > 16 * 1024 * 1024 || $length < 0) {
            throw new LogicException('WebSocket frame too large.');
        }
        $mask = '';
        if ($masked) {
            if (strlen($this->buffer) < $offset + 4) {
                return null;
            }
            $mask = substr($this->buffer, $offset, 4);
            $offset += 4;
        }
        if (strlen($this->buffer) < $offset + $length) {
            return null;
        }
        $payload = substr($this->buffer, $offset, $length);
        $this->buffer = substr($this->buffer, $offset + $length);
        if ($masked) {
            $decoded = '';
            for ($index = 0; $index < $length; $index++) {
                $decoded .= $payload[$index] ^ $mask[$index % 4];
            }
            $payload = $decoded;
        }
        if ($opcode === 0x8) {
            throw new LogicException('WebSocket closed.');
        }
        if ($opcode === 0x9) {
            $this->write($this->frame($payload, 0xA));

            return '';
        }
        if ($opcode !== 0x1 && $opcode !== 0x0) {
            return '';
        }

        $this->fragments .= $payload;
        if (strlen($this->fragments) > 16 * 1024 * 1024) {
            throw new LogicException('WebSocket message too large.');
        }
        if (($byte1 & 0x80) === 0) {
            return '';
        }
        $message = $this->fragments;
        $this->fragments = '';

        return $message;
    }
}
