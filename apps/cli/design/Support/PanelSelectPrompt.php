<?php

declare(strict_types=1);

namespace Design\Support;

use Laravel\Prompts\SelectPrompt;

/** A SelectPrompt drawn inside a php-tui panel; see PanelPrompt. */
final class PanelSelectPrompt extends SelectPrompt
{
    use PanelPrompt;

    public static function make(mixed ...$arguments): self
    {
        $prompt = new self(...$arguments);
        $prompt->state = 'active';

        return $prompt;
    }
}
