<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\JevDecision;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Ai\Contracts\Question;
use Throwable;

final readonly class JevRecorder
{
    private const int SnapshotLimit = 65536;

    private const string TruncationMetadataKey = '__orbit_truncated__';

    private const int ReportDecaySeconds = 60;

    /**
     * @param  array<string, mixed>  $subject
     * @param  array<string, Question>  $questions
     * @param  string|array<string, mixed>  $state
     * @param  array<string, array<string, mixed>>|null  $answers
     */
    public function record(
        string $purpose,
        array $subject,
        array $questions,
        string|array $state,
        ?array $answers,
        ?string $model,
        ?string $errorCode,
        int $started,
        string $callStartedAt,
    ): void {
        try {
            $questionData = [];
            foreach ($questions as $key => $question) {
                $questionData[$key] = $question->toArray();
            }
            $approvalChanges = $subject['approval_changes'] ?? null;
            $approvalSnapshot = is_array($approvalChanges) ? self::sanitizedLines($approvalChanges) : null;
            if ($answers !== null) {
                json_encode($answers, JSON_THROW_ON_ERROR);
            }

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
                'questions' => self::capped(self::redact($questionData)),
                'input_state' => self::capped(self::redact($state)),
                'answers' => $answers,
                'provider_model' => $model,
                'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'error_code' => $errorCode,
            ]);
        } catch (Throwable $exception) {
            self::reportFailure($exception);
        }
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

    private static function capped(mixed $value): mixed
    {
        if (self::encodedSize($value) <= self::SnapshotLimit) {
            return $value;
        }

        for ($stringLimit = 16384; $stringLimit > 0; $stringLimit = intdiv($stringLimit, 2)) {
            $capped = self::markTruncated(self::truncateStrings($value, $stringLimit));
            if (self::encodedSize($capped) <= self::SnapshotLimit) {
                return $capped;
            }
        }

        // Pathological key/container overhead cannot be reduced by shortening string values.
        // Keep an explicit, bounded marker rather than persisting an oversized snapshot.
        return [self::TruncationMetadataKey => true];
    }

    private static function encodedSize(mixed $value): int
    {
        return strlen(json_encode($value, JSON_THROW_ON_ERROR));
    }

    /** @return array<string|int, mixed> */
    private static function markTruncated(mixed $value): array
    {
        if (is_array($value)) {
            $value[self::TruncationMetadataKey] = true;

            return $value;
        }

        return ['value' => $value, self::TruncationMetadataKey => true];
    }

    private static function truncateStrings(mixed $value, int $limit): mixed
    {
        if (is_string($value) && strlen($value) > $limit) {
            return mb_strcut($value, 0, max(0, $limit - 14), 'UTF-8').'[truncated]';
        }
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::truncateStrings($item, $limit);
        }

        return $value;
    }

    private static function reportFailure(Throwable $exception): void
    {
        try {
            RateLimiter::attempt(
                'jev-recorder-failure:'.$exception::class,
                1,
                static fn (): mixed => self::report($exception),
                self::ReportDecaySeconds,
            );
        } catch (Throwable) {
            // Bookkeeping must never change Jev's result or stop a scheduler tick.
        }
    }

    private static function report(Throwable $exception): mixed
    {
        try {
            report($exception);
        } catch (Throwable) {
            // Reporting is best effort because the reporter itself may be unavailable.
        }

        return null;
    }

    private static function redact(mixed $value, ?string $key = null): mixed
    {
        return (new CommandActivityInputSanitizer)->sanitize($value, $key);
    }
}
