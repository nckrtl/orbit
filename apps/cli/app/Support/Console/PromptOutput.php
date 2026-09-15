<?php

declare(strict_types=1);

namespace App\Support\Console;

use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class PromptOutput extends Output
{
    private ?Prompt $prompt = null;

    private ?Throwable $cursorFailure = null;

    private bool $cursorHidden = false;

    private ?string $lastPlainFrame = null;

    public function __construct(private readonly OutputInterface $target, private readonly ConsoleMode $mode)
    {
        parent::__construct($target->getVerbosity(), $mode->decorated, clone $target->getFormatter());
    }

    public function forPrompt(?Prompt $prompt): void
    {
        $this->prompt = $prompt;
        $this->lastPlainFrame = null;
    }

    public function writeDirectly(string $message): void
    {
        if ($message === "\e[?25h") {
            // Vendor destructors use the current global output, even when the
            // destroyed prompt belongs to an earlier invocation.
            return;
        }

        if ($message === "\e[?25l") {
            $this->cursorHidden = true;
        }

        $this->writeCursor($message);
    }

    public function cursorHidden(): bool
    {
        return $this->cursorHidden;
    }

    public function restoreCursor(): void
    {
        if ($this->cursorHidden) {
            $this->writeCursor("\e[?25h");
            $this->cursorHidden = false;
        }
    }

    private function writeCursor(string $message): void
    {
        if ($this->mode->mayRepaint) {
            try {
                $this->target->write($message, false, OutputInterface::OUTPUT_RAW);
            } catch (Throwable $exception) {
                $this->cursorFailure ??= $exception;
            }
        }
    }

    public function cursorFailure(): ?Throwable
    {
        return $this->cursorFailure;
    }

    #[\Override]
    protected function doWrite(string $message, bool $newline): void
    {
        if (! $this->mode->mayRepaint) {
            if ($this->prompt !== null && ! in_array($this->prompt->state, ['initial', 'error', 'submit', 'cancel'], true)) {
                return;
            }

            $message = preg_replace('/\x1B(?:\[[0-?]*[ -\/]*[@-~]|\][^\x07]*(?:\x07|\x1B\\\\))/', '', $message) ?? '';

            if ($this->prompt !== null && $message === $this->lastPlainFrame) {
                return;
            }

            $this->lastPlainFrame = $message;
        }

        $this->target->write($message, $newline, OutputInterface::OUTPUT_RAW);
    }
}
