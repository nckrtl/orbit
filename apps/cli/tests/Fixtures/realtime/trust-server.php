<?php

declare(strict_types=1);

$configuration = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$context = stream_context_create(['ssl' => [
    'local_cert' => $configuration['certificate'],
    'local_pk' => $configuration['key'],
    'verify_peer' => false,
]]);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

if ($server === false) {
    throw new RuntimeException('Could not open fixture listener.');
}

echo stream_socket_get_name($server, false)."\n";
flush();

while ($connection = stream_socket_accept($server, 10)) {
    stream_set_timeout($connection, 3);

    if (($configuration['tls'] ?? true) && ! @stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
        fclose($connection);

        continue;
    }

    $request = '';

    while (! str_contains($request, "\r\n\r\n") && ($line = fgets($connection)) !== false) {
        $request .= $line;
    }

    if (! str_contains($request, "\r\n\r\n")) {
        fclose($connection);

        continue;
    }

    preg_match('/^Content-Length:\s*(\d+)/mi', $request, $length);
    $body = '';

    while (strlen($body) < (int) ($length[1] ?? 0)) {
        $chunk = fread($connection, (int) $length[1] - strlen($body));

        if ($chunk === false || $chunk === '') {
            break;
        }

        $body .= $chunk;
    }

    file_put_contents($configuration['trace'], json_encode(['headers' => $request, 'body' => $body], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);

    if (($configuration['delay_us'] ?? 0) > 0) {
        usleep($configuration['delay_us']);
    }

    if (preg_match('/^Sec-WebSocket-Key:\s*(\S+)/mi', $request, $matches) === 1) {
        $accept = base64_encode(sha1($matches[1].'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        @fwrite($connection, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n");
    } else {
        $body = json_encode($configuration['body'] ?? ['auth' => 'app-key:signature'], JSON_THROW_ON_ERROR);
        $status = $configuration['status'] ?? 200;
        $location = isset($configuration['location']) ? 'Location: '.$configuration['location']."\r\n" : '';
        @fwrite($connection, "HTTP/1.1 {$status} Fixture\r\nContent-Type: application/json\r\n{$location}Content-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n{$body}");
    }

    fclose($connection);
}

fclose($server);
