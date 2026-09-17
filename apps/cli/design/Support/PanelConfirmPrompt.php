<?php

declare(strict_types=1);

namespace Design\Support;

use Laravel\Prompts\ConfirmPrompt;

/** A ConfirmPrompt drawn inside a php-tui panel; see PanelPrompt. */
final class PanelConfirmPrompt extends ConfirmPrompt
{
    use PanelPrompt;

    public static function make(mixed ...$arguments): self
    {
        $prompt = new self(...$arguments);
        $prompt->state = 'active';

        return $prompt;
    }
}
