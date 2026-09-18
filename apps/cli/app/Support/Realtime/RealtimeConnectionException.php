<?php

declare(strict_types=1);

namespace App\Support\Realtime;

use RuntimeException;

/** The realtime socket could not be reached, connected, or kept open. */
final class RealtimeConnectionException extends RuntimeException {}
