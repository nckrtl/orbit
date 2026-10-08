<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseAutomation;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GitHub\BranchHeadReader;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GreenCommit;
use App\Domain\GitHub\GreenCommitResolver;
use App\Infrastructure\GatewayReleases\GatewayReleaseAlerts;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Infrastructure\GatewayReleases\GatewayReleaseSource;
use App\Infrastructure\GatewayReleases\GatewayReleaseSupersession;
use App\Infrastructure\GatewayReleases\GatewayReleaseTickConfirmation;
use App\Models\GatewayRelease;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * One tick of automatic Gateway releases, run every minute by `orbit-gateway-release.timer`
 * ([Automatic releases](/reference/gateway-recovery#automatic-releases)).
 *
 * The tick exits quietly when automatic releases are disabled or paused, when a requested release
 * waits for its unit, when another release step holds the lock, or when no newer green commit
 * exists. Under the lock it first ends the records of releases that died. When the current release
 * was put live by hand after the last resume and a newer green commit exists, it pauses instead of
 * undoing the manual release. Otherwise it deploys the newest releasable
 * commit with the trigger `auto`. A failed release raises its alert through the release record.
 * GitHub errors and other transient causes are a recorded skip; they alert once when they have kept
 * releases from making progress for {@see self::StallAlertMinutes} minutes.
 *
 * The resolver has no answer both when nothing new is green and when the deployed commit left the
 * branch, such as after a force push. So while up to date, the tick reads the branch head at most
 * every {@see self::HeadCheckMinutes} minutes. When the head has differed from the deployed commit
 * for {@see self::BehindAlertHours} hours, it alerts once, until the deployed commit changes or the
 * head is deployed.
 *
 * @phpstan-import-type Tick from GatewayReleaseAutomation
 */
final readonly class RunAutomaticGatewayReleaseAction
{
    public const int StallAlertMinutes = 30;

    /** A commit whose release failed for a transient reason is tried again after this long. */
    public const int RetryMinutes = 10;

    public const int HeadCheckMinutes = 15;

    public const int BehindAlertHours = 6;

    public function __construct(
        private GatewayReleaseAutomation $automation,
        private GatewayReleaseLock $lock,
        private GatewayReleaseLayout $layout,
        private GatewayReleaseSource $source,
        private GreenCommitResolver $resolver,
        private DeployGatewayReleaseAction $deploy,
        private GatewayReleaseAlerts $alerts,
        private BranchHeadReader $heads,
        private GatewayReleaseRecorder $recorder,
        private ?GatewayReleaseTickConfirmation $ticks = null,
    ) {}

    /** @return Tick */
    public function execute(): array
    {
        // Before anything that can return early: a manual deploy, a rollback, and a release while automatic releases
        // are disabled or paused still get their tick confirmed.
        $this->confirmTicks();

        if (! $this->automation->enabled()) {
            $this->automation->clearStall();

            return $this->automation->recordTick('disabled');
        }

        if ($this->automation->pause() !== null) {
            $this->automation->clearStall();
            $this->watchWhilePaused();

            return $this->automation->recordTick('paused');
        }

        $waiting = GatewayRelease::query()
            ->where('outcome', GatewayRelease::Queued)
            ->where('created_at', '>', CarbonImmutable::now()->subSeconds(GatewayReleaseRecorder::QueuedStartSeconds))
            ->exists();

        if ($waiting) {
            // A requested release goes first. Taking the lock now would make its unit fail.
            return $this->stalled($this->deployedCommit(), 'busy', null, 'gateway.release_queued', 'A requested release waits for its unit.');
        }

        try {
            $this->lock->run(fn (): array => $this->recorder->settleDead());
        } catch (GatewayReleaseException $exception) {
            return $this->stalled($this->deployedCommit(), 'busy', null, $exception->errorCode, $exception->getMessage());
        }

        if ($this->automation->pause() !== null) {
            return $this->automation->recordTick('paused');
        }

        $current = $this->layout->currentReleaseId();

        if ($current === null) {
            return $this->automation->recordTick('not_adopted', errorCode: 'gateway.release_not_adopted', message: 'The Gateway does not run from a release. Adopt it, then deploy the first release by hand.');
        }

        $deployed = $this->layout->preparedCommit($current);

        if ($deployed === null) {
            return $this->automation->recordTick('no_deployed_release', errorCode: 'gateway.release_current_incomplete', message: "The current release [{$current}] has no readable REVISION.");
        }

        try {
            $repository = $this->source->repository();
            $green = $this->resolver->resolve(
                $repository,
                $this->source->branch,
                $this->source->checkName,
                $deployed,
                GatewayRelease::failedShas(),
            );
        } catch (GitHubApiException|InvalidArgumentException|GatewayReleaseException $exception) {
            $errorCode = $exception instanceof GatewayReleaseException ? $exception->errorCode : 'gateway.release_source_unavailable';

            return $this->stalled($deployed, 'source_unavailable', null, $errorCode, $exception->getMessage());
        }

        if (! $green instanceof GreenCommit) {
            $this->automation->clearStall();
            $this->watchBranchHead($repository, $deployed);

            return $this->automation->recordTick('up_to_date', $deployed);
        }

        $manual = $this->manualRelease($current);

        if ($manual instanceof GatewayRelease) {
            $this->automation->pauseFor('manual_deploy', $manual);
            $this->alerts->paused($manual, 'manual_deploy');

            return $this->automation->recordTick('paused', $green->sha, $manual->id, 'gateway.release_manual_deploy', "Release [{$current}] was deployed by hand and [{$green->sha}] is newer. Resume to release it.");
        }

        if ($this->backingOff($green->sha)) {
            return $this->automation->recordTick('backing_off', $green->sha);
        }

        return $this->release($green->sha, $deployed);
    }

    /** @return Tick */
    private function release(string $sha, string $deployed): array
    {
        try {
            $this->deploy->execute($sha, trigger: 'auto');
        } catch (GatewayReleaseException $exception) {
            if ($exception->errorCode === 'gateway.release_in_progress') {
                return $this->stalled($deployed, 'busy', null, $exception->errorCode, $exception->getMessage(), $sha);
            }

            $record = $this->record($sha);

            if ($record instanceof GatewayRelease && ! $record->retryable) {
                $this->automation->clearStall();

                return $this->automation->recordTick($record->outcome === 'paused' ? 'paused' : 'failed', $sha, $record->id, $exception->errorCode, $exception->getMessage());
            }

            return $this->stalled($deployed, 'failed', $record?->id, $exception->errorCode, $exception->getMessage(), $sha);
        } catch (Throwable $exception) {
            report($exception);

            return $this->stalled($deployed, 'failed', $this->record($sha)?->id, 'gateway.release_unexpected_failure', $exception->getMessage(), $sha);
        }

        $this->automation->clearStall();

        return $this->automation->recordTick('released', $sha, $this->record($sha)?->id);
    }

    /**
     * A transient cause keeps releases from making progress. It alerts once after the stall lasted
     * long enough.
     *
     * @return Tick
     */
    private function stalled(?string $deployed, string $result, ?int $record, string $errorCode, string $message, ?string $sha = null): array
    {
        $stall = $this->automation->stalled();
        $since = CarbonImmutable::parse($stall['since']);

        if ($deployed !== null && ! $stall['alerted'] && $since->lte(CarbonImmutable::now()->subMinutes(self::StallAlertMinutes))) {
            $this->alerts->stalled($deployed, sprintf(
                'Automatic releases have made no progress since %s: %s %s',
                $stall['since'],
                $errorCode,
                $message,
            ));
            $this->automation->stallAlerted();
        }

        return $this->automation->recordTick($result, $sha, $record, $errorCode, $message);
    }

    /**
     * Alerts once when the branch head has not been deployed for {@see self::BehindAlertHours} hours.
     * A failed head read is skipped quietly; the next read comes on a later tick.
     */
    private function watchBranchHead(GitHubRepository $repository, string $deployed): void
    {
        $state = $this->automation->branchHead();
        $fresh = $state !== null
            && $state['deployed'] === $deployed
            && CarbonImmutable::parse($state['checked_at'])->gt(CarbonImmutable::now()->subMinutes(self::HeadCheckMinutes));

        if (! $fresh) {
            try {
                $state = $this->automation->recordBranchHead($this->heads->head($repository, $this->source->branch), $deployed);
            } catch (GitHubApiException|InvalidArgumentException) {
                return;
            }
        }

        if ($state['behind_since'] === null || $state['alerted']) {
            return;
        }

        if (CarbonImmutable::parse($state['behind_since'])->gt(CarbonImmutable::now()->subHours(self::BehindAlertHours))) {
            return;
        }

        $this->alerts->stalled($deployed, sprintf(
            'The head %s of %s has not been released since %s. The deployed commit %s may have left the branch, or no newer commit passed %s.',
            substr($state['head'], 0, 12),
            $this->source->branch,
            $state['behind_since'],
            substr($deployed, 0, 12),
            $this->source->checkName,
        ));
        $this->automation->branchHeadAlerted();
    }

    private function confirmTicks(): void
    {
        try {
            $this->ticks?->check($this->layout->currentReleaseId());
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** A commit that just failed for a transient reason waits before the next attempt. */
    private function backingOff(string $sha): bool
    {
        $last = $this->record($sha);

        return $last instanceof GatewayRelease
            && in_array($last->outcome, [...GatewayRelease::FailedOutcomes, GatewayRelease::Interrupted], true)
            && $last->retryable
            && $last->updated_at !== null
            && $last->updated_at->gt(CarbonImmutable::now()->subMinutes(self::RetryMinutes));
    }

    private function record(string $sha): ?GatewayRelease
    {
        return GatewayRelease::query()
            ->where('trigger', 'auto')
            ->where(static fn ($query) => $query->where('sha', $sha)->orWhere('requested', $sha))
            ->latest('id')
            ->first();
    }

    /**
     * A pause must not stay silent: the branch-head check runs while paused too, so a head that
     * stays unreleased for {@see self::BehindAlertHours} hours alerts once.
     */
    private function watchWhilePaused(): void
    {
        $deployed = $this->deployedCommit();

        if ($deployed === null) {
            return;
        }

        try {
            $this->watchBranchHead($this->source->repository(), $deployed);
        } catch (GatewayReleaseException) {
            // The release source is unreadable; the next tick tries again.
        }
    }

    /** The commit the current release runs, or null outside the release layout. */
    private function deployedCommit(): ?string
    {
        $current = $this->layout->currentReleaseId();

        return $current === null ? null : $this->layout->preparedCommit($current);
    }

    /**
     * The verified manual deploy that put the current release live after the last resume while a
     * newer green commit existed, a deliberate pin of an older commit. A manual deploy of the newest
     * green commit, an automatic release, an adoption, or an earlier resume hands back to automation.
     */
    private function manualRelease(string $current): ?GatewayRelease
    {
        $live = GatewayRelease::query()
            ->where('release_id', $current)
            ->where('outcome', 'verified')
            ->latest('id')
            ->first();

        return $live instanceof GatewayRelease
            && $live->trigger === 'deploy'
            && $live->id > $this->automation->resumedAfter()
            && GatewayReleaseSupersession::superseded($live) ? $live : null;
    }
}
