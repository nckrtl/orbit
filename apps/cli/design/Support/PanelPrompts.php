<?php

declare(strict_types=1);

namespace Design\Support;

use Laravel\Prompts\ConfirmPrompt;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\SelectPrompt;
use Laravel\Prompts\TextPrompt;

/**
 * Design sketch: Laravel Prompts drawn inside a php-tui panel instead of on the terminal.
 *
 * A prompt is never run; the panel forwards the keys it receives and draws the frame the
 * prompt's own theme renderer returns. That keeps the rendering and validation of the CLI
 * prompts as the single source, the panel only decides where the frame goes.
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

final class PanelTextPrompt extends TextPrompt
{
    use PanelPrompt;

    public static function make(mixed ...$arguments): self
    {
        $prompt = new self(...$arguments);
        $prompt->state = 'active';

        return $prompt;
    }
}

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
