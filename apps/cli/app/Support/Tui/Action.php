<?php

declare(strict_types=1);

namespace App\Support\Tui;

/** One entry in the record actions menu: what it does, and how it does it. */
final readonly class Action
{
    private function __construct(
        public bool $real,
        public bool $destructive,
        public string $description,
        public ?string $command,
        public ?string $hint,
    ) {}

    /** Runs a real SDK request immediately when chosen. $description is the menu's confirm line. */
    public static function real(string $description): self
    {
        return new self(true, false, $description, null, null);
    }

    /** Runs a real SDK request, but only after an inline "Confirm? y/N" (see Input::confirmAndRun()). */
    public static function destructive(string $description): self
    {
        return new self(true, true, $description, null, null);
    }

    /** No SDK request exists yet; choosing it leaves the TUI and prints $command to run instead. */
    public static function leaves(string $command, string $hint): self
    {
        return new self(false, false, $command, $command, $hint);
    }
}
