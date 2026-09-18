<?php

declare(strict_types=1);

namespace Design\Support;

use Laravel\Prompts\Themes\Default\DataTableRenderer;
use Laravel\Prompts\Themes\Default\Renderer;

/** Draws the tab bar, then the active tab: detail text as recorded, or the stock data list. */
final class TabbedShowRenderer extends Renderer
{
    public function __invoke(TabbedShowPrompt $prompt): string
    {
        if ($prompt->state === 'submit') {
            return '';
        }

        $bar = [];
        foreach ($prompt->tabs() as $index => $tab) {
            $bar[] = $index === $prompt->active
                ? $this->inverse(' '.$tab['title'].' ')
                : $this->dim(' '.$tab['title'].' ');
        }

        $this->line('');
        $this->line(' '.$this->bold($prompt->label));
        $this->line(' '.implode($this->dim('│'), $bar));
        $this->line('');

        $tab = $prompt->tabs()[$prompt->active];
        $body = isset($tab['list'])
            ? (string) (new DataTableRenderer($tab['list']))($tab['list'])
            : rtrim($tab['detail'] ?? '', "\n").PHP_EOL;

        $footer = $this->dim('  Tab or ←→ switch tabs'.(isset($tab['list']) ? ' · ↑↓ move · type to filter · Enter selects' : '').' · Ctrl-C leaves');

        return (string) $this.$body.PHP_EOL.$footer.PHP_EOL;
    }
}
