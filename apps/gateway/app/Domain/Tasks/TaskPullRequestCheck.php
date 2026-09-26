<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/** One failed check run on an open settling pull request (ADR 0140, ADR 0164). */
final readonly class TaskPullRequestCheck
{
    public function __construct(
        public string $name,
        public ?string $url,
    ) {}
}
