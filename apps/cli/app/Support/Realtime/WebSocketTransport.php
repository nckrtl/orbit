<?php

declare(strict_types=1);

namespace App\Support\Realtime;

/**
 * A minimal WebSocket client transport, scoped to what the Pusher protocol needs: connect,
 * send one JSON message, and drain whatever has arrived without blocking the caller.
 */
interface WebSocketTransport
{
    /**
     * Open the socket and complete the WebSocket handshake against $url (a `ws://` or `wss://`
     * URL including any path and query string the caller needs). $caPath, when given, pins the
     * TLS trust root the same way the rest of the CLI pins a gateway's certificate.
     *
     * @throws RealtimeConnectionException when the socket cannot be opened or the handshake fails.
     */
    public function connect(string $url, ?string $caPath = null, float $timeoutSeconds = 10.0): void;

    /**
     * Encode $message as JSON and send it as one WebSocket text frame.
     *
     * @param  array<string, mixed>  $message
     *
     * @throws RealtimeConnectionException when the socket is not connected or the write fails.
     */
    public function send(array $message): void;

    /**
     * Return the next decoded JSON message already available on the socket, or null immediately
     * when nothing is available. This method never blocks: a caller polls it in a loop to drain
     * everything currently buffered, including complete frames received before peer EOF.
     *
     * Ping frames are answered with a pong while connected and are never returned. A close
     * frame closes the transport (see isConnected()) and returns null.
     *
     * @return array<string, mixed>|null
     *
     * @throws RealtimeProtocolException when a received frame violates the WebSocket or JSON contract.
     */
    public function receive(): ?array;

    /** Close the socket and discard buffered messages. Safe to call when it is already closed. */
    public function close(): void;

    /** Whether the socket is currently open. False after close(), a close frame, or a dropped connection. */
    public function isConnected(): bool;
}
