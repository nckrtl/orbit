<?php

declare(strict_types=1);

/*
 * A scripted TLS WebSocket server for StreamWebSocketClient and AgentViewSubscriber tests.
 *
 * Usage: php websocket-server.php CERT KEY MODE [SECRET NODE]
 * It prints `port=N` once it listens, then one line per event (`accepted`, `pong=...`,
 * `subscribe=...`) so the test can follow it. Server frames are unmasked, as RFC 6455 requires.
 */

[$script, $cert, $key, $mode] = array_pad($argv, 4, '');
$secret = $argv[4] ?? '';
$nodeId = (int) ($argv[5] ?? 0);

$context = stream_context_create(['ssl' => ['local_cert' => $cert, 'local_pk' => $key, 'verify_peer' => false]]);
// The server accepts plain TCP and starts TLS itself, so the `reset` mode can keep the raw socket.
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

if ($server === false) {
    fwrite(STDERR, "listen failed: {$error}\n");
    exit(1);
}

$name = (string) stream_socket_get_name($server, false);
say('port='.substr($name, strrpos($name, ':') + 1));

function say(string $line): void
{
    fwrite(STDOUT, $line."\n");
    fflush(STDOUT);
}

/**
 * Resets the connection like a crashed Reverb: no close frame and no TLS close_notify, only a TCP
 * reset. Closing the PHP stream would send close_notify first, and the client would read a clean
 * end of stream, so the server kills itself with the socket set to linger zero.
 */
