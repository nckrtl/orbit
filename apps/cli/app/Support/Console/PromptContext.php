<?php

declare(strict_types=1);

namespace App\Support\Console;

use App\Support\Console\Renderers\TableTheme;
use Closure;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Terminal;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

abstract class PromptContext extends Prompt
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public static function preserve(Closure $operation): mixed
    {
        $output = self::output();
        $terminal = self::terminal();
        $interactive = self::$interactive ?? stream_isatty(STDIN);
        $cancel = self::$cancelUsing ?? null;
        $validate = self::$validateUsing ?? null;
        $revert = self::$revertUsing;
        $fallback = self::$shouldFallback;
        $fallbacks = self::$fallbacks;
        $theme = self::$theme;
        $themes = self::$themes;
        $cursorHidden = self::$cursorHidden;

        try {
            return $operation();
        } finally {
            self::$output = $output;
            self::$terminal = $terminal;
            self::$interactive = $interactive;
            self::$cancelUsing = $cancel;
            self::$validateUsing = $validate;
            self::$revertUsing = $revert;
            self::$shouldFallback = $fallback;
            self::$fallbacks = $fallbacks;
            self::$theme = $theme;
            self::$themes = $themes;
            self::$cursorHidden = $cursorHidden;
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public static function run(ConsoleMode $mode, OutputInterface $output, Closure $operation, ?Terminal $terminal = null): mixed
    {
        return self::preserve(function () use ($mode, $output, $operation, $terminal): mixed {
            $previousOutput = self::output();
            $previousCursorHidden = self::$cursorHidden;
            $inputTerminal = $terminal ?? new InputTerminal(columns: $mode->columns);
            $scopeOutput = new PromptOutput($output, $mode);
            $scopeTerminal = new PromptTerminal($inputTerminal, static function () use ($scopeOutput): void {
                // Vendor showCursor changes its shared flag after writing.
                // Its destructor then reaches this terminal callback.
                self::$cursorHidden = $scopeOutput->cursorHidden();
            });
            self::$terminal = $scopeTerminal;
            self::$output = $scopeOutput;
            self::$interactive = $mode->mayPrompt;
            self::$cursorHidden = false;
            self::$cancelUsing = static fn (): never => throw new PromptAborted;

            $failure = null;

            try {
                return TableTheme::run($mode, $operation);
            } catch (Throwable $exception) {
                $failure = $exception;

                throw $exception;
            } finally {
                $cleanupFailure = null;

                try {
                    self::restoreTerminal($scopeTerminal);
                } catch (Throwable $exception) {
                    $cleanupFailure = $exception;
                }

                try {
                    if ($previousCursorHidden) {
                        $previousOutput->write("\e[?25l", false, OutputInterface::OUTPUT_RAW);
                    }
                } catch (Throwable $exception) {
                    $cleanupFailure ??= $exception;
                }

                if ($failure === null && $cleanupFailure !== null) {
                    throw $cleanupFailure;
                }
            }
        });
    }

    public static function displayPrompt(Prompt $prompt): mixed
    {
        $output = self::output();

        if ($output instanceof PromptOutput) {
            $output->forPrompt($prompt);
        }

        try {
            $terminal = self::terminal();
            $fallback = $prompt::shouldFallback();

            if ($terminal instanceof PromptTerminal) {
                $terminal->assertInputAvailable();
            }

            $result = $prompt->prompt();

            if ($fallback && $terminal instanceof PromptTerminal) {
                $terminal->assertInputAvailable();
            }

            return $result;
        } finally {
            if ($output instanceof PromptOutput) {
                $output->forPrompt(null);
            }
        }
    }

    private static function restoreTerminal(PromptTerminal $terminal): void
    {
        $output = self::output();

        try {
            $terminal->close();
        } finally {
            try {
                if ($output instanceof PromptOutput) {
                    $output->restoreCursor();
                }
            } finally {
                self::$cursorHidden = false;
            }
        }

        if ($output instanceof PromptOutput && $output->cursorFailure() !== null) {
            throw $output->cursorFailure();
        }
    }
}
