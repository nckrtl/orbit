<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentView;

/**
 * The part of a WebSocket client that the Pusher protocol needs: connect, send one JSON text
 * message, and receive the JSON messages that arrive within a short wait.
 */
interface WebSocketClient
{
    /** @throws WebSocketException when the connection or the handshake fails. */
    public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void;

    /**
     * @param  array<string, mixed>  $message
     *
     * @throws WebSocketException when the socket is closed or the write fails.
     */
    public function send(array $message): void;

    /**
     * Waits up to `$timeoutSeconds` for data and returns every complete JSON message that arrived.
     * Answers ping frames itself. A close frame, a dropped connection, or a protocol violation
     * closes the client; `isConnected()` then returns false.
     *
     * @return list<array<string, mixed>>
     */
    public function receive(float $timeoutSeconds): array;

    public function close(): void;

    public function isConnected(): bool;
}
