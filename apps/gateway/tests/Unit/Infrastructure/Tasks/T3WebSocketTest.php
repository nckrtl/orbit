<?php

declare(strict_types=1);

use App\Infrastructure\Tasks\T3WebSocket;

it('reads coalesced and fragmented frames without losing handshake bytes', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    expect($server)->not->toBeFalse();
    $address = stream_socket_get_name($server, false);
    $pid = pcntl_fork();
    if ($pid === 0) {
        $client = stream_socket_accept($server, 5);
        if ($client === false) {
            exit(1);
        }
        $headers = '';
        while (! str_contains($headers, "\r\n\r\n")) {
            $headers .= fread($client, 8192);
        }
        preg_match('/Sec-WebSocket-Key: (.+)\r\n/', $headers, $matches);
        $accept = base64_encode(sha1(trim($matches[1]).'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $frame = static fn (string $text, int $opcode = 0x81): string => chr($opcode).chr(strlen($text)).$text;
        fwrite($client, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n"
            .$frame('{"first":1}').$frame('{"second":2}')
            .$frame('{"third":', 0x01).$frame('ping', 0x89).$frame('3}', 0x80));
        stream_set_timeout($client, 2);
        fread($client, 4096);
        fclose($client);
        fclose($server);
        exit(0);
    }
    fclose($server);
    $socket = new T3WebSocket;
    try {
        $socket->connect('ws://'.$address.'/ws');
        expect($socket->receive(2))->toBe(['first' => 1])
            ->and($socket->receive(2))->toBe(['second' => 2])
            ->and($socket->receive(2))->toBe(['third' => 3]);
    } finally {
        $socket->close();
        pcntl_waitpid($pid, $status);
    }
});
