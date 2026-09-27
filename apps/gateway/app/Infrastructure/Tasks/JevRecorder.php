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
        $encoded = json_encode($value, JSON_THROW_ON_ERROR);
        if (strlen($encoded) <= self::SnapshotLimit) {
            return $value;
        }

        $marker = '[truncated at '.self::SnapshotLimit.' bytes]';
        if (! is_array($value) || ! array_is_list($value)) {
            return ['__truncated__' => $marker];
        }

        $capped = [];
        foreach ($value as $item) {
            $candidate = [...$capped, $item, $marker];
            if (strlen(json_encode($candidate, JSON_THROW_ON_ERROR)) > self::SnapshotLimit) {
                break;
            }
            $capped[] = $item;
        }

        return [...$capped, $marker];
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
