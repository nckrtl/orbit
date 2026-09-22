<?php

declare(strict_types=1);

namespace App\Support\Tui\Prompts;

use App\Support\Console\TerminalText;

/**
 * A Laravel Prompts prompt drawn inside a php-tui panel instead of on the terminal.
 *
 * A prompt is never run through Prompt::prompt(); the panel forwards the keys it receives and
 * draws the frame the prompt's own theme renderer returns. That keeps the rendering and
 * validation of the CLI's own prompts as the single source; the panel only decides where the
 * frame goes. Ported from the `design:top` sketch (see `apps/cli/design/README.md`).
 */
trait PanelPrompt
{
    /** Feeds one key the way Prompt::prompt() would, minus the terminal. */
    public function press(string $key): void
    {
        if ($this->state === 'error') {
            $this->state = 'active';
        }
        $this->emit('key', $key);
    }

    /** Escape a display copy before the theme adds its trusted ANSI colours. */
    public function frame(): string
    {
        $display = clone $this;
        $display->prepareDisplay();

        return $display->renderTheme();
    }

    protected function prepareDisplay(): void
    {
        $this->label = TerminalText::safe($this->label);
        $this->hint = TerminalText::safe($this->hint);
        $this->error = TerminalText::safe($this->error);
        $this->cancelMessage = TerminalText::safe($this->cancelMessage);
    }

    public function done(): bool
    {
        return $this->state === 'submit';
    }
}
