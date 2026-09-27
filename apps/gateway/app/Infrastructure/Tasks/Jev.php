<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\GitHub\GitHubPullRequestCommit;
use App\Domain\Tasks\TaskPullRequestHealth;
use App\Domain\Tasks\TaskSessionClassificationException;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\JevDecision;
use App\Models\TaskGroup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\PendingResponses\PendingClassification;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Throwable;

/**
 * Sends one TypeSafe Jev classification and durably records its request and outcome.
 */
final readonly class Jev
{
    /** @param array{task_group_id?: int|null, task_id?: int|null, task_ids?: array<int, int|string>|null, agent_thread_id?: string|null, approval_comment_id?: int|null, approval_changes?: list<string>} $subject */
    public static function classify(PendingClassification $classification, string $purpose = 'unspecified', array $subject = []): ClassificationResponse
    {
        $started = hrtime(true);
        $callStartedAt = now()->toIso8601String();
        $state = self::property($classification, 'state');
        $questions = self::property($classification, 'questions');
        if (! is_array($questions)) {
            $questions = [];
        }
        $questionData = [];
        foreach ($questions as $key => $question) {
            $questionData[$key] = is_object($question) && method_exists($question, 'toArray')
                ? $question->toArray()
                : ['type' => is_object($question) ? class_basename($question) : 'unknown'];
        }

        try {
            $response = $classification->classify();
        } catch (Throwable $exception) {
            self::record($purpose, $subject, $questionData, $state, null, null, self::errorCode($exception), $started, $callStartedAt);

            $key = config('ai.providers.typesafe.key');
            $reason = ! is_string($key) || trim($key) === ''
                ? 'TypeSafe Jev is not configured. Set TYPESAFE_API_KEY.'
                : 'TypeSafe Jev request failed ('.class_basename($exception).').';

            throw new TaskSessionClassificationException($reason, previous: $exception);
        }

        $answers = [];
        foreach ($response->answers as $key => $answer) {
            $value = match (true) {
                $answer instanceof BooleanAnswer => $answer->isTrue(),
                $answer instanceof ChoiceAnswer => $answer->choice,
                default => $answer->toArray()['value'] ?? null,
            };
            $probabilities = match (true) {
                $answer instanceof BooleanAnswer => ['true' => $answer->probability, 'false' => 1 - $answer->probability],
                $answer instanceof ChoiceAnswer => $answer->probabilities,
                default => $answer->toArray()['probabilities'] ?? null,
            };
            $selectedProbability = match (true) {
                $answer instanceof BooleanAnswer => $answer->probability,
                $answer instanceof ChoiceAnswer => $answer->probabilities[$answer->choice] ?? null,
                default => null,
            };
            if (! is_float($selectedProbability) || ! is_finite($selectedProbability) || $selectedProbability < 0 || $selectedProbability > 1) {
                $selectedProbability = null;
            }
            if ($answer instanceof BooleanAnswer && $value === false && $selectedProbability !== null) {
                $selectedProbability = 1 - $selectedProbability;
            }
            $answers[$key] = [
                'value' => $value,
                'probabilities' => $probabilities,
                'provider_confidence' => $answer instanceof ChoiceAnswer ? $answer->confidence : ($answer->toArray()['confidence'] ?? null),
                'selected_answer_probability' => $selectedProbability,
            ];
        }
        self::record($purpose, $subject, $questionData, $state, $answers, $response->meta->model, null, $started, $callStartedAt);

        return $response;
    }

    /** Label decisions only when the scheduler observes a merge with verifiable GitHub evidence. */
    public static function labelMergedCoverage(TaskGroup $group, TaskPullRequestHealth $merge): void
    {
        if ($merge->state !== 'merged') {
            return;
        }
        $titles = $group->tasks->pluck('title')->all();
        $mergeLines = $merge->mergeBody === null ? null : self::changesSection($merge->mergeBody);
        $storedMergeLines = is_array($mergeLines) ? self::sanitizedLines($mergeLines) : null;
        $bodyDigest = $merge->mergeBody === null ? null : hash('sha256', $merge->mergeBody);
        $commitEvidence = $merge->mergeCommits === null ? null : self::commitEvidence($merge->mergeCommits);
        $storedCommitHistory = $commitEvidence['commits'] ?? null;
        $commitHistoryComplete = $merge->mergeCommits !== null && ($commitEvidence['complete'] ?? false);

        JevDecision::query()->where('task_group_id', $group->id)->where('purpose', 'brief_coverage')->get()
            ->each(static function (JevDecision $candidate) use ($group, $merge, $titles, $mergeLines, $storedMergeLines, $bodyDigest, $storedCommitHistory, $commitHistoryComplete): void {
                DB::transaction(static function () use ($candidate, $group, $merge, $titles, $mergeLines, $storedMergeLines, $bodyDigest, $storedCommitHistory, $commitHistoryComplete): void {
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
                    if ($decision->getAttribute('merge_history_complete') !== true) {
                        if ($commitHistoryComplete) {
                            $updates['merge_commit_history'] = $storedCommitHistory;
                            $updates['merge_history_complete'] = true;
                            $evidenceChanged = true;
                        } elseif ($decision->getAttribute('merge_commit_history') === null && $storedCommitHistory !== null) {
                            $updates['merge_commit_history'] = $storedCommitHistory;
                            $updates['merge_history_complete'] = false;
                            $evidenceChanged = true;
                        } elseif ($decision->getAttribute('merge_history_complete') === null) {
                            $updates['merge_history_complete'] = false;
                            $evidenceChanged = true;
                        }
                    }
                    if (! $evidenceChanged) {
                        if ($decision->getAttribute('merge_history_complete') !== true && $decision->getAttribute('labels') !== null) {
                            $decision->forceFill(['labels' => null])->save();
                        }

                        return;
                    }
                    $decision->forceFill($updates);
                    if ($decision->getAttribute('merge_history_complete') !== true) {
                        $decision->forceFill(['labels' => null])->save();

                        return;
                    }
                    $mergeCommits = self::restoreCommitHistory($decision->getAttribute('merge_commit_history'));
                    if ($mergeCommits === null) {
                        $decision->forceFill(['merge_history_complete' => false, 'labels' => null])->save();

                        return;
                    }
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
                    $calledAt = $decision->getAttribute('call_started_at');
                    $fixes = is_string($calledAt) ? self::fixCommits($mergeCommits, $taskIds, $calledAt) : self::unknownFixTimes($mergeCommits, $taskIds);
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
                    if ($mergeMetadataKnown && $allCovered && self::completeHistoryHasNoTaskFix($mergeCommits, $taskIds)) {
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
                        if (array_key_exists($taskId, $fixes) && $fixes[$taskId] === null) {
                            continue;
                        }
                        $fixSha = $fixes[$taskId] ?? null;
                        $label = match (true) {
                            $answer['value'] && $hasNamedApprovedLine && $fixSha === null => 'correct',
                            $answer['value'] => 'false_positive',
                            $hasNamedApprovedLine => 'false_negative',
                            default => 'correct',
                        };
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
                                'matching_change_line' => $hasNamedApprovedLine ? $approvedLines[0] : null,
                                'coverage_fix_commit_sha' => $fixSha,
                            ],
                        ];
                    }
                    $labels['questions'] = $questionLabels;
                    $decision->forceFill(['labels' => $labels['questions'] !== [] || isset($labels['call']) ? $labels : null])->save();
                });
            });
    }

    /**
     * @param  list<GitHubPullRequestCommit>  $commits
     * @param  list<int|string>  $taskIds
     * @return array<int, string|null>
     */
    private static function fixCommits(array $commits, array $taskIds, string $calledAt): array
    {
        $fixes = [];
        foreach ($commits as $commit) {
            foreach (self::coverageFixTrailers($commit->message) as $taskId) {
                if (! in_array($taskId, array_map(intval(...), $taskIds), true)) {
                    continue;
                }
                if (! is_string($commit->committedAt)) {
                    $fixes[$taskId] = null;

                    continue;
                }
                try {
                    if (Carbon::parse($commit->committedAt)->greaterThan(Carbon::parse($calledAt)) && (! array_key_exists($taskId, $fixes) || $fixes[$taskId] !== null)) {
                        $fixes[$taskId] = $commit->sha;
                    }
                } catch (Throwable) {
                    $fixes[$taskId] = null;
                }
            }
        }

        return $fixes;
    }

    /**
     * @param  list<GitHubPullRequestCommit>  $commits
     * @param  list<int|string>  $taskIds
     * @return array<int, null>
     */
    private static function unknownFixTimes(array $commits, array $taskIds): array
    {
        $unknown = [];
        foreach ($commits as $commit) {
            foreach (self::coverageFixTrailers($commit->message) as $taskId) {
                if (in_array($taskId, array_map(intval(...), $taskIds), true)) {
                    $unknown[$taskId] = null;
                }
            }
        }

        return $unknown;
    }

    /**
     * @param  list<GitHubPullRequestCommit>  $commits
     * @param  list<int|string>  $taskIds
     */
    private static function completeHistoryHasNoTaskFix(array $commits, array $taskIds): bool
    {
        foreach ($commits as $commit) {
            foreach (self::coverageFixTrailers($commit->message) as $taskId) {
                if (in_array($taskId, array_map(intval(...), $taskIds), true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return list<int> */
    private static function coverageFixTrailers(string $message): array
    {
        $lines = preg_split('/\r?\n/', rtrim($message)) ?: [];
        $footer = [];
        for ($index = count($lines) - 1; $index >= 0 && trim($lines[$index]) !== ''; $index--) {
            array_unshift($footer, $lines[$index]);
        }
        $taskIds = [];
        foreach ($footer as $line) {
            if (preg_match('/^Orbit-Coverage-Fix: task-(\d+)$/D', $line, $match)) {
                $taskIds[] = (int) $match[1];
            }
        }

        return $taskIds;
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
        if (! class_exists(\Normalizer::class)) {
            return null;
        }
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

    /** @param list<string> $lines */
    private static function snapshotDigest(array $lines): string
    {
        return hash('sha256', json_encode($lines, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return list<GitHubPullRequestCommit>|null
     */
    private static function restoreCommitHistory(mixed $stored): ?array
    {
        if (! is_array($stored) || ! array_is_list($stored)) {
            return null;
        }
        $commits = [];
        foreach ($stored as $commit) {
            $committedAt = is_array($commit) ? ($commit['committed_at'] ?? null) : null;
            if (! is_array($commit) || ! is_string($commit['sha'] ?? null)
                || (! is_string($committedAt) && $committedAt !== null)
                || ! is_array($commit['trailers'] ?? null) || ! array_is_list($commit['trailers'])
                || array_filter($commit['trailers'], is_string(...)) !== $commit['trailers']) {
                return null;
            }
            $trailers = $commit['trailers'];
            $message = $trailers === [] ? '' : "Persisted commit trailers\n\n".implode("\n", $trailers);
            $commits[] = new GitHubPullRequestCommit($commit['sha'], $message, $committedAt);
        }

        return $commits;
    }

    /**
     * @param  list<GitHubPullRequestCommit>  $commits
     * @return array{commits: list<array{sha: string, committed_at: ?string, trailers: list<string>}>, complete: bool}
     */
    private static function commitEvidence(array $commits): array
    {
        $evidence = [];
        $complete = true;
        foreach ($commits as $commit) {
            $originalTrailers = self::commitTrailerLines($commit->message);
            $trailers = self::redact($originalTrailers);
            if (! is_array($trailers) || ! array_is_list($trailers) || array_filter($trailers, is_string(...)) !== $trailers) {
                $trailers = [];
                $complete = false;
            } elseif ($trailers !== $originalTrailers) {
                $complete = false;
            }
            $evidence[] = [
                'sha' => $commit->sha,
                'committed_at' => $commit->committedAt,
                'trailers' => $trailers,
            ];
        }

        return ['commits' => $evidence, 'complete' => $complete];
    }

    /** @return list<string> */
    private static function commitTrailerLines(string $message): array
    {
        $lines = preg_split('/\r?\n/', rtrim($message)) ?: [];
        $footer = [];
        for ($index = count($lines) - 1; $index >= 0 && trim($lines[$index]) !== ''; $index--) {
            array_unshift($footer, $lines[$index]);
        }

        return array_values(array_filter($footer, static fn (string $line): bool => preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*: .+$/D', $line) === 1));
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

    private static function property(PendingClassification $classification, string $name): mixed
    {
        $property = new \ReflectionProperty($classification, $name);

        return $property->getValue($classification);
    }

    /**
     * @param  array<string, mixed>  $subject
     * @param  array<string, array<string, mixed>>  $questions
     * @param  array<string, array<string, mixed>>|null  $answers
     */
    private static function record(string $purpose, array $subject, array $questions, mixed $state, ?array $answers, ?string $model, ?string $errorCode, int $started, string $callStartedAt): void
    {
        $approvalChanges = $subject['approval_changes'] ?? null;
        $approvalSnapshot = is_array($approvalChanges) ? self::sanitizedLines($approvalChanges) : null;

        JevDecision::query()->create([
            'purpose' => $purpose,
            'call_started_at' => $callStartedAt,
            'task_group_id' => $subject['task_group_id'] ?? null,
            'task_id' => $subject['task_id'] ?? null,
            'task_ids' => $subject['task_ids'] ?? null,
            'approval_comment_id' => $subject['approval_comment_id'] ?? null,
            'approval_changes' => $approvalSnapshot,
            'approval_changes_digest' => $approvalSnapshot === null ? null : self::snapshotDigest($approvalSnapshot),
            'approval_changes_redacted' => $approvalSnapshot !== null && $approvalSnapshot !== $approvalChanges,
            'agent_thread_id' => $subject['agent_thread_id'] ?? null,
            'questions' => self::redact($questions),
            'input_state' => self::redact($state),
            'answers' => $answers,
            'provider_model' => $model,
            'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'error_code' => $errorCode,
        ]);
    }

    private static function redact(mixed $value, ?string $key = null): mixed
    {
        return (new CommandActivityInputSanitizer)->sanitize($value, $key);
    }

    private static function errorCode(Throwable $exception): string
    {
        $class = class_basename($exception);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $class));
    }
}
