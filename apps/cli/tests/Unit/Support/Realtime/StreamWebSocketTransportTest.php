<?php

declare(strict_types=1);

use App\Support\Realtime\FakeRealtimeChannelAuthorizer;
use App\Support\Realtime\RealtimeConnectionException;
use App\Support\Realtime\RealtimeProtocolException;
use App\Support\Realtime\RealtimeState;
use App\Support\Realtime\RealtimeSubscriber;
use App\Support\Realtime\StreamWebSocketTransport;
use Symfony\Component\Process\Process;

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

describe('native peer EOF', function (): void {
    beforeEach(function (): void {
        [$this->transport, $this->peer] = stream_websocket_transport_for_testing();
    });

    afterEach(function (): void {
        $this->transport->close();

        if (is_resource($this->peer)) {
            fclose($this->peer);
        }
    });

    it('drains complete frames in order after the peer closes', function (int $count): void {
        for ($id = 1; $id <= $count; $id++) {
            ws_write_frame($this->peer, 0x1, json_encode(['id' => $id]));
        }

        fclose($this->peer);

        for ($id = 1; $id <= $count; $id++) {
            expect($this->transport->receive())->toBe(['id' => $id]);
        }

        expect($this->transport->receive())->toBeNull()
            ->and($this->transport->isConnected())->toBeFalse()
            ->and($this->transport->receive())->toBeNull();
    })->with([0, 1, 3]);

    it('drains complete frames and discards an incomplete final frame', function (string $tail): void {
        ws_write_frame($this->peer, 0x1, json_encode(['id' => 1]));
        ws_write_frame($this->peer, 0x1, json_encode(['id' => 2]));
        fwrite($this->peer, $tail);
        fclose($this->peer);

        expect($this->transport->receive())->toBe(['id' => 1])
            ->and($this->transport->receive())->toBe(['id' => 2])
            ->and($this->transport->receive())->toBeNull()
            ->and($this->transport->isConnected())->toBeFalse()
            ->and(new ReflectionObject($this->transport)->getProperty('buffer')->getValue($this->transport))->toBe('')
            ->and($this->transport->receive())->toBeNull();
    })->with(["\x81", "\x81\x7e\x00", "\x81\x10{\"id\":"]);

    it('keeps a later frame already buffered before the peer closes', function (): void {
        ws_write_frame($this->peer, 0x1, json_encode(['id' => 1]));
        ws_write_frame($this->peer, 0x1, json_encode(['id' => 2]));

        expect($this->transport->receive())->toBe(['id' => 1]);
        expect(new ReflectionObject($this->transport)->getProperty('buffer')->getValue($this->transport))->not->toBe('');
        fclose($this->peer);

        expect($this->transport->receive())->toBe(['id' => 2])
            ->and($this->transport->receive())->toBeNull()
            ->and($this->transport->isConnected())->toBeFalse();
    });

    it('drains text around control frames without writing to an exhausted peer', function (bool $closeFrame): void {
        ws_write_frame($this->peer, 0x9, 'first-ping');
        ws_write_frame($this->peer, 0x1, json_encode(['id' => 1]));
        ws_write_frame($this->peer, 0xA, 'pong');
        ws_write_frame($this->peer, 0x9, 'last-ping');
        ws_write_frame($this->peer, 0x1, json_encode(['id' => 2]));

        if ($closeFrame) {
            ws_write_frame($this->peer, 0x8, "\x03\xE8");
            ws_write_frame($this->peer, 0x1, json_encode(['id' => 3]));
        }

        fclose($this->peer);

        expect($this->transport->receive())->toBe(['id' => 1])
            ->and($this->transport->receive())->toBe(['id' => 2])
            ->and($this->transport->receive())->toBeNull()
            ->and($this->transport->isConnected())->toBeFalse()
            ->and($this->transport->receive())->toBeNull();
    })->with([false, true]);

    it('delivers earlier text before acknowledging a live peer close', function (): void {
        ws_write_frame($this->peer, 0x1, json_encode(['id' => 1]));
        ws_write_frame($this->peer, 0x8, "\x03\xE8");

        expect($this->transport->receive())->toBe(['id' => 1])
            ->and($this->transport->receive())->toBeNull()
            ->and($this->transport->isConnected())->toBeFalse()
            ->and(ws_read_frame($this->peer))->toBe([0x8, "\x03\xE8"]);
    });

    it('explicitly closes idempotently and discards owned frames', function (bool $peerEof): void {
        ws_write_frame($this->peer, 0x1, json_encode(['id' => 1]));
        ws_write_frame($this->peer, 0x1, json_encode(['id' => 2]));

        if ($peerEof) {
            fclose($this->peer);
        }

        expect($this->transport->receive())->toBe(['id' => 1]);
        $this->transport->close();
        $this->transport->close();

        expect($this->transport->receive())->toBeNull()
            ->and($this->transport->isConnected())->toBeFalse()
            ->and(new ReflectionObject($this->transport)->getProperty('buffer')->getValue($this->transport))->toBe('');
    })->with([false, true]);

    it('starts a fresh native connection without replaying owned frames', function (): void {
        ws_write_frame($this->peer, 0x1, json_encode(['id' => 1]));
        ws_write_frame($this->peer, 0x1, json_encode(['id' => 2]));
        expect($this->transport->receive())->toBe(['id' => 1]);
        fclose($this->peer);

        $configuration = tempnam(sys_get_temp_dir(), 'orbit-eof-');
        $trace = $configuration.'.trace';
        file_put_contents($configuration, json_encode(['certificate' => '', 'key' => '', 'tls' => false, 'trace' => $trace]));
        $server = new Process([PHP_BINARY, dirname(__DIR__, 3).'/Fixtures/realtime/trust-server.php', $configuration]);

        try {
            $server->start();
            $server->waitUntil(fn (): bool => str_contains($server->getOutput(), "\n"));
            $this->transport->connect('ws://'.trim($server->getOutput()), timeoutSeconds: 1);

            expect($this->transport->receive())->toBeNull()
                ->and(new ReflectionObject($this->transport)->getProperty('buffer')->getValue($this->transport))->toBe('');
        } finally {
            $server->stop();
            unlink($configuration);

            if (is_file($trace)) {
                unlink($trace);
            }
        }
    });

    it('returns final subscriber events once before reconnecting after native EOF', function (bool $pusherPing): void {
        $subscriber = new RealtimeSubscriber($this->transport, realtime_test_connection_config(), new FakeRealtimeChannelAuthorizer, static fn (): float => 1000.0);
        $object = new ReflectionObject($subscriber);
        $object->getProperty('phase')->setValue($subscriber, 'awaiting_established');
        ws_write_frame($this->peer, 0x1, json_encode(['event' => 'pusher:connection_established', 'data' => ['socket_id' => 'native.1']]));
        ws_write_frame($this->peer, 0x1, json_encode(['event' => 'pusher_internal:subscription_succeeded', 'channel' => 'private-orbit', 'data' => []]));

        expect($subscriber->poll())->toBe([])
            ->and($subscriber->state())->toBe(RealtimeState::Connected);
        [$opcode, $payload] = ws_read_frame($this->peer);
        expect($opcode)->toBe(0x1)
            ->and(json_decode($payload, true)['event'])->toBe('pusher:subscribe');

        foreach ([1, 2] as $id) {
            ws_write_frame($this->peer, 0x1, json_encode(['event' => 'node.created', 'channel' => 'private-orbit', 'data' => [
                'type' => 'node.created', 'id' => $id, 'at' => '2026-09-22T10:00:00+00:00', 'data' => ['name' => 'native-'.$id],
            ]]));

            if ($pusherPing && $id === 1) {
                ws_write_frame($this->peer, 0x1, json_encode(['event' => 'pusher:ping', 'data' => []]));
            }
        }

        fclose($this->peer);
        $events = $subscriber->poll();

        expect(array_map(fn ($event) => $event->id, $events))->toBe([1, 2])
            ->and($subscriber->state())->toBe(RealtimeState::Reconnecting)
            ->and($this->transport->isConnected())->toBeFalse()
            ->and($object->getProperty('nextAttemptAt')->getValue($subscriber))->toBe(1001.0)
            ->and($subscriber->poll())->toBe([])
            ->and($subscriber->poll())->toBe([]);
    })->with([false, true]);
});
