<?php

declare(strict_types=1);

namespace App\Support\Console;

use Closure;
use LogicException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class ProgressDisplay
{
    /** @var array<string, array{waiting: string, running: string, completed: string, state: ProgressState, message: string}> */
    private array $steps = [];

    private bool $finished = false;

    private bool $started = false;

    private readonly TerminalRegion $region;

    public function __construct(
        private readonly ConsoleMode $mode,
        private readonly OutputInterface $output,
        private readonly string $title,
    ) {
        $this->region = new TerminalRegion($mode, $output);
    }

    public function admit(string $id, string $waiting, string $running, string $completed): void
    {
        if ($this->finished || isset($this->steps[$id]) || $this->hasState(ProgressState::Failure)) {
            throw new LogicException('Progress step cannot be admitted.');
        }

        $this->steps[$id] = [
            'waiting' => TerminalText::safe($waiting),
            'running' => TerminalText::safe($running),
            'completed' => TerminalText::safe($completed),
            'state' => ProgressState::Waiting,
            'message' => '',
        ];
    }

    /**
     * The caller settles the returned product result explicitly with complete().
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function during(string $id, Closure $operation): mixed
    {
        if ($this->finished || $this->hasState(ProgressState::Failure) || $this->hasState(ProgressState::Running)
            || ($this->steps[$id]['state'] ?? null) !== ProgressState::Waiting) {
            throw new LogicException('Only an admitted waiting step can start.');
        }

        if (! $this->started && $this->mode->mayRepaint) {
            $this->region->replace($this->frame());
        }

        $this->started = true;
        $this->steps[$id]['state'] = ProgressState::Running;
        $animation = new Animation(
            $this->mode,
            $this->output,
            [$this->frame(), $this->frame(alternate: true)],
            implode("\n", TerminalText::wrap($this->steps[$id]['running'].'...', $this->mode->columns))."\n",
            $this->region,
            fn (): string => $this->frame(),
        );

        try {
            $result = $animation->during($operation);
            InterruptIntent::throwIfPending();

            return $result;
        } catch (Throwable $exception) {
            try {
                $this->complete($id, ProgressState::Failure);
                $this->finish(InterruptIntent::cancellation($exception) ? 'Operation interrupted.' : 'Operation failed.');
            } catch (Throwable) {
                // Output failure must not replace the callback's original failure.
            }

            throw $exception;
        }
    }

    public function complete(string $id, ProgressState $state, string $message = ''): void
    {
        $current = $this->steps[$id]['state'] ?? null;

        if ($this->finished || ! $state->terminal()
            || ($current !== ProgressState::Running && ! ($current === ProgressState::Waiting && $state === ProgressState::Skipped))) {
            throw new LogicException('Progress cannot regress or settle unstarted work.');
        }

        $this->steps[$id]['state'] = $state;
        $this->steps[$id]['message'] = TerminalText::safe($message);

        if ($this->mode->mayRepaint) {
            $this->region->replace($this->frame());
        }
    }

    public function finish(string $outcome): void
    {
        if ($this->finished || $this->hasState(ProgressState::Running)
            || ($this->hasState(ProgressState::Waiting) && ! $this->hasState(ProgressState::Failure))) {
            throw new LogicException('Progress cannot finish while admitted work is unresolved.');
        }

        $this->finished = true;

        if (! $this->mode->machine) {
            $this->region->replace($this->frame(outcome: $outcome));
        }
    }

    private function frame(bool $alternate = false, ?string $outcome = null): string
    {
        $lines = [''];
        array_push($lines, ...$this->row('┌  ', TerminalText::safe($this->title)));
        $lines[] = $this->style('│', 'dim');

        foreach ($this->steps as $step) {
            $state = $step['state'];
            $glyph = match ($state) {
                ProgressState::Waiting => '○',
                ProgressState::Running => $alternate ? '◉' : '○',
                default => '●',
            };
            $color = match ($state) {
                ProgressState::Waiting => 'dim',
                ProgressState::Running => 'cyan',
                ProgressState::Success => 'green',
                ProgressState::Failure => 'red',
                ProgressState::Warning, ProgressState::Skipped => 'orange',
            };
            $label = match ($state) {
                ProgressState::Waiting, ProgressState::Skipped => $step['waiting'],
                ProgressState::Running, ProgressState::Failure => $step['running'],
                default => $step['completed'],
            };
            $prefix = $this->style('├  ', 'dim').$this->style($glyph, $color).' ';
            array_push($lines, ...$this->row($prefix, $label, $state === ProgressState::Waiting ? 'dim' : null));

            if ($step['message'] !== '') {
                array_push($lines, ...$this->row('│    ', $step['message'], match ($state) {
                    ProgressState::Failure => 'red',
                    ProgressState::Warning, ProgressState::Skipped => 'dim',
                    default => null,
                }));
            }

            $lines[] = $this->style('│', 'dim');
        }

        array_push($lines, ...$this->row('└  ', TerminalText::safe($outcome ?? 'Working...'),
            $outcome === null ? 'dim' : ($this->hasState(ProgressState::Failure) ? 'red' : null)));
        $lines[] = '';

        return implode("\n", $lines)."\n";
    }

    /** @return non-empty-list<string> */
    private function row(string $prefix, string $text, ?string $style = null): array
    {
        $prefixWidth = TerminalText::width($prefix);

        if ($this->mode->columns < $prefixWidth + TerminalText::minimumWidth($text)) {
            $prefix = '';
            $prefixWidth = 0;
        }

        $parts = TerminalText::wrap($text, $this->mode->columns - $prefixWidth);

        foreach ($parts as $index => &$part) {
            $connector = $index === 0 ? $prefix : ($prefixWidth > 0 ? '│'.str_repeat(' ', $prefixWidth - 1) : '');
            $connector = str_contains($connector, "\e") ? $connector : $this->style($connector, 'dim');
            $part = $connector.($style !== null ? $this->style($part, $style) : $part);
        }

        return $parts;
    }

    private function hasState(ProgressState $state): bool
    {
        return array_any($this->steps, static fn (array $step): bool => $step['state'] === $state);
    }

    private function style(string $text, string $style): string
    {
        return TerminalText::style($text, $style, $this->mode->decorated && ! $this->mode->machine);
    }
}
