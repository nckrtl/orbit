<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/** A remotely fenced probe key, with its accepted identity or proof that no start was accepted. */
final readonly class TaskProbeRetirement
{
    /** @param array<string, mixed> $execution */
    public function __construct(public ?TaskCheckProcess $process, public array $execution = []) {}
}
