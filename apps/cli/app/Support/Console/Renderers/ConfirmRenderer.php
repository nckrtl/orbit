<?php

declare(strict_types=1);

namespace App\Support\Console\Renderers;

use App\Support\Console\TerminalText;
use Laravel\Prompts\ConfirmPrompt;
use Laravel\Prompts\Themes\Default\ConfirmPromptRenderer;

final class ConfirmRenderer extends ConfirmPromptRenderer
{
    public function __invoke(ConfirmPrompt $prompt): string
    {
        $width = max(1, TableTheme::mode()->columns - 6);

        if (TerminalText::width($prompt->label) <= $width
            && ($prompt->state !== 'error' || TerminalText::width($prompt->error) <= $width)) {
            return parent::__invoke($prompt);
        }

        $this->minWidth = $width;
        $question = implode(PHP_EOL, TerminalText::wrap($prompt->label, $width));
        $options = $prompt->state === 'submit' ? $prompt->label() : $this->renderOptions($prompt);
        $color = match ($prompt->state) {
            'cancel' => 'red',
            'error' => 'yellow',
            default => 'gray',
        };

        $this->box('', $question.PHP_EOL.PHP_EOL.$options, color: $color);

        return (string) match ($prompt->state) {
            'cancel' => $this->error($prompt->cancelMessage),
            'error' => $this->warning($prompt->error),
            'submit' => $this,
            default => $prompt->hint !== '' ? $this->hint($prompt->hint) : $this->newLine(),
        };
    }

    protected function warning(string $message): self
    {
        return $this->feedback($message, 'yellow', '  ⚠ ');
    }

    protected function error(string $message): self
    {
        return $this->feedback($message, 'red', '  ⚠ ');
    }

    protected function hint(string $message): self
    {
        return $message === '' ? $this : $this->feedback($message, 'gray', '  ');
    }

    private function feedback(string $message, string $color, string $prefix): self
    {
        $width = max(1, TableTheme::mode()->columns - TerminalText::width($prefix));

        foreach (TerminalText::wrap($message, $width) as $line) {
            $this->line($this->{$color}($prefix.$line));
        }

        return $this;
    }
}
