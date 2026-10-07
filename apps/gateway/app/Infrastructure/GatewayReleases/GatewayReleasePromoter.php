<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\DeployedGatewayRelease;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseRuntime;
use App\Domain\GatewayReleases\GatewayReleaseSmoke;
use App\Domain\GatewayReleases\GatewayReleaseVerifier;
use App\Domain\GatewayReleases\GatewayReleaseWebBuild;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Switches to a prepared release, hands the runtime over, verifies, switches the web app, then
 * runs smoke. Verify and smoke see the new release. Smoke runs only after the web switch.
 *
 * Any failure after the switch, expected or not, is handled the same way: without migrations the
 * previous release becomes current again and its runtime handoff repeats; after migrations the
 * release pauses. A failure after migrations always pauses, also when the switch itself failed,
 * because the previous code then serves a schema it has not run. Every outcome is recorded.
 */
final readonly class GatewayReleasePromoter
{
    /** The default number of prepared releases kept, newest first, besides the current and previous one. */
    public const int KeptReleases = 5;

    public function __construct(
        private GatewayReleaseLayout $layout,
        private GatewayReleaseSwitcher $switcher,
        private GatewayReleaseRuntime $runtime,
        private GatewayReleaseVerifier $verifier,
        private GatewayReleaseWebBuild $web,
        private GatewayReleaseSmoke $smoke,
        private GatewayReleaseRecorder $recorder,
        private GatewayReleaseBuilder $builder,
        private int $keptReleases = self::KeptReleases,
    ) {}

    /**
     * @param  array<string, mixed>  $phases
     */
    public function promote(
        string $id,
        string $sha,
        string $trigger,
        bool $migrationsRan,
        ?string $snapshotPath,
        array $phases,
        int $startedAt,
    ): DeployedGatewayRelease {
        $previous = $this->layout->currentReleaseId();
        $handoff = null;
        $step = 'configuration';

        try {
            $this->builder->refreshConfiguration($id);
            $phases['configuration'] = ['outcome' => 'cached'];
            $step = 'switch';
            $previous = $this->switcher->switchTo($id);
            $phases['switch'] = ['outcome' => 'switched', 'from' => $previous, 'to' => $id];
            $step = 'handoff';
            $handoff = $this->runtime->handoff($id);
            $phases['handoff'] = $handoff;
            $step = 'verify';
            $verified = $this->verifier->verify($sha);
            $phases['verify'] = ['outcome' => 'passed', 'status' => $verified['status'], 'version' => $verified['version']];
            $step = 'web';
            $this->web->publish($id);
            $phases['web'] = ['outcome' => 'published'];
            $step = 'smoke';
            $phases['smoke'] = $this->smoke->run($id, $sha);
        } catch (Throwable $exception) {
            $this->fail(
                exception: GatewayReleaseException::fromThrowable($exception, $step, $sha),
                id: $id,
                sha: $sha,
                trigger: $trigger,
                migrationsRan: $migrationsRan,
                snapshotPath: $snapshotPath,
                phases: $phases,
                startedAt: $startedAt,
                previous: $previous,
                handoff: $handoff,
            );
        }

        $release = $this->result(
            id: $id,
            sha: $sha,
            outcome: 'verified',
            trigger: $trigger,
            migrationsRan: $migrationsRan,
            previousId: $previous,
            snapshotPath: $snapshotPath,
            cleanupPaused: $handoff['cleanup_paused'],
            startedAt: $startedAt,
            phases: $phases,
        );
        $this->record($release);
        $this->prune($id, $previous);

        return $release;
    }

    /**
     * @param  array<string, mixed>  $phases
     * @param  array<string, mixed>|null  $handoff
     */
    private function fail(
        GatewayReleaseException $exception,
        string $id,
        string $sha,
        string $trigger,
        bool $migrationsRan,
        ?string $snapshotPath,
        array $phases,
        int $startedAt,
        ?string $previous,
        ?array $handoff,
    ): never {
        $outcome = 'failed';
        $cleanupPaused = is_array($handoff) && ($handoff['cleanup_paused'] ?? null) === true;
        $phases[$exception->step] = ['outcome' => 'failed', 'error_code' => $exception->errorCode];
        $switched = $previous !== $id && $this->layout->currentReleaseId() === $id;

        if ($migrationsRan) {
            $outcome = 'paused';
            $phases['pause'] = ['outcome' => 'paused', 'snapshot' => $snapshotPath, 'current' => $this->layout->currentReleaseId()];
        } elseif ($switched && $previous !== null) {
            try {
                $this->switcher->switchTo($previous);
                $this->web->restore($previous);
                $restored = $this->runtime->handoff($previous);
                $phases['switch_back'] = ['outcome' => 'switched_back', 'to' => $previous, 'handoff' => $restored];
                $outcome = 'switched_back';
                $cleanupPaused = $restored['cleanup_paused'];
            } catch (Throwable $thrown) {
                $back = GatewayReleaseException::fromThrowable($thrown, 'switch_back', $sha);
                $phases['switch_back'] = ['outcome' => 'failed', 'error_code' => $back->errorCode, 'current' => $this->layout->currentReleaseId()];
                $exception = new GatewayReleaseException(
                    step: 'switch',
                    errorCode: 'gateway.release_switch_back_failed',
                    message: $exception->getMessage().' Switch-back then failed: '.$back->getMessage(),
                    status: 500,
                    previous: $back,
                    sha: $sha,
                );
            }
        }

        $this->record($this->result(
            id: $id,
            sha: $sha,
            outcome: $outcome,
            trigger: $trigger,
            migrationsRan: $migrationsRan,
            previousId: $previous,
            snapshotPath: $snapshotPath,
            cleanupPaused: $cleanupPaused,
            startedAt: $startedAt,
            phases: $phases,
            errorCode: $exception->errorCode,
            message: $exception->getMessage(),
        ));

        throw $exception;
    }

    /**
     * Writes the record without letting a failed write change the outcome: a verified release is
     * not switched back because its row could not be stored.
     */
    private function record(DeployedGatewayRelease $release): void
    {
        try {
            $this->recorder->write($release);
        } catch (Throwable $exception) {
            Log::error('The Gateway release record could not be written.', [
                'release' => $release->toArray(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $phases
     */
    private function result(
        string $id,
        string $sha,
        string $outcome,
        string $trigger,
        bool $migrationsRan,
        ?string $previousId,
        ?string $snapshotPath,
        bool $cleanupPaused,
        int $startedAt,
        array $phases,
        ?string $errorCode = null,
        ?string $message = null,
    ): DeployedGatewayRelease {
        return new DeployedGatewayRelease(
            id: $id,
            sha: $sha,
            outcome: $outcome,
            trigger: $trigger,
            migrationsRan: $migrationsRan,
            previousId: $previousId,
            snapshotPath: $snapshotPath,
            cleanupPaused: $cleanupPaused,
            retryable: false,
            durationMs: intdiv(hrtime(true) - $startedAt, 1_000_000),
            phases: $phases,
            errorCode: $errorCode,
            message: $message,
        );
    }

    /** Keeps the newest releases plus the current and the previous one, whatever their age. */
    private function prune(string $current, ?string $previous): void
    {
        $ids = $this->layout->retainedReleaseIds();
        $keep = array_values(array_unique(array_filter([
            ...array_slice($ids, 0, max(1, $this->keptReleases)),
            $current,
            $previous,
            $this->layout->currentReleaseId(),
        ])));

        foreach ($ids as $id) {
            if (in_array($id, $keep, true)) {
                continue;
            }

            try {
                $this->builder->remove($id);
            } catch (Throwable) {
                // Pruning is not the release. A release that cannot be removed stays until the next one.
            }
        }
    }
}
