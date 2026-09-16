<?php

declare(strict_types=1);

namespace App\Support\Console;

final readonly class StandardInput implements StandardInputReader
{
    public function read(): string
    {
        $stream = defined('STDIN') ? STDIN : fopen('php://stdin', 'r');

        if (! is_resource($stream)) {
            return '';
        }

        $contents = stream_get_contents($stream);

        return is_string($contents) ? $contents : '';
    }
}
