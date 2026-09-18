<?php

declare(strict_types=1);

namespace App\Support\Realtime;

use RuntimeException;

/** The realtime socket sent a frame, message, or envelope that violates the Pusher protocol contract. */
final class RealtimeProtocolException extends RuntimeException {}
