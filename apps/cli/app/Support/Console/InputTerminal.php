<?php

declare(strict_types=1);

namespace App\Support\Console;

use Laravel\Prompts\Key;
use Laravel\Prompts\Terminal;
use Throwable;

class InputTerminal extends Terminal
{
    private ?PromptAborted $failure = null;

    /** @var resource */
    private readonly mixed $input;

    /** @param resource|null $input */
    public function __construct(mixed $input = null, private readonly ?int $columns = null)
    {
        parent::__construct();
        $this->input = $input ?? STDIN;
    }

    public function assertInputAvailable(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        if (! is_resource($this->input)) {
            throw new PromptAborted('Unable to read input.', 'read_failed');
        }

        if (feof($this->input)) {
            throw new PromptAborted('Input ended before submission.', 'eof');
        }
    }

    #[\Override]
    public function read(): string
    {
        $this->assertInputAvailable();

        try {
            $input = @fread($this->input, 1024);
        } catch (Throwable $exception) {
            throw new PromptAborted('Unable to read input.', 'read_failed', $exception);
        }

        if ($input === false) {
            throw new PromptAborted('Unable to read input.', 'read_failed');
        }

        if (str_contains($input, Key::CTRL_C) || str_contains($input, Key::CTRL_D)) {
            throw new PromptAborted;
        }

        if ($input === '') {
            $this->assertInputAvailable();
            usleep(10_000);
        }

        return $input;
    }

    #[\Override]
    public function setTty(string $mode): void
    {
        try {
            parent::setTty($mode);
        } catch (Throwable $exception) {
            $this->failure = new PromptAborted('Unable to prepare terminal input.', 'terminal_failed', $exception);
        }
    }

    #[\Override]
    public function cols(): int
    {
        return $this->columns ?? parent::cols();
    }
}
