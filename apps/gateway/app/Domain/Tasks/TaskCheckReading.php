<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * What one read of a check process found: it runs, it finished with a result, or it is gone without one.
 */
final readonly class TaskCheckReading
{
    /**
     * @param  list<string>  $changedPaths
     * @param  array<array-key, mixed>|null  $deliverables  the raw deliverable evidence of a passing check
     */
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
        public ?array $deliverables = null,
        /** @var array<string, mixed> */
        public array $execution = [],
    ) {}

    public static function running(): self
    {
        return new self('running');
    }

    /**
     * @param  list<string>  $changedPaths
     * @param  float|null  $finishedAt  the Unix time at which the check itself ended
     * @param  string|null  $treeBefore  the tree the check itself saw, after any setup steps
     * @param  string|null  $failedStep  the setup step that failed, so the task check did not run
     * @param  array<array-key, mixed>|null  $deliverables  the evidence for the subtask's deliverables (ADR 0133)
     * @param  array<string, mixed>  $execution  the check process's managed user, uid, and TMPDIR
     */
    public static function finished(int $exitCode, string $headAfter, string $treeAfter, array $changedPaths, string $output, ?float $finishedAt = null, ?string $treeBefore = null, ?string $failedStep = null, ?array $deliverables = null, array $execution = []): self
    {
        return new self('finished', $exitCode, $headAfter, $treeAfter, $changedPaths, $output, $finishedAt, $treeBefore, $failedStep, $deliverables, $execution);
    }

    /** @param array<string, mixed> $execution */
    public static function lost(string $output, array $execution = []): self
    {
        return new self('lost', output: $output, execution: $execution);
    }
}
