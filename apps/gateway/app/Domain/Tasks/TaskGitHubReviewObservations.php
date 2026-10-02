<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubReviewState;
use App\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Local evidence only: never changes task lifecycle or internal approval receipts. */
final readonly class TaskGitHubReviewObservations
{
    /** Reserve before I/O. A newer attempt fences every older response, including failures. */
    public function begin(Task $group): int
    {
        return DB::transaction(function () use ($group): int {
            $row = DB::table('tasks')->where('id', $group->id)->lockForUpdate()->first();
            if ($row === null || $row->parent_id !== null) {
                throw new LogicException('Approval observations require a stored task group.');
            }
            DB::table('task_github_review_scans')->insertOrIgnore([
                'group_id' => $group->id, 'sequence' => 0, 'snapshot' => self::encode([]),
            ]);
            $scan = DB::table('task_github_review_scans')->where('group_id', $group->id)->first();
            $sequence = (int) ($scan->sequence ?? 0) + 1;
            $snapshot = self::decode((string) ($scan->snapshot ?? '{}'));
            $snapshot['previous_read_status'] = $snapshot['read_status'] ?? 'unreadable';
            $snapshot['previous_reason'] = $snapshot['reason'] ?? 'not_observed';
            $snapshot['last_attempt_at'] = now()->toISOString();
            $snapshot['read_status'] = 'unreadable';
            $snapshot['reason'] = 'scan_in_progress';
            DB::table('task_github_review_scans')->where('group_id', $group->id)->update([
                'sequence' => $sequence, 'snapshot' => self::encode($snapshot),
            ]);

            return $sequence;
        });
    }

    /** A cache hit ends the attempt, but cannot refresh or confirm any approval evidence. */
    public function finishCached(Task $group, int $sequence): bool
    {
        return DB::transaction(function () use ($group, $sequence): bool {
            DB::table('tasks')->where('id', $group->id)->lockForUpdate()->first();
            $scan = DB::table('task_github_review_scans')->where('group_id', $group->id)->first();
            if ($scan === null || (int) $scan->sequence !== $sequence) {
                return false;
            }
            $snapshot = self::decode((string) $scan->snapshot);
            if (($snapshot['completed_sequence'] ?? null) === $sequence) {
                return false;
            }
            $snapshot['completed_sequence'] = $sequence;
            $snapshot['read_status'] = $snapshot['previous_read_status'] ?? 'unreadable';
            $snapshot['reason'] = $snapshot['previous_reason'] ?? 'not_observed';
            unset($snapshot['previous_read_status'], $snapshot['previous_reason']);
            DB::table('task_github_review_scans')->where('group_id', $group->id)->update(['snapshot' => self::encode($snapshot)]);

            return true;
        });
    }

    /** Commit scan/status/source changes together, without holding locks during GitHub reads. */
    public function finish(Task $group, int $sequence, TaskReviewObservation $observation): bool
    {
        return DB::transaction(function () use ($group, $sequence, $observation): bool {
            DB::table('tasks')->where('id', $group->id)->lockForUpdate()->first();
            $scan = DB::table('task_github_review_scans')->where('group_id', $group->id)->first();
            if ($scan === null || (int) $scan->sequence !== $sequence) {
                return false;
            }
            $snapshot = self::decode((string) $scan->snapshot);
            if (($snapshot['completed_sequence'] ?? null) === $sequence) {
                return false;
            }
            $snapshot['completed_sequence'] = $sequence;
            $snapshot['applied_sequence'] = $sequence;
            unset($snapshot['previous_read_status'], $snapshot['previous_reason']);
            $complete = $observation->status === TaskReviewReadStatus::Complete;
            $repository = $observation->repository === null ? null : strtolower($observation->repository->owner.'/'.$observation->repository->name);
            $checkedAt = now()->toISOString();
            $snapshot = array_replace($snapshot, [
                'repository' => $repository, 'pull_request_number' => $observation->number,
                'read_status' => match ($observation->status) {
                    TaskReviewReadStatus::Complete => 'complete',
                    TaskReviewReadStatus::Disabled => 'disabled',
                    default => 'unreadable',
                },
                'reason' => $observation->status->value,
                'checked_at' => $checkedAt,
                'trust_revision' => $observation->trust?->revision,
            ]);
            if ($observation->pullRequest !== null) {
                $snapshot['head'] = $observation->pullRequest->headSha;
                $snapshot['pull_request_state'] = $observation->pullRequest->state->value;
            }
            if ($complete) {
                $snapshot['last_successful_scan_at'] = $checkedAt;
                foreach ($observation->reviews as $review) {
                    if ($review->state !== GitHubReviewState::Approved || $review->submittedAt === null
                        || ! in_array($review->reviewerId, $observation->trust->accountIds ?? [], true)) {
                        continue;
                    }
                    DB::table('task_github_review_observations')->insertOrIgnore([
                        'group_id' => $group->id, 'repository' => $repository,
                        'pull_request_number' => $observation->number,
                        'reviewer_id' => $review->reviewerId, 'review_id' => $review->id,
                        'source' => self::encode([
                            'reviewer_id' => $review->reviewerId, 'reviewer_login' => $review->reviewerLogin,
                            'review_id' => $review->id, 'review_url' => $review->url,
                            'commit_id' => $review->commitId, 'submitted_at' => $review->submittedAt->format('Y-m-d\TH:i:s.uP'),
                            'first_observed_at' => $checkedAt,
                        ]),
                        'latest' => self::encode([]),
                    ]);
                }
            }
            $reviews = [];
            foreach ($observation->reviews as $review) {
                $reviews[$review->id] = $review;
            }
            $selected = [];
            foreach ($observation->selection->effective ?? [] as $review) {
                $selected[$review->reviewerId] = $review->id;
            }
            foreach (DB::table('task_github_review_observations')->where('group_id', $group->id)->get() as $row) {
                $source = self::decode((string) $row->source);
                $latest = self::decode((string) $row->latest);
                $sameTarget = $repository === $row->repository && $observation->number === (int) $row->pull_request_number;
                $review = $sameTarget ? ($reviews[(int) $row->review_id] ?? null) : null;
                $trusted = $sameTarget && $observation->trust?->valid === true
                    && in_array((int) $row->reviewer_id, $observation->trust->accountIds, true);
                $reasons = [];
                if ($repository !== null && ! $sameTarget) {
                    $reasons[] = 'target_changed';
                }
                if ($observation->trust !== null && ! $trusted) {
                    $reasons[] = 'trust_removed';
                }
                if ($sameTarget && $observation->pullRequest !== null) {
                    if ($observation->pullRequest->state !== GitHubPullRequestState::Open) {
                        $reasons[] = 'pull_request_closed';
                    }
                    if ($observation->pullRequest->headSha !== null && $source['commit_id'] !== $observation->pullRequest->headSha) {
                        $reasons[] = 'stale_head';
                    }
                }
                if ($complete && $sameTarget) {
                    if ($review === null) {
                        $reasons[] = 'review_missing';
                    } elseif ($review->state === GitHubReviewState::Dismissed) {
                        $reasons[] = 'dismissed';
                    } elseif ($review->state !== GitHubReviewState::Approved) {
                        $reasons[] = 'review_state_changed';
                    }
                    if ($trusted && ($selected[(int) $row->reviewer_id] ?? null) !== (int) $row->review_id) {
                        $reasons[] = 'superseded';
                    }
                    $latest['reviewer_login'] = $review?->reviewerLogin;
                    $latest['review_state'] = $review?->state->value;
                    $latest['selected_review_id'] = $selected[(int) $row->reviewer_id] ?? null;
                    $latest['last_successful_check_at'] = $checkedAt;
                }
                $latest['trusted'] = $observation->trust === null ? null : $trusted;
                $latest['head'] = $observation->pullRequest?->headSha;
                $latest['pull_request_state'] = $observation->pullRequest?->state->value;
                $latest['checked_at'] = $checkedAt;
                if ($complete || $reasons !== []) {
                    $latest['confirmed_status'] = $reasons === [] ? 'current' : 'historical';
                    $latest['confirmed_reasons'] = $reasons;
                    $latest['confirmed_sequence'] = $sequence;
                }
                DB::table('task_github_review_observations')->where('id', $row->id)->update(['latest' => self::encode($latest)]);
            }
            DB::table('task_github_review_scans')->where('group_id', $group->id)->update(['snapshot' => self::encode($snapshot)]);

            return true;
        });
    }

    /** @return array<string, mixed> */
    public function report(Task $group): array
    {
        return DB::transaction(function () use ($group): array {
            $scan = DB::table('task_github_review_scans')->where('group_id', $group->id)->first();
            $snapshot = array_replace([
                'repository' => null, 'pull_request_number' => null,
                'read_status' => 'unreadable', 'reason' => 'not_observed',
                'head' => null, 'pull_request_state' => null, 'trust_revision' => null,
                'last_attempt_at' => null, 'checked_at' => null, 'last_successful_scan_at' => null,
            ], $scan === null ? [] : self::decode((string) $scan->snapshot));
            $success = $snapshot['last_successful_scan_at'] ?? null;
            $age = is_string($success) ? max(0, Carbon::parse($success)->diffInSeconds(now(), false)) : null;
            $snapshot['sequence'] = $scan === null ? null : (int) $scan->sequence;
            // Display whole seconds, but compare the unrounded age for freshness.
            $snapshot['age_seconds'] = $age === null ? null : (int) $age;
            $snapshot['freshness'] = $age !== null && $age <= 60 && $snapshot['read_status'] === 'complete' ? 'verified' : 'unverified';
            $records = [];
            foreach (DB::table('task_github_review_observations')->where('group_id', $group->id)
                ->orderBy('reviewer_id')->orderBy('review_id')->orderBy('repository')->orderBy('pull_request_number')->get() as $row) {
                $latest = self::decode((string) $row->latest);
                $confirmedOnAttempt = ($latest['confirmed_sequence'] ?? null) === ($snapshot['applied_sequence'] ?? null)
                    && $snapshot['reason'] !== 'scan_in_progress';
                $checked = $latest['checked_at'] ?? null;
                $recent = is_string($checked) && Carbon::parse($checked)->diffInSeconds(now(), false) <= 60;
                $verified = $confirmedOnAttempt && $recent;
                $records[] = [
                    'repository' => $row->repository, 'pull_request_number' => (int) $row->pull_request_number,
                    'source' => self::decode((string) $row->source), 'latest' => $latest,
                    'confirmed_status' => $latest['confirmed_status'] ?? null,
                    'confirmed_reasons' => $latest['confirmed_reasons'] ?? [],
                    'reported_status' => $verified ? ($latest['confirmed_status'] ?? 'unverified') : 'unverified',
                    'reported_reasons' => $verified ? ($latest['confirmed_reasons'] ?? []) : [! $recent ? 'scan_expired' : $snapshot['reason']],
                ];
            }

            return ['group_id' => $group->id, 'scan' => $snapshot, 'empty' => $records === [], 'records' => $records];
        });
    }

    /** @param array<string, mixed> $value */
    private static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function decode(string $value): array
    {
        $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new LogicException('Invalid stored approval evidence.');
        }
        $result = [];
        foreach ($decoded as $key => $item) {
            if (! is_string($key)) {
                throw new LogicException('Invalid stored approval evidence key.');
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
