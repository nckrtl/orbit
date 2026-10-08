<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\Releases\ReleaseAlert;
use App\Domain\Releases\ReleaseAlertKind;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\Releases\ReleaseAlertReceipt;
use App\Domain\Releases\ReleaseAlertSubject;
use App\Models\GatewayRelease;
use InvalidArgumentException;
use Throwable;

/**
 * Raises the release alert for a Gateway release record that needs a person
 * ([Release alerts](/reference/gateway-recovery#release-alerts)). A record alerts at most once: the
 * receipt is stored on the record, and a record that already has one is skipped. Callers run it
 * after the record is written, outside any database transaction.
 */
final readonly class GatewayReleaseAlerts
{
    public const string Target = 'gateway';

    public function __construct(
        private ReleaseAlertNotifier $notifier,
        private GatewayReleaseSource $source,
    ) {}

    /** The alert kind a finished record raises, or null when it needs no person. */
    public static function kindFor(GatewayRelease $record): ?ReleaseAlertKind
    {
        return match (true) {
            $record->outcome === 'paused' => ReleaseAlertKind::ReleasePaused,
            in_array($record->outcome, ['failed', 'switched_back'], true) && ! $record->retryable => ReleaseAlertKind::ReleaseFailed,
            $record->outcome === GatewayRelease::Interrupted && GatewayReleaseRecorder::touchedLiveState($record) => ReleaseAlertKind::ReleasePaused,
            $record->outcome === GatewayRelease::Interrupted && ! $record->retryable => ReleaseAlertKind::ReleaseFailed,
            $record->outcome === 'verified' && $record->cleanup_paused => ReleaseAlertKind::ReleaseCleanupPaused,
            default => null,
        };
    }

    public function raise(GatewayRelease $record): void
    {
        $kind = self::kindFor($record);

        if (! $kind instanceof ReleaseAlertKind || $record->alert !== null) {
            return;
        }

        $summary = $kind === ReleaseAlertKind::ReleaseCleanupPaused
            ? sprintf(
                'Release %s is live, but document cleanup stays paused (%s). Reconcile and resume it.',
                $record->release_id ?? (string) $record->requested,
                $this->cleanupErrorCode($record),
            )
            : sprintf(
                'Release %s %s at step %s: %s',
                $record->release_id ?? (string) $record->requested,
                str_replace('_', ' ', $record->outcome),
                $this->failedStep($record),
                $record->message ?? (string) $record->error_code,
            );
        $receipt = $record->commit() === null
            ? null
            : $this->send($kind, (string) $record->commit(), (string) $record->id, $summary);

        $this->store($record, $kind, $receipt);
    }

    /**
     * Raises the one alert a pause starts with, for a pause that a successful record caused: a
     * verified rollback or a manual deploy that pins an older commit. A failed record that pauses
     * alerts through {@see self::raise()}. The receipt is stored on the record, so a record alerts once.
     */
    public function paused(GatewayRelease $record, string $reason): void
    {
        if ($record->alert !== null) {
            return;
        }

        $summary = sprintf(
            'Automatic releases are paused (%s) after release %s went live. Run gateway:release:auto:resume to hand back to automation.',
            $reason,
            $record->release_id ?? (string) $record->requested,
        );
        $receipt = $record->commit() === null ? null : $this->send(ReleaseAlertKind::ReleasePaused, (string) $record->commit(), (string) $record->id, $summary);

        $this->store($record, ReleaseAlertKind::ReleasePaused, $receipt);
    }

    /**
     * Raises the one alert of a verified release whose scheduler showed no `tasks:tick` in time. It returns what the
     * caller stores on the release's `tick` phase, so it does not take the record's own alert, which a failure or a
     * paused document cleanup may already hold.
     *
     * @return array<string, mixed>
     */
    public function schedulerSilent(GatewayRelease $record, string $summary): array
    {
        $receipt = $record->commit() === null ? null : $this->send(ReleaseAlertKind::ReleaseSchedulerSilent, (string) $record->commit(), (string) $record->id, $summary);

        return $this->stored($record, ReleaseAlertKind::ReleaseSchedulerSilent, $receipt);
    }

    /**
     * Raises the one alert of a verified release whose handoff could not bring the Gateway Node's own `orbit-agent` to
     * the pin. The receipt is stored on the scheduler phase's `gateway_agent` result, so it does not take the record's
     * own alert, and a record alerts for it once.
     */
    public function gatewayAgent(GatewayRelease $record): void
    {
        $phases = $record->phases;
        $scheduler = is_array($phases['scheduler'] ?? null) ? $phases['scheduler'] : [];
        $agent = is_array($scheduler['gateway_agent'] ?? null) ? $scheduler['gateway_agent'] : [];

        if ($record->outcome !== 'verified' || ($agent['outcome'] ?? null) !== 'failed' || array_key_exists('alert', $agent)) {
            return;
        }

        $summary = sprintf(
            'Release %s is live, but the Gateway Node\'s orbit-agent did not move to %s (%s): %s',
            $record->release_id ?? (string) $record->requested,
            is_string($agent['version'] ?? null) ? $agent['version'] : 'the pin',
            is_string($agent['error_code'] ?? null) ? $agent['error_code'] : 'no error code',
            is_string($agent['message'] ?? null) ? $agent['message'] : 'no message',
        );
        $receipt = $record->commit() === null ? null : $this->send(ReleaseAlertKind::ReleaseGatewayAgentFailed, (string) $record->commit(), (string) $record->id, $summary);
        $stored = $this->stored($record, ReleaseAlertKind::ReleaseGatewayAgentFailed, $receipt);

        try {
            // Only this key: the tick confirmation may write `phases.tick` meanwhile, outside the release lock.
            GatewayRelease::query()->whereKey($record->getKey())->update(['phases->scheduler->gateway_agent->alert' => $stored]);
            $record->refresh();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Raises a stalled alert for the deployed commit when automatic releases cannot make progress.
     * The caller decides when a stall is long enough and that it alerts once.
     */
    public function stalled(string $deployedSha, string $summary): ?ReleaseAlertReceipt
    {
        return $this->send(ReleaseAlertKind::ReleaseStalled, $deployedSha, null, $summary);
    }

    private function send(ReleaseAlertKind $kind, string $sha, ?string $recordId, string $summary): ?ReleaseAlertReceipt
    {
        $repository = $this->source->repositoryName();

        if ($repository === null) {
            return null;
        }

        try {
            $alert = new ReleaseAlert($kind, new ReleaseAlertSubject(self::Target, $repository, $sha, $recordId), $summary);
        } catch (InvalidArgumentException $exception) {
            report($exception);

            return null;
        }

        return $this->notifier->alert($alert);
    }

    /** @return array<string, mixed> */
    private function stored(GatewayRelease $record, ReleaseAlertKind $kind, ?ReleaseAlertReceipt $receipt): array
    {
        return $receipt instanceof ReleaseAlertReceipt
            ? ['kind' => $kind->value, ...$receipt->toArray()]
            : ['kind' => $kind->value, 'outcome' => 'skipped', 'reason' => $record->commit() === null ? 'commit_unknown' : 'repository_unknown'];
    }

    private function store(GatewayRelease $record, ReleaseAlertKind $kind, ?ReleaseAlertReceipt $receipt): void
    {
        $alert = $this->stored($record, $kind, $receipt);

        try {
            $record->forceFill(['alert' => $alert])->save();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function cleanupErrorCode(GatewayRelease $record): string
    {
        foreach (['scheduler', 'handoff'] as $step) {
            $phase = $record->phases[$step] ?? null;
            $code = is_array($phase) ? ($phase['cleanup_error_code'] ?? null) : null;

            if (is_string($code)) {
                return $code;
            }
        }

        return 'no error code';
    }

    private function failedStep(GatewayRelease $record): string
    {
        foreach ($record->phases as $step => $phase) {
            if (is_array($phase) && ($phase['outcome'] ?? null) === 'failed' && $step !== 'switch_back') {
                return (string) $step;
            }
        }

        return 'release';
    }
}
