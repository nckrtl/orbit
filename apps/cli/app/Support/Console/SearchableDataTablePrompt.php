<?php

declare(strict_types=1);

namespace App\Support\Console;

use Laravel\Prompts\DataTablePrompt;
use Laravel\Prompts\Key;

/**
 * The interactive data list of the CLI standard: typing filters the rows at once, the arrow
 * keys move the highlight, and Enter selects. There is no separate search mode to enter.
 */
final class SearchableDataTablePrompt extends DataTablePrompt
{
    /** Rows the box shows before it scrolls; the box shrinks to fewer matches. */
    private readonly int $window;

    /**
     * @param  list<string>  $headers
     * @param  array<int|string, list<string>>  $rows
     */
    public function __construct(array $headers, array $rows, string $label, string $hint = '', bool|string $required = false, mixed $validate = null, int $scroll = 10)
    {
        parent::__construct(headers: $headers, rows: $rows, scroll: $scroll, label: $label, hint: $hint, required: $required, validate: $validate);
        $this->window = $scroll;

        $this->on('key', function (string $key): void {
            if ($this->state !== 'active' || $key === '') {
                return;
            }
            if ($key === Key::BACKSPACE || $key === Key::CTRL_H) {
                $this->typedValue = mb_substr($this->typedValue, 0, -1);
            } elseif ($key === Key::CTRL_U) {
                $this->typedValue = '';
            } elseif ($key[0] === "\e" || $key === Key::ENTER || $key === "\r" || ord($key[0]) < 32) {
                return;
            } else {
                // A pasted chunk can carry control characters; keep only the printable text.
                $this->typedValue .= (string) preg_replace('/[\x00-\x1f\x7f]/', '', $key);
            }
            $this->cursorPosition = mb_strlen($this->typedValue);
            $this->search();
            $this->scroll = max(1, min(count($this->filteredRows()), $this->window));
        });
    }

    /** The current filter text. */
    public function filterText(): string
    {
        return $this->typedValue;
    }
}
