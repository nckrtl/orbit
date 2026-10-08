<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use Orbit\Sdk\Responses\GatewayReleases\GatewayReleaseAutomationResponse;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleaseResponse;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleaseSmokeResponse;

/** Human renderings shared by the Gateway release commands. */
final class GatewayReleaseOutput
{
    /** @return list<string> */
    public static function row(GatewayReleaseResponse $record): array
    {
        return [
            (string) $record->id,
            $record->release ?? $record->requested ?? '—',
            $record->trigger,
            $record->outcome,
            $record->errorCode ?? '—',
            self::duration($record->durationMs),
            $record->createdAt ?? '—',
        ];
    }

    /** @return array<string, string|int|null> */
    public static function detail(GatewayReleaseResponse $record): array
    {
        $fields = [
            'Record' => $record->id,
            'Commit' => $record->sha ?? $record->requested,
            'Trigger' => $record->trigger.($record->force ? ' (force)' : ''),
            'Outcome' => $record->outcome,
            'Previous' => $record->previous,
            'Migrations' => $record->migrationsRan ? 'ran' : 'none',
            'Snapshot' => $record->snapshot,
            'Steps' => self::steps($record),
            'Cleanup' => $record->cleanupPaused ? 'paused' : null,
            'Error' => $record->errorCode,
            'Message' => $record->message,
            'Alert' => self::alert($record->alert),
            'Duration' => self::duration($record->durationMs),
            'Started' => $record->createdAt,
            'Updated' => $record->updatedAt,
        ];

        if (! $record->cleanupPaused) {
            unset($fields['Cleanup']);
        }

        return $fields;
    }

    /** @return array<string, string|null> */
    public static function automation(GatewayReleaseAutomationResponse $state): array
    {
        $tick = $state->lastTick;
        $pause = $state->pause;

        return [
            'Enabled' => $state->enabled ? 'yes' : 'no',
            'Paused' => $pause === null
                ? 'no'
                : 'yes, '.implode(', ', array_filter([
                    is_string($pause['reason'] ?? null) ? str_replace('_', ' ', $pause['reason']) : null,
                    is_string($pause['release'] ?? null) ? 'release '.$pause['release'] : null,
                    is_string($pause['error_code'] ?? null) ? $pause['error_code'] : null,
                    is_string($pause['snapshot'] ?? null) ? 'snapshot '.$pause['snapshot'] : null,
                ])),
            'Current release' => $state->currentRelease,
            'Current commit' => $state->currentSha,
            'Source' => $state->branch.', check '.$state->check,
            'Last check' => is_array($tick) && is_string($tick['checked_at'] ?? null) ? $tick['checked_at'] : null,
            'Last result' => is_array($tick) && is_string($tick['result'] ?? null)
                ? implode(', ', array_filter([$tick['result'], is_string($tick['error_code'] ?? null) ? $tick['error_code'] : null, is_string($tick['sha'] ?? null) ? substr($tick['sha'], 0, 12) : null]))
                : null,
            'Stalled since' => $state->stalledSince,
            'Branch head' => $state->branchHead,
            'Behind since' => $state->behindSince,
            'Scheduler tick' => self::tickConfirmation($state->tickConfirmation),
        ];
    }

    /**
     * Whether the current release's own scheduler has run `tasks:tick`, with the time that decides it.
     *
     * @param  array<string, mixed>|null  $phase
     */
    private static function tickConfirmation(?array $phase): ?string
    {
        $outcome = is_array($phase) && is_string($phase['outcome'] ?? null) ? $phase['outcome'] : null;

        return match ($outcome) {
            null => null,
            'pending' => 'pending, due by '.(is_string($phase['deadline'] ?? null) ? $phase['deadline'] : '—'),
            'confirmed' => 'confirmed, tick at '.(is_string($phase['last_tick_at'] ?? null) ? $phase['last_tick_at'] : '—'),
            'missed' => 'missed, no tick by '.(is_string($phase['deadline'] ?? null) ? $phase['deadline'] : '—'),
            default => $outcome,
        };
    }

    /** @return array<string, string|null> */
    public static function smoke(GatewayReleaseSmokeResponse $result): array
    {
        $report = $result->report ?? [];
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $counts = [];

        foreach (['passed', 'failed', 'timeout', 'skipped'] as $status) {
            if (is_int($summary[$status] ?? null)) {
                $counts[] = $summary[$status].' '.$status;
            }
        }

        // A passed smoke has no message or next step, so those rows appear only when the report has them.
        return array_filter([
            'Commit' => $result->sha,
            'Outcome' => $result->outcome,
            'Checks' => $counts === [] ? null : implode(', ', $counts),
            'Message' => is_string($report['message'] ?? null) ? $report['message'] : false,
            'Next' => is_string($report['next'] ?? null) ? $report['next'] : false,
            'Duration' => is_int($report['duration_ms'] ?? null) ? self::duration($report['duration_ms']) : null,
            'Started' => is_string($report['started_at'] ?? null) ? $report['started_at'] : null,
        ], static fn (string|false|null $value): bool => $value !== false);
    }

    /** @return list<list<string>> */
    public static function smokeChecks(GatewayReleaseSmokeResponse $result): array
    {
        $rows = [];

        foreach ($result->checks() as $name => $check) {
            $rows[] = [
                $name,
                $check['status'],
                $check['error'] ?? '—',
                $check['message'] ?? '—',
                $check['duration_ms'] === null ? '—' : self::duration($check['duration_ms']),
            ];
        }

        return $rows;
    }

    private static function steps(GatewayReleaseResponse $record): ?string
    {
        $steps = [];

        foreach ($record->phases as $step => $phase) {
            $outcome = is_array($phase) && is_string($phase['outcome'] ?? null) ? $phase['outcome'] : 'done';
            $steps[] = $step.' '.$outcome;
        }

        return $steps === [] ? null : implode(', ', $steps);
    }

    /** @param array<string, mixed>|null $alert */
    private static function alert(?array $alert): ?string
    {
        if ($alert === null) {
            return null;
        }

        $kind = is_string($alert['kind'] ?? null) ? $alert['kind'] : 'alert';
        $activity = is_int($alert['activity_id'] ?? null) ? 'Activity '.$alert['activity_id'] : null;
        $skipped = is_string($alert['reason'] ?? null) ? 'skipped: '.$alert['reason'] : null;

        return implode(', ', array_filter([$kind, $activity, $skipped]));
    }

    private static function duration(int $milliseconds): string
    {
        if ($milliseconds < 1000) {
            return $milliseconds.' ms';
        }

        $seconds = intdiv($milliseconds, 1000);

        return $seconds < 60 ? $seconds.' s' : intdiv($seconds, 60).' min '.($seconds % 60).' s';
    }
}
