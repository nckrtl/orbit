<?php

declare(strict_types=1);

namespace App\Support\Console;

use Symfony\Component\Console\Output\OutputInterface;

/** One sequential panel at the end of its selected output stream. */
final class TerminalRegion
{
    private int $lines = 0;

    private string $frame = '';

    private ?Animation $compositor = null;

    public function __construct(private readonly ConsoleMode $mode, private readonly OutputInterface $output) {}

    public function clearSequence(): string
    {
        return $this->compositor === null && $this->mode->mayRepaint && $this->lines > 0 ? "\r\e[{$this->lines}A\e[J" : '';
    }

    public function record(string $frame): void
    {
        $this->lines = substr_count($frame, "\n");
        $this->frame = $frame;
    }

    public function content(): string
    {
        return $this->frame;
    }

    public function attach(Animation $compositor, string $frame): void
    {
        $this->compositor = $compositor;
        $this->record($frame);
    }

    public function detach(): void
    {
        $this->compositor = null;
        $this->record('');
    }

    public function replace(string $frame): void
    {
        if ($this->mode->machine) {
            return;
        }

        if (Animation::renderRegion($this, $this->output, $frame)) {
            return;
        }

        if ($this->compositor !== null && ! $this->compositor->active()) {
            $this->detach();
        }

        Animation::withoutRepainting(function () use ($frame): void {
            ConsoleWriter::write($this->output, $this->clearSequence().$frame);
            $this->record($frame);
        });
    }
}
