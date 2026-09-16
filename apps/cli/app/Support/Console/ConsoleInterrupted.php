<?php

declare(strict_types=1);

namespace App\Support\Console;

use RuntimeException;

final class ConsoleInterrupted extends RuntimeException
{
    public function __construct(public readonly int $signal)
    {
        InterruptIntent::record($signal);
        parent::__construct('Command interrupted.', 128 + $signal);
    }
}
