<?php

declare(strict_types=1);

namespace App\Support\Tui\Prompts;

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

    /** The frame the active theme renders for this prompt, with its ANSI colours. */
    public function frame(): string
    {
        return $this->renderTheme();
    }

    public function done(): bool
    {
        return $this->state === 'submit';
    }
}
