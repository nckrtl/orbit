<?php

declare(strict_types=1);

namespace App\Support\Realtime;

/**
 * A queueable marker for FakeWebSocketTransport that simulates the peer sending a WebSocket
 * close frame: receive() returns null (as a real close frame does) and isConnected() then
 * reports false, distinct from a thrown Throwable which fails the in-flight receive() call.
 */
final class FakeWebSocketClose {}
