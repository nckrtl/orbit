<?php

declare(strict_types=1);

namespace App\Support\Console;

/**
 * A resultState closure returns this instead of a bare ProgressState when the
 * generic completed label ("Updated Tool.") would misstate a non-success result.
 * The footer replaces it; the row still shows the state's own glyph and color.
 */
final readonly class ProgressOutcome
{
    public function __construct(
        public ProgressState $state,
        public string $footer,
    ) {}
}
