<?php

declare(strict_types=1);

namespace App\Support\Tui\Prompts;

use Laravel\Prompts\MultiSelectPrompt;

/** A MultiSelectPrompt drawn inside a php-tui panel; see PanelPrompt. */
final class PanelMultiSelectPrompt extends MultiSelectPrompt
{
    use PanelPrompt;

    public static function make(mixed ...$arguments): self
    {
        $prompt = new self(...$arguments);
        $prompt->state = 'active';

        return $prompt;
    }
}
