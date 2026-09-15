<?php

declare(strict_types=1);

namespace App\Support\Console;

use Closure;
use Laravel\Prompts\Terminal;

final class PromptTerminal extends Terminal
{
    /** @param Closure(): void $restoreCursorState */
    public function __construct(private readonly Terminal $delegate, private readonly Closure $restoreCursorState)
    {
        parent::__construct();
    }

    public function read(): string
    {
        return $this->delegate->read();
    }

    public function assertInputAvailable(): void
    {
        if ($this->delegate instanceof InputTerminal) {
            $this->delegate->assertInputAvailable();
        }
    }

    public function setTty(string $mode): void
    {
        $this->delegate->setTty($mode);
    }

    public function restoreTty(): void
    {
        // Prompt destructors cannot end a caller-owned terminal scope or mask
        // the prompt's failure. PromptContext performs the explicit close.
        ($this->restoreCursorState)();
    }

    public function close(): void
    {
        $this->delegate->restoreTty();
    }

    public function cols(): int
    {
        return $this->delegate->cols();
    }

    public function lines(): int
    {
        return $this->delegate->lines();
    }

    public function initDimensions(): void
    {
        $this->delegate->initDimensions();
    }

    public function supportsTrueColor(): bool
    {
        return $this->delegate->supportsTrueColor();
    }

    /** @return array{int, int, int} */
    public function foregroundColor(): array
    {
        return $this->delegate->foregroundColor();
    }

    /** @return array{int, int, int} */
    public function backgroundColor(): array
    {
        return $this->delegate->backgroundColor();
    }
}
