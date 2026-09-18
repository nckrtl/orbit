<?php

declare(strict_types=1);

namespace App\Support\Console;

use App\Support\Console\Renderers\TableLayout;
use Closure;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Terminal;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class CommandPrompts
{
    /** Rows a data list shows before it scrolls. */
    private const int SCROLL_ROWS = 15;

    public function __construct(
        private ConsoleMode $mode,
        private OutputInterface $output,
        private ?Terminal $terminal = null,
    ) {}

    /** @param Closure(): Prompt $makePrompt */
    public function run(Closure $makePrompt): mixed
    {
        if (! $this->mode->mayPrompt || $this->mode->machine) {
            throw new PromptAborted('Interactive input is not available.', 'interaction_forbidden');
        }

        return PromptContext::run($this->mode, $this->output, function () use ($makePrompt): mixed {
            $prompt = $makePrompt();

            return PromptContext::displayPrompt($prompt);
        }, $this->terminal);
    }

    /**
     * @param  list<string>  $headers
     * @param  array<int|string, list<string>>  $rows
     * @param  (Closure(int|string): ?string)|null  $validate
     */
    public function selectEntity(string $label, array $headers, array $rows, ?Closure $validate = null): int|string
    {
        $selected = $this->run(function () use ($label, $headers, $rows, $validate): SearchableDataTablePrompt {
            if ($rows === []) {
                throw new PromptAborted('No matching records were found.', 'empty_selection');
            }

            $minimum = TableLayout::minimumWidth($headers, $rows);

            if ($this->mode->columns < $minimum) {
                throw new PromptAborted("Selection requires at least {$minimum} terminal columns; {$this->mode->columns} are available.", 'terminal_too_narrow');
            }

            return new SearchableDataTablePrompt(
                headers: $headers,
                rows: $rows,
                label: $label,
                required: true,
                validate: $validate,
                // Show every row of a short list; a long list scrolls inside a fixed window.
                scroll: max(1, min(count($rows), self::SCROLL_ROWS)),
            );
        });

        if ((! is_int($selected) && ! is_string($selected)) || ! array_key_exists($selected, $rows)) {
            throw new PromptAborted('The selected record is not available.', 'invalid_selection');
        }

        return $selected;
    }
}
