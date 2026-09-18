<?php

declare(strict_types=1);

use App\Support\Realtime\RealtimeConnectionException;
use App\Support\Realtime\RealtimeProtocolException;
use App\Support\Realtime\StreamWebSocketTransport;

/**
 * These tests exercise the real frame parser and writer directly over a connected socket pair,
 * bypassing connect()'s TCP/TLS dial and HTTP handshake (which need a real listener and are not
 * practical to drive synchronously from a single-process test). A ReflectionObject injects one
 * end of the pair as though the handshake had just completed, matching the state connect()
 * leaves the transport in: a non-blocking stream and an empty read buffer.
 */
function stream_websocket_transport_for_testing(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    if ($pair === false) {
        throw new RuntimeException('Could not create a socket pair for the test.');
    }

    [$clientStream, $serverStream] = $pair;
    stream_set_blocking($clientStream, false);

    $transport = new StreamWebSocketTransport;
    $object = new ReflectionObject($transport);
    $object->getProperty('stream')->setValue($transport, $clientStream);
    $object->getProperty('connected')->setValue($transport, true);

    return [$transport, $serverStream];
}

function ws_write_frame($stream, int $opcode, string $payload, bool $masked = false): void
{
    $length = strlen($payload);
    $maskBit = $masked ? 0x80 : 0x00;
    $head = chr(0x80 | $opcode);

    if ($length <= 125) {
        $head .= chr($maskBit | $length);
    } elseif ($length <= 65535) {
        $head .= chr($maskBit | 126).pack('n', $length);
    } else {
        $head .= chr($maskBit | 127).pack('J', $length);
    }

    if (! $masked) {
        fwrite($stream, $head.$payload);

        return;
    }

    $maskKey = random_bytes(4);
    $masked = '';

    for ($i = 0; $i < $length; $i++) {
        $masked .= $payload[$i] ^ $maskKey[$i % 4];
    }

    fwrite($stream, $head.$maskKey.$masked);
}

/** @return array{0: int, 1: string} */
function ws_read_frame($stream): array
{
    $header = fread($stream, 2);
    $first = ord($header[0]);
    $second = ord($header[1]);
    $opcode = $first & 0x0F;
    $masked = ($second & 0x80) === 0x80;
    $length = $second & 0x7F;

    if ($length === 126) {
        $length = unpack('n', fread($stream, 2))[1];
    } elseif ($length === 127) {
        $parts = unpack('N2', fread($stream, 8));
        $length = ($parts[1] << 32) + $parts[2];
    }

    $maskKey = $masked ? fread($stream, 4) : null;
    $payload = $length > 0 ? fread($stream, $length) : '';

    if ($maskKey !== null) {
        $unmasked = '';

        for ($i = 0; $i < $length; $i++) {
            $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
        }

        $payload = $unmasked;
    }

    return [$opcode, $payload];
}

describe(StreamWebSocketTransport::class, function (): void {
    it('returns null immediately when nothing has arrived, without blocking', function (): void {
        [$transport] = stream_websocket_transport_for_testing();

        $start = microtime(true);
        $message = $transport->receive();
        $elapsed = microtime(true) - $start;

        expect($message)->toBeNull()
            ->and($elapsed)->toBeLessThan(0.25);
    });

    it('decodes a complete unmasked text frame written by the peer', function (): void {
        [$transport, $server] = stream_websocket_transport_for_testing();

        ws_write_frame($server, 0x1, json_encode(['event' => 'pusher:connection_established', 'data' => '{}']));

        expect($transport->receive())->toBe(['event' => 'pusher:connection_established', 'data' => '{}']);
    });

    it('decodes a payload long enough to need the extended 16-bit length header', function (): void {
        [$transport, $server] = stream_websocket_transport_for_testing();
        $long = str_repeat('a', 200);

        ws_write_frame($server, 0x1, json_encode(['event' => 'node.created', 'data' => $long]));

        expect($transport->receive())->toBe(['event' => 'node.created', 'data' => $long]);
    });

    it('buffers a partial frame across two reads instead of blocking or failing', function (): void {
        [$transport, $server] = stream_websocket_transport_for_testing();
        $payload = json_encode(['event' => 'node.created', 'data' => '{}']);
        $frame = "\x81".chr(strlen($payload)).$payload;

        fwrite($server, substr($frame, 0, 3));
        expect($transport->receive())->toBeNull();

        fwrite($server, substr($frame, 3));
        expect($transport->receive())->toBe(['event' => 'node.created', 'data' => '{}']);
    });

    it('answers a ping frame with a pong and does not surface it as a message', function (): void {
        [$transport, $server] = stream_websocket_transport_for_testing();

        ws_write_frame($server, 0x9, 'ping-payload');

        expect($transport->receive())->toBeNull();

        [$opcode, $payload] = ws_read_frame($server);
        expect($opcode)->toBe(0xA)
            ->and($payload)->toBe('ping-payload');
    });

    it('closes and acknowledges a close frame sent by the peer', function (): void {
        [$transport, $server] = stream_websocket_transport_for_testing();

        ws_write_frame($server, 0x8, "\x03\xE8");

        expect($transport->receive())->toBeNull()
            ->and($transport->isConnected())->toBeFalse();

        [$opcode] = ws_read_frame($server);
        expect($opcode)->toBe(0x8);
    });

    it('sends a masked text frame the peer can decode back to the original JSON', function (): void {
        [$transport, $server] = stream_websocket_transport_for_testing();

        $transport->send(['event' => 'pusher:subscribe', 'data' => ['channel' => 'private-orbit']]);

        [$opcode, $payload] = ws_read_frame($server);

        expect($opcode)->toBe(0x1)
            ->and(json_decode($payload, associative: true))->toBe([
                'event' => 'pusher:subscribe',
                'data' => ['channel' => 'private-orbit'],
            ]);
    });

    it('rejects a text frame whose payload is not valid JSON', function (): void {
        [$transport, $server] = stream_websocket_transport_for_testing();

        ws_write_frame($server, 0x1, 'not json');

        $transport->receive();
    })->throws(RealtimeProtocolException::class);

    it('refuses to connect to a non-ws(s) URL', function (): void {
        (new StreamWebSocketTransport)->connect('https://example.test');
    })->throws(RealtimeConnectionException::class);
});
