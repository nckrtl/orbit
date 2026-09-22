<?php

declare(strict_types=1);

namespace App\Support\Tui\Prompts;

use App\Support\Console\TerminalText;
use Laravel\Prompts\TextPrompt;

/** A TextPrompt drawn inside a php-tui panel; see PanelPrompt. */
final class PanelTextPrompt extends TextPrompt
{
    use PanelPrompt {
        prepareDisplay as private prepareCommonDisplay;
    }

    public static function make(mixed ...$arguments): self
    {
        $prompt = new self(...$arguments);
        $prompt->state = 'active';

        return $prompt;
    }

    protected function prepareDisplay(): void
    {
        $this->prepareCommonDisplay();
        $this->cursorPosition = mb_strlen(TerminalText::safe(mb_substr($this->typedValue, 0, $this->cursorPosition)));
        $this->typedValue = TerminalText::safe($this->typedValue);
        $this->placeholder = TerminalText::safe($this->placeholder);
    }
}
