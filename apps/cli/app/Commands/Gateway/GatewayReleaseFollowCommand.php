<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Commands\GatewayCommand;
use App\Support\Console\InterruptIntent;
use App\Support\Console\ProgressDisplay;
use App\Support\Console\ProgressState;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Sleep;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\GatewayReleases\ShowGatewayReleaseRequest;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleaseResponse;

/**
 * Queues a Gateway deploy or rollback and follows its release record until it finishes. The release
 * runs in its own unit on the Gateway, so the CLI polls the record and shows each step as the
 * Gateway records it. `--json` prints only the final record. Interrupting the CLI stops following;
 * the release keeps running on the Gateway.
 */
abstract class GatewayReleaseFollowCommand extends GatewayCommand
{
    /** Seconds between two reads of the record. */
    public const int PollSeconds = 2;

    /** A release unit may run for one hour; the CLI follows a little longer before it gives up. */
    public const int FollowSeconds = 3900;

    /** Consecutive failed reads, such as while the Gateway reloads, before the CLI gives up. */
    public const int TransientReads = 30;

    private ?CarbonImmutable $followDeadline = null;

    /** @var array<string, array{string, string, string}> */
    private const array STEPS = [
        'queue' => ['Queue release', 'Queuing release', 'Queued release'],
        'prepare' => ['Prepare release', 'Preparing release', 'Prepared release'],
        'migrate' => ['Migrate database', 'Migrating database', 'Migrated database'],
        'switch' => ['Switch release', 'Switching release', 'Switched release'],
        'handoff' => ['Hand off runtime', 'Handing off runtime', 'Handed off runtime'],
        'verify' => ['Verify release', 'Verifying release', 'Verified release'],
        'scheduler' => ['Move scheduler', 'Moving scheduler', 'Moved scheduler'],
        'web' => ['Publish web app', 'Publishing web app', 'Published web app'],
        'smoke' => ['Run smoke tests', 'Running smoke tests', 'Passed smoke tests'],
    ];

    /**
     * @param  list<string>  $steps  the steps after `queue` this kind of release records, in order
     * @param  Closure(): GatewayReleaseResponse  $queue  sends the deploy or rollback request
     */
    protected function followRelease(GatewayConnector $connector, string $title, array $steps, Closure $queue): int
    {
        $this->followDeadline = CarbonImmutable::now()->addSeconds(self::FollowSeconds);

        return $this->consoleMode()->machine
            ? $this->followMachine($connector, $queue)
            : $this->followHuman($connector, $title, $steps, $queue);
    }

