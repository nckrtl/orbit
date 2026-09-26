<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/** One failed check run on an open settling pull request (ADR 0140, ADR 0164). */
final readonly class TaskPullRequestCheck
{
    /**
     * Aggregate checks that only report whether other checks passed. One of them is ignored while
     * another failed check explains the failure, so one real failure is one problem (ADR 0164).
     */
    public const array ROLLUP_NAMES = ['Required checks'];

    public function __construct(
        public string $name,
        public ?string $url,
    ) {}

    public function rollup(): bool
    {
        return in_array($this->name, self::ROLLUP_NAMES, true);
    }
}
