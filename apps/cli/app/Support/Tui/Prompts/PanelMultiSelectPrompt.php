<?php

declare(strict_types=1);

namespace App\Support\Tui\Prompts;

use App\Support\Console\TerminalText;
use Laravel\Prompts\MultiSelectPrompt;

/** A MultiSelectPrompt drawn inside a php-tui panel; see PanelPrompt. */
final class PanelMultiSelectPrompt extends MultiSelectPrompt
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
        $this->info = TerminalText::safe($this->infoText());
        if (array_is_list($this->options)) {
            $options = [];
            $values = [];
            foreach ($this->options as $index => $label) {
                $key = 'display-'.$index;
                $options[$key] = $label;
                if (in_array($label, $this->values)) {
                    $values[] = $key;
                }
            }
            $this->options = $options;
            $this->values = $values;
        }
        $this->options = array_map(TerminalText::safe(...), $this->options);
    }
}