    /** @param Closure(): GatewayReleaseResponse $queue */
    private function followMachine(GatewayConnector $connector, Closure $queue): int
    {
        try {
            $record = $queue();

            while (! $record->finished) {
                $record = $this->nextRead($connector, $record);
            }
        } catch (GatewayApiException $exception) {
            return $this->renderApiFailure($exception);
        }

        $this->writeJson($record->toArray());

        return $record->succeeded() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<string>  $steps
     * @param  Closure(): GatewayReleaseResponse  $queue
     */
    private function followHuman(GatewayConnector $connector, string $title, array $steps, Closure $queue): int
    {
        $progress = $this->progressDisplay($title);

        foreach (['queue', ...$steps] as $step) {
            $progress->admit($step, ...self::STEPS[$step]);
        }

        try {
            $record = $progress->during('queue', $queue);
            $progress->complete('queue', ProgressState::Success, 'Record '.$record->id.'.');
            $settled = $this->settle($progress, $steps, $record, []);

            while (! $record->finished) {
                $next = $this->firstOpen($steps, $settled);

                if ($next === null) {
                    while (! $record->finished) {
                        $record = $this->nextRead($connector, $record);
                    }

                    break;
                }

                $record = $progress->during($next, function () use ($connector, $record, $next): GatewayReleaseResponse {
                    $read = $record;

                    do {
                        $read = $this->nextRead($connector, $read);
                    } while (! $read->finished && ! array_key_exists($this->phaseKey($next), $read->phases));

                    return $read;
                });
                $settled = $this->settle($progress, $steps, $record, $settled, $next);
            }
        } catch (GatewayApiException $exception) {
            return $this->renderApiFailure($exception);
        }

        $this->closeTree($progress, $steps, $settled, $record);
        $progress->finish($this->footer($record));
        $this->writeHumanMessage('Release record: '.$record->id);

        if (! $record->succeeded() && $record->message !== null) {
            $this->writeHumanMessage($record->message);
        }

        $this->writeHumanMessage('Request ID: '.$record->requestId);

        return $record->succeeded() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Settles every step the record already reports, in order. `$running` is the step that was
     * animating while the CLI waited; it settles even when the record never reached it.
     *
     * @param  list<string>  $steps
     * @param  array<string, true>  $settled
     * @return array<string, true>
     */
    private function settle(ProgressDisplay $progress, array $steps, GatewayReleaseResponse $record, array $settled, ?string $running = null): array
    {
        foreach ($steps as $step) {
            if (isset($settled[$step])) {
                continue;
            }

            $state = $this->state($step, $record);

            if ($state === null) {
                if ($step === $running) {
                    $progress->complete($step, $record->succeeded() ? ProgressState::Skipped : ProgressState::Failure, $this->failure($record));
                    $settled[$step] = true;
                }

                return $settled;
            }

            [$progressState, $message] = $state;

            if ($step !== $running) {
                if ($progressState === ProgressState::Skipped) {
                    $progress->complete($step, ProgressState::Skipped, $message);
                    $settled[$step] = true;

                    continue;
                }

                $progress->during($step, static fn (): null => null);
            }

            $progress->complete($step, $progressState, $message);
            $settled[$step] = true;

            if ($progressState === ProgressState::Failure) {
                return $settled;
            }
        }

        return $settled;
    }

    /**
     * @param  list<string>  $steps
     * @param  array<string, true>  $settled
     */
    private function closeTree(ProgressDisplay $progress, array $steps, array $settled, GatewayReleaseResponse $record): void
    {
        $failed = false;

        foreach ($steps as $step) {
            if (isset($settled[$step])) {
                $failed = $failed || ($this->state($step, $record)[0] ?? null) === ProgressState::Failure;

                continue;
            }

            if ($failed) {
                continue;
            }

            if ($record->succeeded()) {
                $progress->complete($step, ProgressState::Skipped, 'Not reached.');

                continue;
            }

            // The record failed before this step started: show the failure where the release stopped.
            $progress->during($step, static fn (): null => null);
            $progress->complete($step, ProgressState::Failure, $this->failure($record));
            $failed = true;
        }
    }

    /**
     * The progress state and message of one step, or null when the record has not reached it.
     *
     * @return array{ProgressState, string}|null
     */
    private function state(string $step, GatewayReleaseResponse $record): ?array
    {
        if ($step === 'migrate') {
            $snapshot = $this->outcome($record->phases['snapshot'] ?? null);
            $migrate = $this->outcome($record->phases['migrate'] ?? null);

            return match (true) {
                $snapshot === 'failed' => [ProgressState::Failure, $this->failure($record)],
                $migrate === null => null,
                $migrate === 'skipped' => [ProgressState::Skipped, 'No migrations pending.'],
                $migrate === 'failed' => [ProgressState::Failure, $this->failure($record)],
                default => [ProgressState::Success, ''],
            };
        }

        if (! array_key_exists($step, $record->phases)) {
            return null;
        }

        return match ($this->outcome($record->phases[$step])) {
            'failed' => [ProgressState::Failure, $this->failure($record)],
            'skipped' => [ProgressState::Skipped, ''],
            'reused' => [ProgressState::Success, 'Reused the prepared release.'],
            default => [ProgressState::Success, ''],
        };
    }

    private function phaseKey(string $step): string
    {
        return $step === 'migrate' ? 'migrate' : $step;
    }

    private function outcome(mixed $phase): ?string
    {
        if (! is_array($phase)) {
            return null;
        }

        return is_string($phase['outcome'] ?? null) ? $phase['outcome'] : 'done';
    }

    /**
     * @param  list<string>  $steps
     * @param  array<string, true>  $settled
     */
    private function firstOpen(array $steps, array $settled): ?string
    {
        foreach ($steps as $step) {
            if (! isset($settled[$step])) {
                return $step;
            }
        }

        return null;
    }

    private function failure(GatewayReleaseResponse $record): string
    {
        $what = match ($record->outcome) {
            'switched_back' => 'Switched back to '.($record->previous ?? 'the previous release').'.',
            'paused' => 'Migrations ran, so automatic releases are paused.',
            default => '',
        };

        $code = $record->errorCode ?? '';

        return implode('. ', array_filter([$code, rtrim($what, '.')])).($what !== '' ? '.' : '');
    }

    private function footer(GatewayReleaseResponse $record): string
    {
        $release = $record->release ?? $record->requested ?? (string) $record->id;

        return match ($record->outcome) {
            'verified' => $record->trigger === 'rollback' ? "Rolled back to release {$release}." : "Release {$release} is live.",
            'switched_back' => "Release {$release} failed and was switched back.",
            'paused' => "Release {$release} failed after migrations. Automatic releases are paused.",
            'interrupted' => "Release {$release} was interrupted.",
            default => "Release {$release} failed.",
        };
    }

    /** Waits, then reads the record again. A Gateway that reloads or briefly drops connections is read again. */
    private function nextRead(GatewayConnector $connector, GatewayReleaseResponse $record): GatewayReleaseResponse
    {
        $deadline = $this->followDeadline ?? CarbonImmutable::now()->addSeconds(self::FollowSeconds);
        $failures = 0;

        while (true) {
            if (CarbonImmutable::now()->gte($deadline)) {
                throw new GatewayApiException(
                    message: 'The CLI stopped following release record '.$record->id.' after '.intdiv(self::FollowSeconds, 60).' minutes. The release continues on the Gateway; run gateway:release:show '.$record->id.'.',
                    errorCode: 'gateway.release_follow_failed',
                    requestId: $record->requestId,
                );
            }

            InterruptIntent::throwIfPending();
            Sleep::for(self::PollSeconds)->seconds();
            InterruptIntent::throwIfPending();

            try {
                return $this->sendOrThrow($connector, new ShowGatewayReleaseRequest((string) $record->id), GatewayReleaseResponse::class);
            } catch (GatewayApiException $exception) {
                $transient = in_array($exception->errorCode(), [null, 'gateway.unreachable', 'gateway.request_failed'], true);

                if (! $transient || ++$failures >= self::TransientReads) {
                    throw new GatewayApiException(
                        message: 'The CLI stopped following release record '.$record->id.'. The release continues on the Gateway; run gateway:release:show '.$record->id.'.',
                        errorCode: 'gateway.release_follow_failed',
                        previous: $exception,
                        requestId: $exception->requestId(),
                    );
                }
            }
        }
    }
}
