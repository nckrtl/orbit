<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * What one read of a check process found: it runs, it finished with a result, or it is gone without one.
 */
final readonly class TaskCheckReading
{
    /** @param list<string> $changedPaths */
    private function __construct(
        public string $state,
        public ?int $exitCode = null,
        public ?string $headAfter = null,
        public ?string $treeAfter = null,
        public array $changedPaths = [],
        public string $output = '',
        public ?float $finishedAt = null,
        public ?string $treeBefore = null,
        public ?string $failedStep = null,
    ) {}

    public static function running(): self
    {
        return new self('running');
    }

    /**
     * @param  list<string>  $changedPaths
     * @param  float|null  $finishedAt  the Unix time at which the check itself ended
     * @param  string|null  $treeBefore  the tree the check itself saw, after any setup steps
     * @param  string|null  $failedStep  the setup step that failed, so composer check did not run
     */
    public static function finished(int $exitCode, string $headAfter, string $treeAfter, array $changedPaths, string $output, ?float $finishedAt = null, ?string $treeBefore = null, ?string $failedStep = null): self
    {
        return new self('finished', $exitCode, $headAfter, $treeAfter, $changedPaths, $output, $finishedAt, $treeBefore, $failedStep);
    }

    public static function lost(string $output): self
    {
        return new self('lost', output: $output);
    }
}
