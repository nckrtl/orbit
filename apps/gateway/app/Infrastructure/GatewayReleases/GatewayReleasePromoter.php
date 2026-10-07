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
use Throwable;

/**
 * Switches to a prepared release, hands the runtime over, verifies, switches the web app, then
 * runs smoke. Verify and smoke see the new release. Smoke runs only after the web switch. A
 * failure before migrations switches back and repeats the runtime handoff for the previous
 * release. A failure after migrations pauses instead.
 */
final readonly class GatewayReleasePromoter
{
    /** @var list<string> */
    private const array SWITCH_BACK_STEPS = ['handoff', 'verify', 'web', 'smoke'];

    public function __construct(
        private GatewayReleaseLayout $layout,
        private GatewayReleaseSwitcher $switcher,
        private GatewayReleaseRuntime $runtime,
        private GatewayReleaseVerifier $verifier,
        private GatewayReleaseWebBuild $web,
        private GatewayReleaseSmoke $smoke,
        private GatewayReleaseRecorder $recorder,
        private GatewayReleaseBuilder $builder,
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
        $previous = null;
        $switched = false;
        $handoff = null;

        try {
            $previous = $this->switcher->switchTo($id);
            $switched = $previous !== $id;
            $phases['switch'] = ['outcome' => 'switched', 'from' => $previous, 'to' => $id];
            $handoff = $this->runtime->handoff($id);
            $phases['handoff'] = $handoff;
            $verified = $this->verifier->verify($sha);
            $phases['verify'] = ['outcome' => 'passed', 'status' => $verified['status'], 'version' => $verified['version']];
            $this->web->publish($id);
            $phases['web'] = ['outcome' => 'published'];
            $smoked = $this->smoke->run($id, $sha);
            $phases['smoke'] = $smoked;

            $release = $this->result(
                id: $id,
                sha: $sha,
                outcome: 'verified',
                trigger: $trigger,
                migrationsRan: $migrationsRan,
                previousId: $previous,
                snapshotPath: $snapshotPath,
                cleanupPaused: $handoff['cleanup_paused'],
                retryable: false,
                startedAt: $startedAt,
                phases: $phases,
            );
            $this->recorder->write($release);
            $this->prune($id, $previous);

            return $release;
        } catch (GatewayReleaseException $exception) {
            return $this->fail($exception, $id, $sha, $trigger, $migrationsRan, $snapshotPath, $phases, $startedAt, $previous, $switched, $handoff);
        }
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
        bool $switched,
        ?array $handoff,
    ): never {
        $outcome = 'failed';
        $reportedPause = is_array($handoff) ? ($handoff['cleanup_paused'] ?? null) : null;
        $cleanupPaused = $reportedPause === true;
        $phases[$exception->step] = ['outcome' => 'failed', 'error_code' => $exception->errorCode];

        if ($this->switchesBack($exception, $switched, $migrationsRan, $previous, $id)) {
            try {
                $this->switcher->switchTo((string) $previous);
                $this->web->restore((string) $previous);
                $restored = $this->runtime->handoff((string) $previous);
                $phases['switch_back'] = ['outcome' => 'switched_back', 'to' => $previous, 'handoff' => $restored];
                $outcome = 'switched_back';
                $cleanupPaused = $restored['cleanup_paused'];
            } catch (GatewayReleaseException $back) {
                $phases['switch_back'] = ['outcome' => 'failed', 'error_code' => $back->errorCode];
                $exception = new GatewayReleaseException(
                    step: 'switch',
                    errorCode: 'gateway.release_switch_back_failed',
                    message: $exception->getMessage().' Switch-back then failed: '.$back->getMessage(),
                    status: 500,
                    previous: $back,
                    sha: $sha,
                );
            }
        } elseif ($switched && $migrationsRan) {
            $outcome = 'paused';
            $phases['pause'] = ['outcome' => 'paused', 'snapshot' => $snapshotPath];
        }

        $release = $this->result(
            id: $id,
            sha: $sha,
            outcome: $outcome,
            trigger: $trigger,
            migrationsRan: $migrationsRan,
            previousId: $previous,
            snapshotPath: $snapshotPath,
            cleanupPaused: $cleanupPaused,
            retryable: false,
            startedAt: $startedAt,
            phases: $phases,
            errorCode: $exception->errorCode,
            message: $exception->getMessage(),
        );
        $this->recorder->write($release);

        throw $exception;
    }

    private function switchesBack(
        GatewayReleaseException $exception,
        bool $switched,
        bool $migrationsRan,
        ?string $previous,
        string $id,
    ): bool {
        return $switched
            && ! $migrationsRan
            && $previous !== null
            && $previous !== $id
            && in_array($exception->step, self::SWITCH_BACK_STEPS, true);
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
        bool $retryable,
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
            retryable: $retryable,
            durationMs: intdiv(hrtime(true) - $startedAt, 1_000_000),
            phases: $phases,
            errorCode: $errorCode,
            message: $message,
        );
    }

    private function prune(string $current, ?string $previous): void
    {
        $ids = $this->layout->retainedReleaseIds();
        $keep = array_values(array_unique(array_filter([
            ...array_slice($ids, 0, 5),
            $current,
            $previous,
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
