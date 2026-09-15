<?php

declare(strict_types=1);

namespace App\Support\Console\Renderers;

use App\Support\Console\ConsoleMode;
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

    /** @return array<class-string<Prompt>, class-string> */
    public static function renderers(): array
    {
        return [Table::class => TableRenderer::class, DataTablePrompt::class => DataTableRenderer::class, ConfirmPrompt::class => ConfirmRenderer::class];
    }

    public static function mode(): ConsoleMode
    {
        return self::$mode ?? throw new LogicException('The table theme requires an invocation context.');
    }
}
