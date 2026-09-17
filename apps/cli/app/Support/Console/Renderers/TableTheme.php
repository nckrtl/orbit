<?php

declare(strict_types=1);

namespace App\Support\Console\Renderers;

use App\Support\Console\ConsoleMode;
use App\Support\Console\SearchableDataTablePrompt;
use Closure;
use Laravel\Prompts\ConfirmPrompt;
use Laravel\Prompts\DataTablePrompt;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Table;
use LogicException;

final class TableTheme
{
    private static ?ConsoleMode $mode = null;

    /**
     * PromptContext owns restoration of the full Prompts static configuration.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public static function run(ConsoleMode $mode, Closure $operation): mixed
    {
        $previousMode = self::$mode;
        $previousTheme = Prompt::theme();
        self::$mode = $mode;
        Prompt::addTheme('orbit-cli', self::renderers());
        Prompt::theme('orbit-cli');

        try {
            return $operation();
        } finally {
            Prompt::theme($previousTheme);
            self::$mode = $previousMode;
        }
    }

    /** @var array<class-string<Prompt>, class-string> Renderers added at runtime, such as design sketches. */
    private static array $extensions = [];

    /** @param array<class-string<Prompt>, class-string> $renderers */
    public static function extend(array $renderers): void
    {
        self::$extensions = [...self::$extensions, ...$renderers];
    }

    /** @return array<class-string<Prompt>, class-string> */
    public static function renderers(): array
    {
        // The data list is the stock Laravel Prompts rendering, minus the summary a chosen row would leave behind.
        return [Table::class => TableRenderer::class, DataTablePrompt::class => EphemeralDataTableRenderer::class, SearchableDataTablePrompt::class => EphemeralDataTableRenderer::class, ConfirmPrompt::class => ConfirmRenderer::class, ...self::$extensions];
    }

    public static function mode(): ConsoleMode
    {
        return self::$mode ?? throw new LogicException('The table theme requires an invocation context.');
    }
}
