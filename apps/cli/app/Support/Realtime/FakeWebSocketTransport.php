<?php

declare(strict_types=1);

namespace App\Support\Realtime;

use Throwable;

/**
 * An in-memory WebSocketTransport double for tests. Construct or enqueue() a scripted sequence
 * of already-decoded Pusher messages; receive() replays them in order. A queued null simulates
 * "nothing available yet" without blocking, matching the non-blocking receive() contract. A
 * queued Throwable is thrown from receive() and marks the transport disconnected, simulating a
 * dropped socket. A queued FakeWebSocketClose simulates the peer sending a close frame: receive()
 * returns null and the transport then reports disconnected, without throwing. Either lets
 * reconnect behavior be exercised.
 */
final class FakeWebSocketTransport implements WebSocketTransport
{
    /** @var list<array{url: string, ca_path: ?string}> */
    public array $connections = [];

    /** @var list<array<string, mixed>> */
    public array $sent = [];

    private bool $connected = false;

    /** @param list<array<string, mixed>|Throwable|FakeWebSocketClose|null> $queue */
    public function __construct(private array $queue = []) {}

    /** @param  list<array<string, mixed>|Throwable|FakeWebSocketClose|null>  $messages */
    public function enqueue(array $messages): void
    {
        array_push($this->queue, ...$messages);
    }

    #[\Override]
    public function connect(string $url, ?string $caPath = null, float $timeoutSeconds = 10.0): void
    {
        $this->connections[] = ['url' => $url, 'ca_path' => $caPath];
        $this->connected = true;
    }

    #[\Override]
    public function send(array $message): void
    {
        if (! $this->connected) {
            throw new RealtimeConnectionException('The fake realtime socket is not connected.');
        }

        $this->sent[] = $message;
    }

    #[\Override]
    public function receive(): ?array
    {
        if (! $this->connected || $this->queue === []) {
            return null;
        }

        $next = array_shift($this->queue);

        if ($next instanceof Throwable) {
            $this->connected = false;

            throw $next;
        }

        if ($next instanceof FakeWebSocketClose) {
            $this->connected = false;

            return null;
        }

        return $next;
    }

    #[\Override]
    public function close(): void
    {
        $this->connected = false;
    }

    #[\Override]
    public function isConnected(): bool
    {
        return $this->connected;
    }
}
