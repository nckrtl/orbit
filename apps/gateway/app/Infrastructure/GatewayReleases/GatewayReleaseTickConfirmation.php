<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskTickClock;
use App\Models\GatewayRelease;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Throwable;

/**
 * Confirms after a release that its scheduler runs `tasks:tick`
 * ([Post-release tick confirmation](/reference/gateway-recovery#post-release-tick-confirmation)).
 *
 * A new scheduler first runs at the next full minute, so smoke does not wait for a tick. A verified release starts
 * with the `tick` phase `pending` instead. A tick confirms it when it started at or after the handoff and the code
 * that ran it has the release's commit as its version. Every tick of the release runner checks the pending
 * confirmations. One that sees no such tick before its deadline is `missed` and raises one
 * `release_scheduler_silent` alert. It never switches back or pauses, so a forward fix still ships automatically.
 *
 * @phpstan-type PendingPhase array{outcome: string, since: string, deadline: string}
 * @phpstan-type TickPhase array<string, mixed>
 */
final readonly class GatewayReleaseTickConfirmation
{
    /** How long a verified release has to show a tick from its own scheduler. */
    public const int WindowSeconds = 180;

    public function __construct(
        private TaskTickClock $clock,
        private TaskExtensionState $extension,
        private GatewayReleaseAlerts $alerts,
        private int $windowSeconds = self::WindowSeconds,
    ) {}

    /**
     * The `tick` phase of a release that was just verified. A tick that already ran during smoke confirms it at once.
     *
     * @return TickPhase
     */
    public function start(string $sha, DateTimeImmutable $handoffAt): array
    {
        $now = CarbonImmutable::now('UTC');
        $phase = [
            'outcome' => 'pending',
            'since' => CarbonImmutable::instance($handoffAt)->utc()->toIso8601ZuluString(),
            'deadline' => $now->addSeconds($this->windowSeconds)->toIso8601ZuluString(),
        ];

        try {
            return $this->decide($phase, $sha, $now) ?? $phase;
        } catch (Throwable $exception) {
            // The release is verified either way; the runner decides on its next tick.
            report($exception);

            return $phase;
        }
    }

    /**
     * Ends each pending confirmation that can be decided now. A record whose release is no longer current is
     * `superseded`: the release that replaced it has a confirmation of its own.
     */
    public function check(?string $currentReleaseId): void
    {
        $pending = GatewayRelease::query()
            ->where('outcome', 'verified')
            ->where('phases->tick->outcome', 'pending')
            ->orderBy('id')
            ->get();

        foreach ($pending as $record) {
            try {
                $this->checkRecord($record, $currentReleaseId);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function checkRecord(GatewayRelease $record, ?string $currentReleaseId): void
    {
        $sha = $record->commit();
        $tick = $record->phases['tick'] ?? null;

        if ($sha === null || ! is_array($tick) || ! is_string($tick['since'] ?? null) || ! is_string($tick['deadline'] ?? null)) {
            return;
        }

        $phase = ['outcome' => 'pending', 'since' => $tick['since'], 'deadline' => $tick['deadline']];
        $now = CarbonImmutable::now('UTC');
        $decided = $record->release_id === $currentReleaseId
            ? $this->decide($phase, $sha, $now)
            : [...$phase, 'outcome' => 'superseded', 'decided_at' => $now->toIso8601ZuluString(), 'current' => $currentReleaseId];

        if ($decided === null || ! $this->store($record, $decided)) {
            return;
        }

        if ($decided['outcome'] === 'missed') {
            $this->alert($record, $phase, $decided);
        }
    }

    /**
     * The decided phase, or null while it stays pending.
     *
     * @param  PendingPhase  $phase
     * @return TickPhase|null
     */
    private function decide(array $phase, string $sha, CarbonImmutable $now): ?array
    {
        if (! $this->extension->enabled()) {
            return [...$phase, 'outcome' => 'skipped', 'reason' => 'tasks_disabled', 'decided_at' => $now->toIso8601ZuluString()];
        }

        $tick = $this->clock->lastStarted();
        $since = CarbonImmutable::parse($phase['since']);
        $evidence = ['last_tick_at' => $tick['started_at'] ?? null, 'last_tick_version' => $tick['version'] ?? null];

        if ($tick !== null && $tick['version'] === $sha && CarbonImmutable::parse($tick['started_at'])->gte($since)) {
            return [...$phase, 'outcome' => 'confirmed', ...$evidence, 'decided_at' => $now->toIso8601ZuluString()];
        }

        if ($now->gte(CarbonImmutable::parse($phase['deadline']))) {
            return [...$phase, 'outcome' => 'missed', ...$evidence, 'decided_at' => $now->toIso8601ZuluString()];
        }

        return null;
    }

    /**
     * Writes the decided phase only when the record is still pending, so two runners cannot both decide it, and a
     * missed confirmation alerts once.
     *
     * @param  TickPhase  $decided
     */
    private function store(GatewayRelease $record, array $decided): bool
    {
        $phases = $record->phases;
        $phases['tick'] = $decided;
        $updated = GatewayRelease::query()
            ->whereKey($record->id)
            ->where('phases->tick->outcome', 'pending')
            ->update(['phases' => json_encode($phases, JSON_THROW_ON_ERROR)]);

        if ($updated !== 1) {
            return false;
        }

        $record->refresh();

        return true;
    }

    /**
     * @param  PendingPhase  $pending
     * @param  TickPhase  $missed
     */
    private function alert(GatewayRelease $record, array $pending, array $missed): void
    {
        $lastTickAt = $missed['last_tick_at'] ?? null;
        $lastTickVersion = $missed['last_tick_version'] ?? null;
        $seen = is_string($lastTickAt)
            ? sprintf('The last tick started %s and ran version %s.', $lastTickAt, is_string($lastTickVersion) ? $lastTickVersion : 'unknown')
            : 'No tick is remembered.';
        $stored = $this->alerts->schedulerSilent($record, sprintf(
            'Release %s is live, but its scheduler ran no tasks:tick between %s and %s. %s Check the scheduler unit.',
            $record->release_id ?? (string) $record->commit(),
            $pending['since'],
            $pending['deadline'],
            $seen,
        ));

        $phases = $record->phases;
        $phases['tick'] = [...$missed, 'alert' => $stored];
        $record->forceFill(['phases' => $phases])->save();
    }
}
