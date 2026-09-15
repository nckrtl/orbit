<?php

declare(strict_types=1);

namespace App\Support\Console;

use Closure;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final readonly class SpinnerDisplay
{
    public function __construct(private ConsoleMode $mode, private OutputInterface $output) {}

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function during(string $label, Closure $operation): mixed
    {
        $label = TerminalText::safe($label);
        $prefixWidth = $this->mode->columns >= 2 + TerminalText::minimumWidth($label) ? 2 : 0;
        $parts = TerminalText::wrap($label, $this->mode->columns - $prefixWidth);
        $text = implode("\n".str_repeat(' ', $prefixWidth), $parts)."\n";
        $frames = array_map(fn (string $glyph): string => ($prefixWidth > 0 ? TerminalText::style($glyph, 'cyan', $this->mode->decorated).' ' : '').$text, ['○', '◉']);
        $plain = implode("\n", TerminalText::wrap($label.'...', $this->mode->columns))."\n";
        $settled = $this->outcome('Wait interrupted.', 'red');
        $region = new TerminalRegion($this->mode, $this->output);

        try {
            $result = new Animation($this->mode, $this->output, $frames, $plain, $region,
                static function () use (&$settled): string {
                    return $settled;
                },
            )->during(function () use ($operation, &$settled): mixed {
                try {
                    $value = $operation();
                    $settled = $this->outcome('Wait finished.');

                    return $value;
                } catch (Throwable $exception) {
                    $settled = $this->outcome($exception instanceof ConsoleInterrupted ? 'Wait interrupted.' : 'Wait failed.', 'red');

                    throw $exception;
                }
            });
        } catch (Throwable $exception) {
            if (! $this->mode->machine && ! $this->mode->mayRepaint) {
                try {
                    ConsoleWriter::write($this->output, $this->outcome($exception instanceof ConsoleInterrupted ? 'Wait interrupted.' : 'Wait failed.', 'red'));
                } catch (Throwable) {
                    // Preserve the operation's exception when its output is unavailable.
                }
            }

            throw $exception;
        }

        if (! $this->mode->machine && ! $this->mode->mayRepaint) {
            ConsoleWriter::write($this->output, $settled);
        }

        return $result;
    }

    private function outcome(string $message, ?string $style = null): string
    {
        $lines = TerminalText::wrap($message, $this->mode->columns);

        return implode("\n", array_map(fn (string $line): string => $style !== null
            ? TerminalText::style($line, $style, $this->mode->decorated && ! $this->mode->machine)
            : $line, $lines))."\n";
    }
}