function reset_connection(Socket $socket): never
{
    socket_set_option($socket, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
    say('reset=yes');
    posix_kill(getmypid(), SIGKILL);

    exit(1);
}

function frame(int $opcode, string $payload, bool $final = true): string
{
    $length = strlen($payload);
    $head = chr(($final ? 0x80 : 0) | $opcode);

    return $head.match (true) {
        $length <= 125 => chr($length),
        $length <= 65535 => chr(126).pack('n', $length),
        default => chr(127).pack('J', $length),
    }.$payload;
}

function text(array $message): string
{
    return frame(0x1, json_encode($message));
}

/** @return array{0: int, 1: string}|null */
function readFrame($client): ?array
{
    $head = fread($client, 2);

    if ($head === false || strlen($head) < 2) {
        return null;
    }

    $opcode = ord($head[0]) & 0x0F;
    $length = ord($head[1]) & 0x7F;

    if ($length === 126) {
        $length = unpack('n', fread($client, 2))[1];
    } elseif ($length === 127) {
        $length = unpack('J', fread($client, 8))[1];
    }

    $mask = (ord($head[1]) & 0x80) === 0x80 ? fread($client, 4) : null;
    $payload = $length > 0 ? stream_get_contents($client, $length) : '';

    if ($mask !== null) {
        for ($i = 0; $i < $length; $i++) {
            $payload[$i] = $payload[$i] ^ $mask[$i % 4];
        }
    }

    return [$opcode, $payload];
}

/** Reads the client's handshake and answers it; returns false for a refused handshake. */
function handshake($client, string $mode): bool
{
    $request = '';

    while (! str_contains($request, "\r\n\r\n")) {
        $line = fgets($client);

        if ($line === false) {
            return false;
        }

        $request .= $line;
    }

    say('request='.strtok($request, "\r\n"));
    preg_match('/^Sec-WebSocket-Key:\s*(\S+)/mi', $request, $matches);
    $accept = base64_encode(sha1(($matches[1] ?? '').'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

    if ($mode === 'refuse') {
        fwrite($client, "HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\n\r\n");

        return false;
    }

    if ($mode === 'bad-accept') {
        $accept = base64_encode('wrong');
    }

    fwrite($client, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n");

    return $mode !== 'bad-accept';
}

$connections = $mode === 'pusher' ? 2 : 1;

for ($connection = 1; $connection <= $connections; $connection++) {
    $client = @stream_socket_accept($server, 30);

    if ($client === false) {
        exit(1);
    }

    $socket = str_starts_with($mode, 'reset') ? socket_import_stream($client) : null;

    if (stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
        fclose($client);

        continue;
    }

    say('accepted='.microtime(true));

    if ($mode === 'reset-handshake') {
        // Read the whole handshake request, then reset instead of answering it.
        while (($line = fgets($client)) !== false && $line !== "\r\n") {
        }

        reset_connection($socket);
    }

    if (! handshake($client, $mode)) {
        fclose($client);

        continue;
    }

    if ($mode === 'reset') {
        // The client is connected and waiting to read.
        usleep(200_000);
        reset_connection($socket);
    }

    if ($mode === 'reset-after-frame') {
        // Once the client is connected and waiting, a whole message and then a reset before it reads.
        usleep(200_000);
        fwrite($client, text(['event' => 'last']));
        reset_connection($socket);
    }

    if ($mode === 'ping') {
        // A ping the client cannot answer, because the test shut its write side.
        usleep(200_000);
        fwrite($client, frame(0x9, 'p'));
        say('pinged=yes');
        // Keep the connection open, so only the client's failed write can end it.
        sleep(30);

        continue;
    }

    if ($mode === 'huge') {
        // A frame header that claims 2 MiB: the client must close instead of buffering it.
        fwrite($client, chr(0x81).chr(127).pack('J', 2 * 1024 * 1024).str_repeat('x', 1000));
        say('closed='.(fread($client, 1) === '' ? 'yes' : 'no'));
        fclose($client);

        continue;
    }

    if ($mode === 'frames') {
        fwrite($client, text(['event' => 'one']));
        fwrite($client, frame(0x9, 'p'));
        fwrite($client, frame(0x1, '{"event":"frag', false).frame(0x0, 'mented"}'));
        fwrite($client, text(['event' => 'too-big', 'data' => str_repeat('x', 70_000)]));
        fwrite($client, frame(0x1, '{"event":"big-fragment","data":"'.str_repeat('y', 40_000), false));
        fwrite($client, frame(0x0, str_repeat('y', 40_000), false));
        fwrite($client, frame(0x0, '{"event":"tail"}'));
        fwrite($client, frame(0x1, 'not json'));
        fwrite($client, frame(0x1, '[1,2]'));
        fwrite($client, frame(0x2, "\x00\x01"));
        fwrite($client, text(['event' => 'two']));

        [$opcode, $payload] = readFrame($client) ?? [0, ''];
        say('pong='.($opcode === 0xA ? $payload : 'none'));
        [$opcode, $payload] = readFrame($client) ?? [0, ''];
        fwrite($client, text(['event' => 'echo', 'data' => $opcode === 0x1 ? $payload : null]));
        fwrite($client, frame(0x8, pack('n', 1000)));
        readFrame($client);
        fclose($client);

        continue;
    }

    if ($mode === 'pusher') {
        $socketId = "{$connection}00.{$connection}";
        fwrite($client, text(['event' => 'pusher:connection_established', 'data' => json_encode(['socket_id' => $socketId, 'activity_timeout' => 30])]));
        $channel = "presence-node.{$nodeId}";

        while (($frame = readFrame($client)) !== null) {
            $message = json_decode($frame[1], true);

            if (($message['event'] ?? null) !== 'pusher:subscribe' || ($message['data']['channel'] ?? null) !== $channel) {
                continue;
            }

            $channelData = $message['data']['channel_data'];
            $expected = hash_hmac('sha256', "{$socketId}:{$channel}:{$channelData}", $secret);
            $valid = str_ends_with($message['data']['auth'], ':'.$expected);
            say('subscribe='.($valid ? 'signed' : 'unsigned').' member='.(json_decode($channelData, true)['user_id'] ?? ''));

            if (! $valid) {
                break;
            }

            fwrite($client, text(['event' => 'pusher_internal:subscription_succeeded', 'channel' => $channel, 'data' => json_encode(['presence' => ['ids' => ["agent.{$nodeId}"], 'hash' => [], 'count' => 1]])]));
            fwrite($client, text(['event' => 'client-snapshot', 'channel' => $channel, 'user_id' => "agent.{$nodeId}", 'data' => ['sequence' => 1, 'at' => '2026-09-25T10:00:00Z', 'docker' => 'available', 'part' => 1, 'parts' => 1, 'units' => [['name' => 'orbit-process-7-web', 'runtime' => 'systemd', 'runtime_status' => 'active']]]]));

            if ($connection === 1) {
                // Let the client read the snapshot, then drop the connection like a Reverb restart.
                usleep(1_500_000);
                fwrite($client, frame(0x8, pack('n', 1001)));
                say('dropped='.microtime(true));
                break;
            }

            usleep(3_000_000);
            break;
        }

        fclose($client);
    }
}
