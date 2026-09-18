<?php

declare(strict_types=1);

namespace Design\Support;

use App\Support\Console\SearchableDataTablePrompt;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

/**
 * Design sketch: one record shown as tabs. A tab holds either rendered detail text or a
 * data list. Tab, Shift+Tab, and the arrow keys switch tabs; keys inside a list tab go to
 * that list, so typing filters, the arrows move, and Enter selects a row.
 *
 * @phpstan-type Tab array{title: string, detail?: string, list?: SearchableDataTablePrompt}
 */
final class TabbedShowPrompt extends Prompt
{
    public int $active = 0;

    /** @param list<Tab> $tabs */
    public function __construct(public readonly string $label, public readonly array $tabs)
    {
        // The base prompt validates on submit and expects these to be set.
        $this->required = false;
        $this->validate = null;

        foreach ($this->tabs as $tab) {
            if (isset($tab['list'])) {
                // A child list never renders itself, so it starts active straight away.
                $tab['list']->state = 'active';
            }
        }

        $this->on('key', fn (string $key) => $this->handleKey($key));
    }

    public function value(): mixed
    {
        $tab = $this->tabs[$this->active];
        if (isset($tab['list']) && $tab['list']->state === 'submit') {
            return ['tab' => $tab['title'], 'key' => $tab['list']->value()];
        }

        return null;
    }

    /** @return list<Tab> */
    public function tabs(): array
    {
        return $this->tabs;
    }

    private function handleKey(string $key): void
    {
        $count = count($this->tabs);

        if ($key === Key::TAB || $key === Key::RIGHT || $key === Key::RIGHT_ARROW) {
            $this->active = ($this->active + 1) % $count;

            return;
        }
        if ($key === Key::SHIFT_TAB || $key === Key::LEFT || $key === Key::LEFT_ARROW) {
            $this->active = ($this->active - 1 + $count) % $count;

            return;
        }

        $list = $this->tabs[$this->active]['list'] ?? null;
        if ($list === null) {
            return;
        }

        $list->emit('key', $key);
        if ($list->state === 'submit') {
            $this->submit();
        }
    }
}
