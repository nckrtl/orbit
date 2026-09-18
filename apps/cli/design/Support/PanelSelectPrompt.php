<?php

declare(strict_types=1);

namespace Design\Support;

use App\Support\Tui\Prompts\PanelPrompt;
use Laravel\Prompts\SelectPrompt;

/** A SelectPrompt drawn inside a php-tui panel; see PanelPrompt (moved to App\Support\Tui\Prompts with the real `orbit top` command). */
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
