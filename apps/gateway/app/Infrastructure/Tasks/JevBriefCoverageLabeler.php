<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Tasks\BriefCoverageLabeler;
use App\Domain\Tasks\TaskPullRequestHealth;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\JevDecision;
use App\Models\TaskGroup;
use Illuminate\Support\Facades\DB;

final readonly class JevBriefCoverageLabeler implements BriefCoverageLabeler
{
    private const int SnapshotLimit = 65536;

    /** Label decisions only when the scheduler observes a merge with verifiable GitHub evidence. */
    public function label(TaskGroup $group, TaskPullRequestHealth $merge): void
    {
        if ($merge->state !== 'merged') {
            return;
        }
        $titles = $group->tasks->pluck('title')->all();
        $mergeLines = $merge->mergeBody === null ? null : self::changesSection($merge->mergeBody);
        $sanitizedMergeLines = is_array($mergeLines) ? self::sanitizedLines($mergeLines) : null;
        $storedMergeLines = is_array($sanitizedMergeLines) ? self::cappedLines($sanitizedMergeLines) : null;
        $bodyDigest = $merge->mergeBody === null ? null : hash('sha256', $merge->mergeBody);
        JevDecision::query()->where('task_group_id', $group->id)->where('purpose', 'brief_coverage')->get()
            ->each(static function (JevDecision $candidate) use ($group, $merge, $titles, $mergeLines, $storedMergeLines, $bodyDigest): void {
                DB::transaction(static function () use ($candidate, $group, $merge, $titles, $mergeLines, $storedMergeLines, $bodyDigest): void {
                    $decision = JevDecision::query()->lockForUpdate()->find($candidate->id);
                    if (! $decision instanceof JevDecision) {
                        return;
                    }
                    $storedPullRequestNumber = $decision->getAttribute('merged_pull_request_number');
                    $storedMergeSha = $decision->getAttribute('merge_commit_sha');
                    if (($storedPullRequestNumber !== null && $merge->pullRequestNumber !== null && $storedPullRequestNumber !== $merge->pullRequestNumber)
                        || ($storedMergeSha !== null && $merge->mergeSha !== null && $storedMergeSha !== $merge->mergeSha)) {
                        return;
                    }

                    $evidenceChanged = false;
                    $updates = [];
                    foreach ([
                        'merged_pull_request_number' => $merge->pullRequestNumber,
                        'merge_commit_sha' => $merge->mergeSha,
                        'merged_at' => $merge->mergedAt,
                    ] as $field => $incoming) {
                        if ($decision->getAttribute($field) === null && $incoming !== null) {
                            $updates[$field] = $incoming;
                            $evidenceChanged = true;
                        }
                    }
                    if ($decision->getAttribute('merge_body_digest') === null && $bodyDigest !== null) {
                        $updates['merge_body_digest'] = $bodyDigest;
                        $updates['merge_changes'] = $storedMergeLines;
                        $updates['merge_changes_digest'] = $storedMergeLines === null ? null : self::snapshotDigest($storedMergeLines);
                        $updates['merge_changes_redacted'] = $storedMergeLines === null ? null : $storedMergeLines !== $mergeLines;
                        $evidenceChanged = true;
                    }
                    if (! $evidenceChanged) {
                        return;
                    }
                    $decision->forceFill($updates);
                    $mergeLines = $decision->getAttribute('merge_changes');
                    $bodyDigest = $decision->getAttribute('merge_body_digest');
                    $mergeChangesDigest = $decision->getAttribute('merge_changes_digest');
                    $mergeChangesRedacted = $decision->getAttribute('merge_changes_redacted') === true;
                    $pullRequestNumber = $decision->getAttribute('merged_pull_request_number');
                    $mergeSha = $decision->getAttribute('merge_commit_sha');
                    $mergedAt = $decision->getAttribute('merged_at');

                    $answers = $decision->getAttribute('answers');
                    $state = $decision->getAttribute('input_state');
                    $taskIds = $decision->getAttribute('task_ids');
                    if (! is_array($answers) || ! is_array($state) || ! is_array($taskIds) || ! array_is_list($taskIds) || $taskIds === []
                        || array_filter($taskIds, static fn (mixed $id): bool => is_int($id) || is_string($id)) !== $taskIds) {
                        $decision->forceFill(['labels' => null])->save();

                        return;
                    }
                    /** @var list<int|string> $taskIds */
                    $labels = [];
                    $questionLabels = [];
                    $expectedAnswerKeys = array_map(static fn (int|string $taskId): string => 'subtask_'.$taskId, $taskIds);
                    $allCovered = count($answers) === count($taskIds)
                        && array_diff($expectedAnswerKeys, array_keys($answers)) === []
                        && array_diff(array_keys($answers), $expectedAnswerKeys) === [];
                    foreach ($answers as $key => $answer) {
                        if (! is_string($key) || ! is_array($answer) || ! is_bool($answer['value'] ?? null)) {
                            $allCovered = false;

                            continue;
                        }
                        $allCovered = $allCovered && $answer['value'];
                    }
                    $mergeMetadataKnown = is_int($pullRequestNumber) && is_string($mergeSha) && is_string($mergedAt);
                    if ($mergeMetadataKnown && $allCovered) {
                        $labels['call'] = [
                            'label' => 'correct',
                            'source' => ['rule' => 'brief_coverage_call_v1', 'pull_request_number' => $pullRequestNumber, 'merge_sha' => $mergeSha, 'merged_at' => $mergedAt],
                        ];
                    }

                    $approvalLines = $decision->getAttribute('approval_changes');
                    $approvalRedacted = $decision->getAttribute('approval_changes_redacted') === true;
                    $subtasks = $state['subtasks'] ?? null;
                    $approvalCommentId = $decision->getAttribute('approval_comment_id');
                    $approvalDigest = $decision->getAttribute('approval_changes_digest');
                    if (! is_array($approvalLines) || ! array_is_list($approvalLines) || array_filter($approvalLines, is_string(...)) !== $approvalLines
                        || ! is_array($subtasks) || ! is_int($approvalCommentId) || ! is_string($approvalDigest) || $approvalRedacted
                        || ! is_array($mergeLines) || ! array_is_list($mergeLines) || array_filter($mergeLines, is_string(...)) !== $mergeLines
                        || $mergeChangesRedacted || ! is_string($mergeChangesDigest)
                        || ! is_string($bodyDigest) || ! $mergeMetadataKnown || ! self::digest($approvalLines, $approvalDigest)) {
                        $decision->forceFill(['labels' => $labels === [] ? null : $labels])->save();

                        return;
                    }

                    foreach ($answers as $key => $answer) {
                        if (! is_string($key) || ! preg_match('/^subtask_(\d+)$/', $key, $matches) || ! is_array($answer) || ! is_bool($answer['value'] ?? null)) {
                            continue;
                        }
                        $taskId = (int) $matches[1];
                        $taskIndex = array_search($taskId, $taskIds, true);
                        if (! is_int($taskIndex) || ! in_array($taskId, $group->tasks->modelKeys(), true) || ! is_array($subtasks[$taskIndex] ?? null)) {
                            continue;
                        }
                        $title = $subtasks[$taskIndex]['title'] ?? null;
                        $normalizedTitle = is_string($title) ? self::normalize($title) : null;
                        if ($normalizedTitle === null || count(array_filter($titles, static fn (mixed $other): bool => self::normalize(is_string($other) ? $other : '') === $normalizedTitle)) !== 1) {
                            continue;
                        }
                        $approved = self::matchingLines($approvalLines, $normalizedTitle);
                        $merged = self::matchingLines($mergeLines, $normalizedTitle);
                        $approvedLines = array_values(array_filter($approved, static fn (string $line): bool => in_array(self::normalize($line), array_map(self::normalize(...), $mergeLines), true)));
                        if (count($approvedLines) > 1 || count($merged) > 1) {
                            continue;
                        }
                        $hasNamedApprovedLine = count($approvedLines) === 1 && count($merged) === 1;
                        if (! $hasNamedApprovedLine || $answer['value']) {
                            continue;
                        }
                        $label = 'false_negative';
                        $questionLabels[$key] = [
                            'label' => $label,
                            'source' => [
                                'rule' => 'brief_coverage_v1',
                                'question_id' => $key,
                                'task_id' => $taskId,
                                'approval_comment_id' => $approvalCommentId,
                                'approval_changes_digest' => $approvalDigest,
                                'pull_request_number' => $pullRequestNumber,
                                'merge_sha' => $mergeSha,
                                'merged_at' => $mergedAt,
                                'merge_body_digest' => $bodyDigest,
                                'merge_changes_digest' => $mergeChangesDigest,
                                'matching_change_line' => $approvedLines[0],
                            ],
                        ];
                    }
                    if ($questionLabels !== []) {
                        $labels['questions'] = $questionLabels;
                    }
                    $decision->forceFill(['labels' => $labels !== [] ? $labels : null])->save();
                });
            });
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function matchingLines(array $lines, string $normalizedTitle): array
    {
        $matching = [];
        foreach ($lines as $line) {
            $normalizedLine = self::normalize($line);
            if ($normalizedLine !== null && str_contains(' '.$normalizedLine.' ', ' '.$normalizedTitle.' ')) {
                $matching[] = $line;
            }
        }

        return $matching;
    }

    private static function normalize(string $value): ?string
    {
        $normalized = \Normalizer::normalize($value, \Normalizer::FORM_KC);
        if (! is_string($normalized)) {
            return null;
        }
        $normalized = mb_convert_case($normalized, MB_CASE_FOLD, 'UTF-8');
        $normalized = preg_replace('/[\p{P}\p{S}]+/u', ' ', $normalized);
        if (! is_string($normalized)) {
            return null;
        }

        return trim((string) preg_replace('/\s+/u', ' ', $normalized));
    }

    /**
     * @param  array<mixed>  $lines
     * @return list<string>|null
     */
    private static function sanitizedLines(array $lines): ?array
    {
        if (! array_is_list($lines) || array_filter($lines, is_string(...)) !== $lines) {
            return null;
        }
        $snapshot = self::redact($lines);

        return is_array($snapshot) && array_is_list($snapshot) && array_filter($snapshot, is_string(...)) === $snapshot
            ? $snapshot
            : null;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function cappedLines(array $lines): array
    {
        $marker = '[truncated at '.self::SnapshotLimit.' bytes]';
        $capped = [];
        foreach ($lines as $line) {
            $candidate = [...$capped, $line, $marker];
            if (strlen(json_encode($candidate, JSON_THROW_ON_ERROR)) > self::SnapshotLimit) {
                break;
            }
            $capped[] = $line;
        }

        return [...$capped, ...($capped === $lines ? [] : [$marker])];
    }

    /** @param list<string> $lines */
    private static function snapshotDigest(array $lines): string
    {
        return hash('sha256', json_encode($lines, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return list<string>|null */
    private static function changesSection(string $body): ?array
    {
        if (! preg_match('/^## Changes[ \t]*\r?\n(.*?)(?=^##(?:[ \t]|#)|\z)/ms', $body, $match)) {
            return null;
        }
        $lines = [];
        foreach (preg_split('/\r?\n/', trim($match[1])) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\s*(?:[-*+] |\d+[.)] )/', '', $line));
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** @param list<string> $lines */
    private static function digest(array $lines, string $expected): bool
    {
        return hash_equals($expected, hash('sha256', json_encode($lines, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }

    private static function redact(mixed $value, ?string $key = null): mixed
    {
        return (new CommandActivityInputSanitizer)->sanitize($value, $key);
    }
}
