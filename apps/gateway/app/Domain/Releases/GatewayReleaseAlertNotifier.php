<?php

declare(strict_types=1);

namespace App\Domain\Releases;

use App\Domain\Logs\LogRedactor;
use App\Domain\Problems\ProblemCollector;
use App\Domain\Tasks\TaskExtensionState;
use App\Models\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records the alert in the Gateway's own Activity and outer loop, then posts the webhook.
 *
 * The Activity entry is written first, so a process that dies during the webhook still leaves a
 * running entry that the interrupted-activity sweep ends.
 */
final readonly class GatewayReleaseAlertNotifier implements ReleaseAlertNotifier
{
    public const string Command = 'release:alert';

    private const int SummaryLimit = 1000;

    public function __construct(
        private ProblemCollector $problems,
        private TaskExtensionState $tasks,
        private ReleaseAlertWebhook $webhook,
        private LogRedactor $redactor,
    ) {}

    public function alert(ReleaseAlert $alert): ReleaseAlertReceipt
    {
        $startedAt = hrtime(true);
        $requestId = (string) Str::uuid();
        $occurredAt = CarbonImmutable::now('UTC');
        $summary = $this->summary($alert->summary);
        $evidenceUrl = $alert->evidenceUrl === null ? null : $this->redactor->redact($alert->evidenceUrl, []);
        $details = [
            'kind' => $alert->kind->value,
            'subject' => $alert->subject->toArray(),
            'summary' => $summary,
            'evidence_url' => $evidenceUrl,
        ];

        $activity = $this->start($requestId, $alert->kind, $details);
        $problem = $this->recordProblem($alert, $summary, $evidenceUrl, $activity);
        $webhook = $this->post([
            'event' => 'release.alert',
            ...$details,
            'text' => $this->text($alert, $summary, $evidenceUrl),
            'occurred_at' => $occurredAt->format('Y-m-d\TH:i:s\Z'),
            'request_id' => $requestId,
            'activity_id' => $activity?->id,
            'problem_fingerprint' => $problem->fingerprint,
        ]);
        $this->finish($activity, $details, $problem, $webhook, $startedAt);

        return new ReleaseAlertReceipt($requestId, $activity?->id, $problem, $webhook);
    }

    /** @param array<string, mixed> $details */
    private function start(string $requestId, ReleaseAlertKind $kind, array $details): ?Activity
    {
        try {
            return Activity::query()->create([
                'log_name' => 'releases',
                'description' => $kind->value,
                'properties' => $details,
                'request_id' => $requestId,
                'command' => self::Command,
                'status' => 'running',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function recordProblem(ReleaseAlert $alert, string $summary, ?string $evidenceUrl, ?Activity $activity): ReleaseAlertStep
    {
        try {
            if (! $this->tasks->enabled()) {
                return ReleaseAlertStep::skipped('tasks_disabled');
            }

            $subject = $alert->subject;
            $observation = [
                'summary' => $summary,
                'release_repository' => $subject->repository,
                'evidence_urls' => $evidenceUrl === null ? [] : [$evidenceUrl],
                'activity_ids' => $activity instanceof Activity ? [$activity->id] : [],
            ];

            if ($subject->releaseId !== null) {
                $observation['release_id'] = $subject->releaseId;
            }

            $key = $alert->kind->value.'|'.$subject->target.'|'.$subject->sha;
            $recorded = $this->problems->recordRelease($key, $observation);

            return $recorded['stored']
                ? ReleaseAlertStep::done($recorded['fingerprint'])
                : ReleaseAlertStep::skipped('suppressed', $recorded['fingerprint']);
        } catch (Throwable $exception) {
            report($exception);

            return ReleaseAlertStep::failed('error');
        }
    }

    /** @param array<string, mixed> $payload */
    private function post(array $payload): ReleaseAlertStep
    {
        try {
            return $this->webhook->send($payload);
        } catch (Throwable $exception) {
            report($exception);

            return ReleaseAlertStep::failed('error');
        }
    }

    /** @param array<string, mixed> $details */
    private function finish(?Activity $activity, array $details, ReleaseAlertStep $problem, ReleaseAlertStep $webhook, int $startedAt): void
    {
        if (! $activity instanceof Activity) {
            return;
        }

        $errorCode = match (true) {
            $problem->failedStep() => 'release.alert_problem_failed',
            $webhook->failedStep() => 'release.alert_webhook_failed',
            default => null,
        };

        try {
            $activity->update([
                'properties' => [...$details, 'problem' => $problem->toArray(), 'webhook' => $webhook->toArray()],
                'status' => $errorCode === null ? 'succeeded' : 'failed',
                'error_code' => $errorCode,
                'duration_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function summary(string $summary): string
    {
        $redacted = trim($this->redactor->redact(mb_scrub($summary, 'UTF-8'), []));

        if (mb_strlen($redacted) <= self::SummaryLimit) {
            return $redacted;
        }

        return mb_substr($redacted, 0, self::SummaryLimit).'...';
    }

    /**
     * One chat line. Slack reads `&`, `<`, and `>` as markup, so they are escaped here and only here.
     */
    private function text(ReleaseAlert $alert, string $summary, ?string $evidenceUrl): string
    {
        $subject = $alert->subject;
        $line = sprintf(
            '%s for %s %s@%s: %s',
            $alert->kind->label(),
            $subject->target,
            $subject->repository,
            $subject->shortSha(),
            preg_replace('/\s+/u', ' ', $summary) ?? $summary,
        );

        if ($evidenceUrl !== null) {
            $line .= ' '.$evidenceUrl;
        }

        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $line);
    }
}
