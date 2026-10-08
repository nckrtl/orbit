<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

/** The review-and-merge state of a task: its pull request branch, merge gate result, and fully reviewed commits. */
final readonly class TaskReviewAndMergeResponse
{
    /**
     * @param  list<array{sha: string, source: string, review_task_id: int|null, pushed_at: string|null, github_review_id: int|null, recorded_at: string}>  $reviewedCommits
     */
    public function __construct(
        public bool $enabled,
        public ?string $prBranch,
        public ?string $mergeStatus,
        public ?string $mergeReason,
        public ?string $mergedSha,
        public array $reviewedCommits,
    ) {}

    public static function fromGatewayData(mixed $value, string $requestId): ?self
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value) || ! is_bool($value['enabled'] ?? null) || ! is_array($value['reviewed_commits'] ?? null) || ! array_is_list($value['reviewed_commits'])) {
            throw TaskFields::invalid('task review and merge', $requestId);
        }
        $data = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $data[$key] = $item;
            }
        }
        $commits = [];
        foreach ($value['reviewed_commits'] as $commit) {
            if (! is_array($commit) || ! is_string($commit['sha'] ?? null) || ! is_string($commit['source'] ?? null) || ! is_string($commit['recorded_at'] ?? null)) {
                throw TaskFields::invalid('task review and merge', $requestId);
            }
            $commits[] = [
                'sha' => $commit['sha'],
                'source' => $commit['source'],
                'review_task_id' => is_int($commit['review_task_id'] ?? null) ? $commit['review_task_id'] : null,
                'pushed_at' => is_string($commit['pushed_at'] ?? null) ? $commit['pushed_at'] : null,
                'github_review_id' => is_int($commit['github_review_id'] ?? null) ? $commit['github_review_id'] : null,
                'recorded_at' => $commit['recorded_at'],
            ];
        }

        return new self(
            enabled: $value['enabled'],
            prBranch: TaskFields::nullableText($data, 'pr_branch'),
            mergeStatus: TaskFields::nullableText($data, 'merge_status'),
            mergeReason: TaskFields::nullableText($data, 'merge_reason'),
            mergedSha: TaskFields::nullableText($data, 'merged_sha'),
            reviewedCommits: $commits,
        );
    }

    /**
     * @return array{enabled: bool, pr_branch: string|null, merge_status: string|null, merge_reason: string|null, merged_sha: string|null,
     *     reviewed_commits: list<array{sha: string, source: string, review_task_id: int|null, pushed_at: string|null, github_review_id: int|null, recorded_at: string}>}
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'pr_branch' => $this->prBranch,
            'merge_status' => $this->mergeStatus,
            'merge_reason' => $this->mergeReason,
            'merged_sha' => $this->mergedSha,
            'reviewed_commits' => $this->reviewedCommits,
        ];
    }
}
